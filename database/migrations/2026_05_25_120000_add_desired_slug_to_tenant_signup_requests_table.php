<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenant_signup_requests', function (Blueprint $table): void {
            $table->string('desired_slug', 32)->nullable()->after('company_name');
        });
    }

    public function down(): void
    {
        Schema::table('tenant_signup_requests', function (Blueprint $table): void {
            $table->dropColumn('desired_slug');
        });
    }
};
