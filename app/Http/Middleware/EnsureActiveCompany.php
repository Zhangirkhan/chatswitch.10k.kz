<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

final class EnsureActiveCompany
{
    /**
     * Подписочные статусы, при которых тенант блокируется.
     */
    private const BLOCKED_STATUSES = ['suspended', 'canceled'];

    public function __construct(
        private readonly TenantContext $tenantContext,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (! $this->tenantContext->isResolved()) {
            return $next($request);
        }

        $company = $this->tenantContext->company();
        if ($company === null) {
            return $next($request);
        }

        $reason = null;

        if ($company->is_active === false) {
            $reason = 'disabled';
        } elseif (in_array((string) $company->subscription_status, self::BLOCKED_STATUSES, true)) {
            $reason = (string) $company->subscription_status;
        }

        if ($reason === null) {
            return $next($request);
        }

        $titles = [
            'disabled' => 'Сайт отключён',
            'suspended' => 'Подписка приостановлена',
            'canceled' => 'Подписка отменена',
        ];
        $descriptions = [
            'disabled' => 'Доступ к рабочему пространству временно закрыт администратором. Обратитесь к владельцу аккаунта или в поддержку Accel.',
            'suspended' => 'Подписка вашей компании приостановлена. Возобновите её, чтобы вернуть доступ к чатам и WhatsApp.',
            'canceled' => 'Подписка вашей компании отменена. Чтобы продолжить работу, обратитесь к менеджеру Accel.',
        ];

        $payload = [
            'companyName' => $company->name,
            'companySlug' => $company->slug,
            'reason' => $reason,
            'status' => $company->subscription_status,
            'isActive' => (bool) $company->is_active,
            'title' => $titles[$reason] ?? 'Доступ закрыт',
            'description' => $descriptions[$reason] ?? 'Рабочее пространство недоступно.',
            'supportEmail' => 'hello@accel.kz',
        ];

        if ($request->expectsJson()) {
            return new JsonResponse([
                'message' => $payload['title'],
                'reason' => $reason,
            ], 403);
        }

        return Inertia::render('Tenant/Suspended', $payload)
            ->toResponse($request)
            ->setStatusCode(Response::HTTP_FORBIDDEN);
    }
}
