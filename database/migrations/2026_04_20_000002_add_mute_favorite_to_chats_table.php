<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('chats', function (Blueprint $table) {
            $table->boolean('is_muted')->default(false)->after('is_pinned');
            $table->boolean('is_favorite')->default(false)->after('is_muted');
        });
    }

    public function down(): void
    {
        Schema::table('chats', function (Blueprint $table) {
            $table->dropColumn(['is_muted', 'is_favorite']);
        });
    }
};
