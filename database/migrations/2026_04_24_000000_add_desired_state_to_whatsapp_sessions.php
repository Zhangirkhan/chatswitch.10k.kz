<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Вводит явное «намерение» пользователя по сессии:
     *   - active     — сессия должна оставаться подключённой; watchdog будет
     *                  автоматически поднимать её, если Node-клиент умрёт.
     *   - logged_out — пользователь сам нажал «Выйти»; трогать нельзя
     *                  до явного повторного «Подключить».
     *
     * Отделено от рабочего `status`, который отражает текущее *фактическое*
     * состояние (disconnected/connecting/qr_pending/connected) и может
     * прыгать из-за сетевых/puppeteer-проблем.
     */
    public function up(): void
    {
        Schema::table('whatsapp_sessions', function (Blueprint $table): void {
            $table->enum('desired_state', ['active', 'logged_out'])
                ->default('active')
                ->after('status');
        });

        // Существующие сессии: если в прошлом пользователь сам разлогинился —
        // у них status=disconnected и disconnected_at не null ⇒ ставим logged_out,
        // чтобы watchdog не поднимал то, что пользователь осознанно выключил.
        // Остальные — считаем «активными».
        \Illuminate\Support\Facades\DB::table('whatsapp_sessions')
            ->where('status', 'disconnected')
            ->whereNotNull('disconnected_at')
            ->update(['desired_state' => 'logged_out']);
    }

    public function down(): void
    {
        Schema::table('whatsapp_sessions', function (Blueprint $table): void {
            $table->dropColumn('desired_state');
        });
    }
};
