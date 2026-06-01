<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->string('super_admin_scope', 20)->default('global')->after('is_super_admin');
        });

        Schema::table('companies', function (Blueprint $table): void {
            $table->foreignId('provisioned_by_user_id')
                ->nullable()
                ->after('owner_user_id')
                ->constrained('users')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('provisioned_by_user_id');
        });

        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('super_admin_scope');
        });
    }
};
