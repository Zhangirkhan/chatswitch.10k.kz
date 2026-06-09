<?php

declare(strict_types=1);

namespace App\Services\SuperAdmin;

use App\Models\Company;
use App\Models\User;
use App\Tenancy\TenantContext;
use Spatie\Permission\PermissionRegistrar;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\URL;
use Symfony\Component\HttpKernel\Exception\HttpException;

final class TenantImpersonationService
{
    public const SESSION_KEY = 'super_admin_impersonation';

    private const TOKEN_TTL_SECONDS = 120;

    public function __construct(
        private readonly SuperAdminAuditLogger $audit,
    ) {}

    public function resolveTargetUser(Company $company): ?User
    {
        if (! $company->is_active) {
            return null;
        }

        if ($company->owner_user_id !== null) {
            $owner = User::query()
                ->withoutGlobalScope('tenant')
                ->where('company_id', $company->id)
                ->where('id', $company->owner_user_id)
                ->where('is_active', true)
                ->first();

            if ($owner !== null && $this->withCompanyTeam($company, static fn () => $owner->hasRole('administrator'))) {
                return $owner;
            }
        }

        return $this->withCompanyTeam($company, static function () use ($company): ?User {
            return User::query()
                ->withoutGlobalScope('tenant')
                ->where('company_id', $company->id)
                ->where('is_active', true)
                ->whereHas('roles', fn ($q) => $q->where('name', 'administrator'))
                ->orderBy('id')
                ->first();
        });
    }

    public function canImpersonate(Company $company): bool
    {
        return $this->impersonationBlockedReason($company) === null;
    }

    public function impersonationBlockedReason(Company $company): ?string
    {
        if (! $company->is_active) {
            return 'Тенант отключён.';
        }

        if ($this->resolveTargetUser($company) === null) {
            return 'Нет активного пользователя с ролью administrator.';
        }

        return null;
    }

    public function issueRedirectUrl(Company $company, User $superAdmin): string
    {
        $targetUser = $this->resolveTargetUser($company);
        $reason = $this->impersonationBlockedReason($company);

        if ($targetUser === null || $reason !== null) {
            throw new HttpException(422, $reason ?? 'Невозможно войти в тенант.');
        }

        $token = Crypt::encryptString(json_encode([
            'company_id' => $company->id,
            'target_user_id' => $targetUser->id,
            'super_user_id' => $superAdmin->id,
            'return_url' => route('super.companies.show', $company, absolute: true),
            'expires_at' => now()->addSeconds(self::TOKEN_TTL_SECONDS)->getTimestamp(),
        ], JSON_THROW_ON_ERROR));

        $tenantRoot = rtrim($company->tenantUrl('/'), '/');
        $previousRoot = config('app.url');
        URL::forceRootUrl($tenantRoot);

        try {
            return URL::temporarySignedRoute(
                'tenant.impersonate.accept',
                now()->addSeconds(self::TOKEN_TTL_SECONDS),
                [
                    'tenant' => $company->slug,
                    'token' => $token,
                ],
                absolute: true,
            );
        } finally {
            if (is_string($previousRoot) && $previousRoot !== '') {
                URL::forceRootUrl($previousRoot);
            }
        }
    }

