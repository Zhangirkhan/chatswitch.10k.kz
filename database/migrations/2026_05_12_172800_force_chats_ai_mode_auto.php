<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('chats')
            ->where('ai_mode', 'draft')
            ->update(['ai_mode' => 'auto']);

        $driver = DB::connection()->getDriverName();

        if ($driver === 'mysql') {
            DB::statement("ALTER TABLE chats ALTER ai_mode SET DEFAULT 'auto'");
        } elseif ($driver === 'pgsql') {
            DB::statement("ALTER TABLE chats ALTER COLUMN ai_mode SET DEFAULT 'auto'");
        }
    }

    public function down(): void
    {
        $driver = DB::connection()->getDriverName();

        if ($driver === 'mysql') {
            DB::statement("ALTER TABLE chats ALTER ai_mode SET DEFAULT 'draft'");
        } elseif ($driver === 'pgsql') {
            DB::statement("ALTER TABLE chats ALTER COLUMN ai_mode SET DEFAULT 'draft'");
        }
    }
};
