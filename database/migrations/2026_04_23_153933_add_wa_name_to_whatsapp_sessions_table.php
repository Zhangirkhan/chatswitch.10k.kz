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
            $table->string('wa_name')->nullable()->after('display_name')
                ->comment('WhatsApp account pushname (set automatically on connect)');
        });
    }

    public function down(): void
    {
        Schema::table('whatsapp_sessions', function (Blueprint $table): void {
            $table->dropColumn('wa_name');
        });
    }
};
