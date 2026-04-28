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
            $table->foreignId('user_id')->nullable()->change();
            $table->string('external_id')->nullable()->after('user_id');
            $table->string('external_name')->nullable()->after('external_id');
            $table->unique(['message_id', 'external_id']);
        });
    }

    public function down(): void
    {
        Schema::table('message_reactions', function (Blueprint $table): void {
            $table->dropUnique(['message_id', 'external_id']);
            $table->dropColumn(['external_id', 'external_name']);
            $table->foreignId('user_id')->nullable(false)->change();
        });
    }
};
