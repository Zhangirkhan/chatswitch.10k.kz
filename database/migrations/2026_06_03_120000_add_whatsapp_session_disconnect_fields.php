<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('whatsapp_sessions', function (Blueprint $table): void {
            $table->string('last_disconnect_reason')->nullable()->after('disconnected_at');
            $table->text('last_auth_failure_message')->nullable()->after('last_disconnect_reason');
            $table->timestamp('qr_required_at')->nullable()->after('last_auth_failure_message');
        });
    }

    public function down(): void
    {
        Schema::table('whatsapp_sessions', function (Blueprint $table): void {
            $table->dropColumn([
                'last_disconnect_reason',
                'last_auth_failure_message',
                'qr_required_at',
            ]);
        });
    }
};
