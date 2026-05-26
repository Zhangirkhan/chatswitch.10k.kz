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
            $table->text('company_name')->change();
            $table->text('contact_name')->change();
            $table->text('email')->change();
            $table->text('phone')->nullable()->change();
            $table->text('message')->nullable()->change();
        });

        if (Schema::hasColumn('tenant_signup_requests', 'bin')) {
            Schema::table('tenant_signup_requests', function (Blueprint $table): void {
                $table->text('bin')->nullable()->change();
            });
        }

        if (Schema::hasColumn('tenant_signup_requests', 'desired_slug')) {
            Schema::table('tenant_signup_requests', function (Blueprint $table): void {
                $table->text('desired_slug')->nullable()->change();
            });
        }
    }

    public function down(): void
    {
        Schema::table('tenant_signup_requests', function (Blueprint $table): void {
            $table->string('company_name')->change();
            $table->string('contact_name')->change();
            $table->string('email')->change();
            $table->string('phone')->nullable()->change();
            $table->text('message')->nullable()->change();
        });

        if (Schema::hasColumn('tenant_signup_requests', 'bin')) {
            Schema::table('tenant_signup_requests', function (Blueprint $table): void {
                $table->string('bin', 12)->nullable()->change();
            });
        }

        if (Schema::hasColumn('tenant_signup_requests', 'desired_slug')) {
            Schema::table('tenant_signup_requests', function (Blueprint $table): void {
                $table->string('desired_slug', 32)->nullable()->change();
            });
        }
    }
};
