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
        Schema::table('funnels', function (Blueprint $table): void {
            $table->foreignId('company_id')
                ->nullable()
                ->after('id')
                ->constrained('companies')
                ->cascadeOnDelete();
        });

        $defaultCompanyId = DB::table('companies')->orderBy('id')->value('id');
        if ($defaultCompanyId !== null) {
            DB::table('funnels')
                ->whereNull('company_id')
                ->update(['company_id' => $defaultCompanyId]);
        }

        Schema::table('funnels', function (Blueprint $table): void {
            $table->unsignedBigInteger('company_id')->nullable(false)->change();
            $table->index(['company_id', 'position']);
        });
    }

    public function down(): void
    {
        Schema::table('funnels', function (Blueprint $table): void {
            $table->dropIndex(['company_id', 'position']);
            $table->dropConstrainedForeignId('company_id');
        });
    }
};
