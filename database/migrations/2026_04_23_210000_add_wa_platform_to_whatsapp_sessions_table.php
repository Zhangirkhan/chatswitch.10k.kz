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
            $table->string('wa_platform', 64)->nullable()->after('wa_name')
                ->comment('WhatsApp client platform from Node on connect (android, iphone, web, etc.)');
        });
    }

    public function down(): void
    {
        Schema::table('whatsapp_sessions', function (Blueprint $table): void {
            $table->dropColumn('wa_platform');
        });
    }
};
