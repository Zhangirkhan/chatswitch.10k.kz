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
        Schema::table('system_settings', function (Blueprint $table): void {
            $table->foreignId('company_id')->nullable()->after('id')->constrained()->cascadeOnDelete();
        });

        Schema::table('system_settings', function (Blueprint $table): void {
            $table->dropUnique(['key']);
        });

        $legacy = DB::table('system_settings')->whereNull('company_id')->get();
        $companyIds = DB::table('companies')->pluck('id');

        foreach ($companyIds as $companyId) {
            foreach ($legacy as $row) {
                $exists = DB::table('system_settings')
                    ->where('company_id', $companyId)
                    ->where('key', $row->key)
                    ->exists();

                if ($exists) {
                    continue;
                }

                DB::table('system_settings')->insert([
                    'company_id' => $companyId,
                    'key' => $row->key,
                    'value' => $row->value,
                    'created_at' => $row->created_at ?? now(),
                    'updated_at' => $row->updated_at ?? now(),
                ]);
            }
        }

        DB::table('system_settings')->whereNull('company_id')->delete();

        Schema::table('system_settings', function (Blueprint $table): void {
            $table->unsignedBigInteger('company_id')->nullable(false)->change();
            $table->unique(['company_id', 'key']);
        });
    }

    public function down(): void
    {
        Schema::table('system_settings', function (Blueprint $table): void {
            $table->dropUnique(['company_id', 'key']);
            $table->dropConstrainedForeignId('company_id');
            $table->unique('key');
        });
    }
};
