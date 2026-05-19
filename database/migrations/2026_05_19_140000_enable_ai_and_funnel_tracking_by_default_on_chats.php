<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('chats', 'ai_enabled')) {
            return;
        }

        DB::table('chats')
            ->where('is_group', false)
            ->where('ai_enabled', false)
            ->update(['ai_enabled' => true]);

        if (Schema::hasColumn('chats', 'funnel_tracking_enabled')) {
            DB::table('chats')
                ->where('is_group', false)
                ->where('funnel_tracking_enabled', false)
                ->update(['funnel_tracking_enabled' => true]);
        }

        $driver = Schema::getConnection()->getDriverName();
        if ($driver === 'mysql') {
            DB::statement('ALTER TABLE chats MODIFY ai_enabled TINYINT(1) NOT NULL DEFAULT 1');
        }
    }

    public function down(): void
    {
        if (! Schema::hasColumn('chats', 'ai_enabled')) {
            return;
        }

        $driver = Schema::getConnection()->getDriverName();
        if ($driver === 'mysql') {
            DB::statement('ALTER TABLE chats MODIFY ai_enabled TINYINT(1) NOT NULL DEFAULT 0');
        }
    }
};
