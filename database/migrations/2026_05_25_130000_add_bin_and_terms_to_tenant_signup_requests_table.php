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
            $table->string('bin', 12)->nullable()->after('company_name');
            $table->timestamp('terms_accepted_at')->nullable()->after('message');
        });
    }

    public function down(): void
    {
        Schema::table('tenant_signup_requests', function (Blueprint $table): void {
            $table->dropColumn(['bin', 'terms_accepted_at']);
        });
    }
};
