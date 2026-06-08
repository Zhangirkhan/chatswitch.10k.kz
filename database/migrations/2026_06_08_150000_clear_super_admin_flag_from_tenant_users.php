<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('users')
            ->whereNotNull('company_id')
            ->where('is_super_admin', true)
            ->update(['is_super_admin' => false]);
    }

    public function down(): void
    {
        // Irreversible data cleanup.
    }
};
