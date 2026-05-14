<?php

use App\Jobs\AnalyzeCompanyToneProfileJob;
use App\Jobs\AnalyzeEmployeeToneProfileJob;
use App\Models\Company;
use App\Models\EmployeeToneProfile;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('ai:tone-profiles-refresh', function (): int {
    Company::query()
        ->where(function ($query): void {
            $query->whereDoesntHave('toneProfile')
                ->orWhereHas('toneProfile', function ($toneQuery): void {
                    $toneQuery->whereNull('analyzed_at')
                        ->orWhere('analyzed_at', '<', now()->subDays(7));
                });
        })
        ->orderBy('id')
        ->limit(100)
        ->pluck('id')
        ->each(fn ($companyId) => AnalyzeCompanyToneProfileJob::dispatch((int) $companyId));

    EmployeeToneProfile::query()
        ->where(function ($query): void {
            $query->whereNull('analyzed_at')
                ->orWhere('analyzed_at', '<', now()->subDays(7));
        })
        ->orderBy('id')
        ->limit(200)
        ->get(['user_id', 'company_id'])
        ->each(fn (EmployeeToneProfile $profile) => AnalyzeEmployeeToneProfileJob::dispatch($profile->user_id, $profile->company_id));

    return 0;
})->purpose('Refresh stale AI tone profiles');

// Watchdog: раз в минуту пытаемся поднять WhatsApp-сессии, которые пользователь
// не выключал явно (desired_state=active), но подключение фактически мёртвое.
// withoutOverlapping — чтобы параллельные запуски не спорили за один и тот же
// /verify → /initialize цикл.
Schedule::command('whatsapp:heal')
    ->everyMinute()
    ->withoutOverlapping()
    ->runInBackground();

Schedule::command('scheduled-messages:send')
    ->everyMinute()
    ->withoutOverlapping()
    ->runInBackground();

// После полуночи (по timezone приложения): в архив — диалоги, где последнее
// сообщение — ответ сотрудника (исходящее с sent_by_user_id). Закреплённые не трогаем.
Schedule::command('chats:auto-archive-answered')
    ->dailyAt('00:05')
    ->withoutOverlapping()
    ->runInBackground();

Schedule::command('ai:tone-profiles-refresh')
    ->dailyAt('03:10')
    ->withoutOverlapping()
    ->runInBackground();
