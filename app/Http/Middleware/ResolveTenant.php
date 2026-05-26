<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\Company;
use App\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;

final class ResolveTenant
{
    public function __construct(
        private readonly TenantContext $tenantContext,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $rootDomain = (string) config('tenancy.root_domain', 'accel.kz');
        $host = strtolower($request->getHost());

        $slug = $request->route('tenant');
        if (is_string($slug) && $slug !== '') {
            $slug = strtolower($slug);
        } else {
            $slug = TenantContext::parseSlugFromHost($host, $rootDomain);
        }

        if ($slug === null || $slug === '') {
            if (app()->environment('testing', 'local')) {
                $slug = (string) config('tenancy.fallback_slug', 'demo');
            } else {
                return $this->tenantNotFoundResponse($request, $host);
            }
        }

        if (in_array($slug, config('tenancy.reserved_slugs', []), true)) {
            return $this->tenantNotFoundResponse($request, $host);
        }

        // Намеренно не фильтруем по is_active: отключённый тенант существует, но
        // должен показать страницу «сайт отключён» (это делает EnsureActiveCompany),
        // а не наш «такого рабочего пространства не существует».
        $company = Company::query()
            ->withoutGlobalScope('tenant')
            ->where('slug', $slug)
            ->first();

        if ($company === null) {
            return $this->tenantNotFoundResponse($request, $host);
        }

        $this->tenantContext->setCompany($company);
        $request->attributes->set('tenant_company', $company);
        $request->attributes->set('tenant_slug', $slug);

        // Подставляем slug по умолчанию для генератора URL — чтобы route('login'),
        // route('chats.index') и т.д. работали без явной передачи параметра {tenant}.
        URL::defaults(['tenant' => $slug]);

        // {tenant} из домена не должен попадать в аргументы контроллера: Laravel
        // передаёт route parameters позиционно, и slug «esl» оказывается первым
        // аргументом вместо WhatsappSession/Chat и ломает implicit binding.
        $request->route()?->forgetParameter('tenant');

        return $next($request);
    }

    /**
     * Для HTML-запросов на неизвестный поддомен отправляем пользователя
     * на красивую 404-страницу лендинга, для API/JSON — обычный 404.
     */
    private function tenantNotFoundResponse(Request $request, string $host): Response
    {
        if ($request->expectsJson()) {
            return new JsonResponse([
                'message' => 'Tenant not found.',
                'host' => $host,
            ], 404);
        }

        $rootDomain = (string) config('tenancy.root_domain', 'accel.kz');
        // На лендинг (не /404), чтобы не светить отдельную страницу перебора поддоменов.
        $target = 'https://'.$rootDomain.'/';

        return new RedirectResponse($target, 302);
    }
}
