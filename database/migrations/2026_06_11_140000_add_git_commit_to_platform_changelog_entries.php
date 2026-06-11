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
            $table->string('git_commit_hash', 64)->nullable()->unique()->after('id');
            $table->string('source_commit_subject', 500)->nullable()->after('git_commit_hash');
        });
    }

    public function down(): void
    {
        Schema::table('platform_changelog_entries', function (Blueprint $table): void {
            $table->dropUnique(['git_commit_hash']);
            $table->dropColumn(['git_commit_hash', 'source_commit_subject']);
        });
    }
};
