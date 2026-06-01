<?php

declare(strict_types=1);

/**
 * Лимиты WhatsApp-подключений по ёмкости сервера (не тарифный потолок).
 *
 * Формула: (WHATSAPP_SERVER_RAM_MB − WHATSAPP_RESERVED_RAM_MB) / WHATSAPP_SESSION_RAM_MB
 * На текущем хосте (16 GB RAM, ~7 GB резерв под ОС/Laravel/БД/другие PM2): ≈ 20 сессий.
 */
$serverRamMb = max(1024, (int) env('WHATSAPP_SERVER_RAM_MB', 16384));
$reservedRamMb = max(512, (int) env('WHATSAPP_RESERVED_RAM_MB', 7168));
$sessionRamMb = max(256, (int) env('WHATSAPP_SESSION_RAM_MB', 450));

$computedGlobal = max(1, (int) floor(($serverRamMb - $reservedRamMb) / $sessionRamMb));
$maxGlobal = max(1, (int) env('WHATSAPP_MAX_SESSIONS_GLOBAL', $computedGlobal));

return [

    'server_ram_mb' => $serverRamMb,
    'reserved_ram_mb' => $reservedRamMb,
    'session_ram_mb' => $sessionRamMb,

    /** Сколько Chromium-сессий выдерживает whatsapp-service на этом хосте (все тенанты суммарно). */
    'max_sessions_global' => $maxGlobal,

    /** Сколько номеров может подключить одна компания (по умолчанию = глобальный потолок). */
    'max_sessions_per_tenant' => max(1, (int) env('WHATSAPP_MAX_SESSIONS_PER_TENANT', $maxGlobal)),

];
