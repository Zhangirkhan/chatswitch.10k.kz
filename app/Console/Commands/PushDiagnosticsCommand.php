<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Company;
use App\Models\User;
use App\Models\UserDevice;
use App\Services\Push\FcmAccessTokenProvider;
use Illuminate\Console\Command;

final class PushDiagnosticsCommand extends Command
{
    protected $signature = 'push:diagnostics {--company= : Показать сотрудников компании без зарегистрированного устройства}';

    protected $description = 'Проверка готовности FCM push (токены, credentials, queue)';

    public function handle(FcmAccessTokenProvider $tokenProvider): int
    {
        $enabled = (bool) config('services.firebase.enabled', false);
        $credentials = (string) config('services.firebase.credentials', '');

        $this->line('FIREBASE_FCM_ENABLED: '.($enabled ? 'true' : 'false'));
        $this->line('FIREBASE_CREDENTIALS: '.($credentials !== '' ? $credentials : '(not set)'));
        $this->line('Credentials file exists: '.(is_file($credentials) ? 'yes' : 'no'));

        if (is_file($credentials)) {
            $json = json_decode((string) file_get_contents($credentials), true);
            $projectId = is_array($json) ? (string) ($json['project_id'] ?? '') : '';
            $this->line('Firebase project_id: '.($projectId !== '' ? $projectId : '(missing)'));
            if ($projectId !== '' && $projectId !== 'accel-d52ce') {
                $this->warn('Expected project_id accel-d52ce — check service account matches google-services.json');
            }
        }

        if ($enabled && $tokenProvider->isConfigured()) {
            $token = $tokenProvider->getAccessToken();
            $this->line('FCM OAuth token: '.($token !== null ? 'ok' : 'FAILED'));
        } else {
            $this->warn('FCM sender disabled or credentials missing — push jobs will no-op.');
        }

        $deviceCount = UserDevice::query()->withoutGlobalScope('tenant')->count();
        $this->line('user_devices rows: '.$deviceCount);

        UserDevice::query()
            ->withoutGlobalScope('tenant')
            ->latest('updated_at')
            ->limit(5)
            ->get(['id', 'user_id', 'company_id', 'platform', 'fcm_token', 'updated_at'])
            ->each(function (UserDevice $device): void {
                $this->line(sprintf(
                    '  #%d user=%d company=%d platform=%s token=%s… updated=%s',
                    $device->id,
                    $device->user_id,
                    $device->company_id,
                    $device->platform,
                    substr($device->fcm_token, 0, 16),
                    $device->updated_at?->toIso8601String() ?? '—',
                ));
            });

        $companyOption = $this->option('company');
        if ($companyOption !== null && $companyOption !== '') {
            $this->reportUsersWithoutDevices((int) $companyOption);
        }

        return self::SUCCESS;
    }

    private function reportUsersWithoutDevices(int $companyId): void
    {
        $company = Company::query()->withoutGlobalScope('tenant')->find($companyId);
        if ($company === null) {
            $this->warn("Company #{$companyId} not found.");

            return;
        }

        $users = User::query()
            ->withoutGlobalScope('tenant')
            ->where('company_id', $companyId)
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name']);

        $withDevice = UserDevice::query()
            ->withoutGlobalScope('tenant')
            ->whereIn('user_id', $users->pluck('id'))
            ->pluck('user_id')
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->all();

        $withDeviceLookup = array_fill_keys($withDevice, true);
        $missing = $users->filter(fn (User $user): bool => ! isset($withDeviceLookup[(int) $user->id]));

        $this->newLine();
        $this->line("Company: {$company->name} (#{$companyId})");
        $this->line('Active users with push token: '.count($withDevice).' / '.$users->count());

        if ($missing->isEmpty()) {
            $this->info('All active users have at least one registered device.');

            return;
        }

        $this->warn('Users without mobile device (push will NOT reach them):');
        foreach ($missing as $user) {
            $this->line("  - #{$user->id} {$user->name}");
        }
        $this->line('Ask them to sign in to the mobile app so POST /devices registers an FCM token.');
    }
}
