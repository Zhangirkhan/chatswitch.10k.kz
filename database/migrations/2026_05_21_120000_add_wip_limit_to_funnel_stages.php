<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('funnel_stages', function (Blueprint $table): void {
            $table->unsignedSmallInteger('wip_limit')->nullable()->after('is_active');
        });
    }

    public function down(): void
    {
        Schema::table('funnel_stages', function (Blueprint $table): void {
            $table->dropColumn('wip_limit');
        });
    }
};
