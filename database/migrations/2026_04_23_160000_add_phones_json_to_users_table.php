<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->json('phones')->nullable()->after('phone');
        });

        foreach (
            DB::table('users')
                ->whereNotNull('phone')
                ->where('phone', '!=', '')
                ->orderBy('id')
                ->cursor() as $row
        ) {
            DB::table('users')
                ->where('id', $row->id)
                ->update(['phones' => json_encode([$row->phone])]);
        }
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('phones');
        });
    }
};