    public function accept(Request $request, string $token): Response
    {
        $payload = $this->decodeToken($token);

        if ($payload === null) {
            abort(403, 'Ссылка для входа недействительна или уже использована.');
        }

        $expiresAt = (int) ($payload['expires_at'] ?? 0);
        if ($expiresAt < now()->getTimestamp()) {
            abort(403, 'Ссылка для входа просрочена.');
        }

        $consumeKey = 'impersonate-used:'.hash('sha256', $token);
        if (! Cache::add($consumeKey, true, self::TOKEN_TTL_SECONDS)) {
            abort(403, 'Ссылка для входа недействительна или уже использована.');
        }

        $context = app(TenantContext::class);
        $company = $context->company();

        if ($company === null || (int) $payload['company_id'] !== $company->id) {
            abort(403, 'Компания не совпадает с поддоменом.');
        }

        $targetUser = User::query()
            ->withoutGlobalScope('tenant')
            ->where('company_id', $company->id)
            ->where('id', (int) $payload['target_user_id'])
            ->where('is_active', true)
            ->first();

        if ($targetUser === null || ! $this->withCompanyTeam($company, static fn () => $targetUser->hasRole('administrator'))) {
            abort(403, 'Пользователь для входа недоступен.');
        }

        $superUser = User::query()
            ->withoutGlobalScope('tenant')
            ->where('id', (int) $payload['super_user_id'])
            ->where('is_super_admin', true)
            ->first();

        if ($superUser === null) {
            abort(403, 'Сессия супер-админа недействительна.');
        }

        Auth::login($targetUser);
        $request->session()->regenerate();

        $request->session()->put(self::SESSION_KEY, [
            'super_user_id' => $superUser->id,
            'super_user_name' => $superUser->name,
            'company_id' => $company->id,
            'company_name' => $company->name,
            'return_url' => (string) ($payload['return_url'] ?? route('super.dashboard', absolute: true)),
        ]);

        Log::info('super_admin.impersonation.start', [
            'super_user_id' => $superUser->id,
            'company_id' => $company->id,
            'target_user_id' => $targetUser->id,
        ]);

        $this->audit->log($company, $superUser, 'impersonation.start', $targetUser, [
            'target_user_id' => $targetUser->id,
            'target_user_email' => $targetUser->email,
            'super_user_id' => $superUser->id,
        ]);

        return redirect()->route('settings.connections', [
            'tenant' => $company->slug,
        ]);
    }

    public function leave(Request $request): Response
    {
        $meta = $request->session()->get(self::SESSION_KEY);

        if (! is_array($meta)) {
            return $this->externalRedirect($request, $this->defaultReturnUrl());
        }

        $returnUrl = (string) ($meta['return_url'] ?? $this->defaultReturnUrl());
        $superUserId = isset($meta['super_user_id']) ? (int) $meta['super_user_id'] : null;
        $companyId = isset($meta['company_id']) ? (int) $meta['company_id'] : null;
        $companyName = (string) ($meta['company_name'] ?? '');

        $superUser = $superUserId !== null
            ? User::query()
                ->withoutGlobalScope('tenant')
                ->where('id', $superUserId)
                ->where('is_super_admin', true)
                ->first()
            : null;

        $company = $companyId !== null
            ? Company::query()->find($companyId)
            : null;

        Auth::logout();
        $request->session()->forget(self::SESSION_KEY);

        if ($this->usesSharedSessionDomain()) {
            $request->session()->regenerate();

            if ($superUser !== null) {
                Auth::login($superUser);
            }
        } else {
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }

        if ($superUser !== null) {
            $this->audit->log($company, $superUser, 'impersonation.end', null, [
                'company_id' => $companyId,
                'company_name' => $companyName !== '' ? $companyName : $company?->name,
                'return_url' => $returnUrl,
            ]);
        }

        Log::info('super_admin.impersonation.end', [
            'super_user_id' => $superUserId,
            'company_id' => $companyId,
            'return_url' => $returnUrl,
        ]);

        return $this->externalRedirect($request, $returnUrl);
    }

    private function externalRedirect(Request $request, string $url): Response
    {
        if ($request->header('X-Inertia')) {
            return Inertia::location($url);
        }

        return redirect()->away($url);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function decodeToken(string $token): ?array
    {
        try {
            $decoded = json_decode(Crypt::decryptString($token), true, 512, JSON_THROW_ON_ERROR);
        } catch (DecryptException|\JsonException) {
            return null;
        }

        return is_array($decoded) ? $decoded : null;
    }

    private function defaultReturnUrl(): string
    {
        $root = (string) config('tenancy.root_domain', 'accel.kz');
        $admin = (string) config('tenancy.admin_subdomain', 'app');
        $scheme = config('app.env') === 'production' ? 'https' : 'http';

        return $scheme.'://'.$admin.'.'.$root.'/dashboard';
    }

    private function usesSharedSessionDomain(): bool
    {
        $domain = config('session.domain');

        return is_string($domain) && $domain !== '';
    }
}
