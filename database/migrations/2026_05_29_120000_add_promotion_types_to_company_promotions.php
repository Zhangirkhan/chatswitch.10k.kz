<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('company_promotions', function (Blueprint $table): void {
            $table->unsignedTinyInteger('buy_quantity')->nullable()->after('fixed_amount');
            $table->unsignedTinyInteger('get_quantity')->nullable()->after('buy_quantity');
        });

        Schema::table('company_promotions', function (Blueprint $table): void {
            $table->string('discount_type', 24)->default('percent')->change();
        });
    }

    public function down(): void
    {
        Schema::table('company_promotions', function (Blueprint $table): void {
            $table->dropColumn(['buy_quantity', 'get_quantity']);
            $table->string('discount_type', 16)->default('percent')->change();
        });
    }
};
