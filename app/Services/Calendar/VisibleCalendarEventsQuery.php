<?php

declare(strict_types=1);

namespace App\Services\Calendar;

use App\Models\CalendarEvent;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Builder;

/**
 * Записи календаря, доступные пользователю для просмотра (те же правила, что в HTTP-контроллере календаря).
 *
 * @return Builder<CalendarEvent>
 */
final class VisibleCalendarEventsQuery
{
    public static function forUser(User $user): Builder
    {
        $q = self::scopeToTenant(CalendarEvent::query());

        if ($user->hasRole('administrator')) {
            return $q;
        }

        if ($user->hasRole('manager')) {
            $deptIds = $user->departmentIds();
            if ($deptIds === []) {
                return $q->whereRaw('0 = 1');
            }
            $peerIds = User::query()
                ->whereHas('departments', static fn (Builder $d) => $d->whereIn('departments.id', $deptIds))
                ->pluck('id')
                ->map(fn ($v) => (int) $v)
                ->all();

            return $q->where(function (Builder $w) use ($user, $peerIds): void {
                $w->where('user_id', $user->id)
                    ->orWhere('assignee_user_id', $user->id)
                    ->orWhereIn('user_id', $peerIds)
                    ->orWhereIn('assignee_user_id', $peerIds);
            });
        }

        return $q->where(function (Builder $w) use ($user): void {
            $w->where('user_id', $user->id)
                ->orWhere('assignee_user_id', $user->id);
        });
    }

    /**
     * @param  Builder<CalendarEvent>  $query
     * @return Builder<CalendarEvent>
     */
    public static function scopeToTenant(Builder $query): Builder
    {
        $context = app(TenantContext::class);
        if (! $context->shouldApplyTenantScope()) {
            return $query;
        }

        $companyId = $context->companyId();

        return $query->where(function (Builder $w) use ($companyId): void {
            $w->whereHas('user', static fn (Builder $u) => $u->withoutGlobalScope('tenant')->where('company_id', $companyId))
                ->orWhereHas('assignee', static fn (Builder $u) => $u->withoutGlobalScope('tenant')->where('company_id', $companyId))
                ->orWhereHas('chat', static fn (Builder $c) => $c->withoutGlobalScope('tenant')->where('company_id', $companyId))
                ->orWhereHas('contact', static fn (Builder $c) => $c->withoutGlobalScope('tenant')->where('company_id', $companyId));
        });
    }
}
