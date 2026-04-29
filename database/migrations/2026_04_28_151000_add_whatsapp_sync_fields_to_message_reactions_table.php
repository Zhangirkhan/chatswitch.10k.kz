<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('message_reactions', function (Blueprint $table): void {
            $table->boolean('pending_whatsapp_sync')->default(false)->after('emoji');
            $table->timestamp('whatsapp_synced_at')->nullable()->after('pending_whatsapp_sync');
            $table->string('whatsapp_sync_error', 500)->nullable()->after('whatsapp_synced_at');
        });
    }

    public function down(): void
    {
        Schema::table('message_reactions', function (Blueprint $table): void {
            $table->dropColumn(['pending_whatsapp_sync', 'whatsapp_synced_at', 'whatsapp_sync_error']);
        });
    }
};

