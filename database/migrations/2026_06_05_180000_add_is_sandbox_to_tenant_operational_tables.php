<?php

declare(strict_types=1);

use App\Services\SuperAdmin\TenantSandboxMarkerService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contacts', function (Blueprint $table): void {
            if (! Schema::hasColumn('contacts', 'is_sandbox')) {
                $table->boolean('is_sandbox')->default(false)->after('is_business');
                $table->index(['company_id', 'is_sandbox']);
            }
        });

        Schema::table('chats', function (Blueprint $table): void {
            if (! Schema::hasColumn('chats', 'is_sandbox')) {
                $table->boolean('is_sandbox')->default(false)->after('is_group');
                $table->index(['company_id', 'is_sandbox']);
            }
        });

        Schema::table('whatsapp_sessions', function (Blueprint $table): void {
            if (! Schema::hasColumn('whatsapp_sessions', 'is_sandbox')) {
                $table->boolean('is_sandbox')->default(false)->after('is_active');
                $table->index(['company_id', 'is_sandbox']);
            }
        });

        app(TenantSandboxMarkerService::class)->backfillAllCompanies();
    }

    public function down(): void
    {
        foreach (['whatsapp_sessions', 'chats', 'contacts'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table) use ($tableName): void {
                if (Schema::hasColumn($tableName, 'is_sandbox')) {
                    $table->dropColumn('is_sandbox');
                }
            });
        }
    }
};
