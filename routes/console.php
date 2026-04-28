<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Watchdog: раз в минуту пытаемся поднять WhatsApp-сессии, которые пользователь
// не выключал явно (desired_state=active), но подключение фактически мёртвое.
// withoutOverlapping — чтобы параллельные запуски не спорили за один и тот же
// /verify → /initialize цикл.
Schedule::command('whatsapp:heal')
    ->everyMinute()
    ->withoutOverlapping()
    ->runInBackground();
