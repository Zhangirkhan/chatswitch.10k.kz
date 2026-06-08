<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('chats', function (Blueprint $table): void {
            $table->boolean('is_lead_closed')->default(false)->after('is_favorite');
            $table->timestamp('lead_closed_at')->nullable()->after('is_lead_closed');

            $table->index(['company_id', 'is_lead_closed', 'is_archived']);
        });
    }

    public function down(): void
    {
        Schema::table('chats', function (Blueprint $table): void {
            $table->dropIndex(['company_id', 'is_lead_closed', 'is_archived']);
            $table->dropColumn(['is_lead_closed', 'lead_closed_at']);
        });
    }
};
