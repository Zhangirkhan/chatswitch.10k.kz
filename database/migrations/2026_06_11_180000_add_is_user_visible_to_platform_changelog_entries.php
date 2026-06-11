<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('platform_changelog_entries', function (Blueprint $table): void {
            $table->boolean('is_user_visible')->default(true)->after('is_published');
            $table->index(['is_published', 'is_user_visible', 'published_at'], 'platform_changelog_user_visible_idx');
        });
    }

    public function down(): void
    {
        Schema::table('platform_changelog_entries', function (Blueprint $table): void {
            $table->dropIndex('platform_changelog_user_visible_idx');
            $table->dropColumn('is_user_visible');
        });
    }
};
