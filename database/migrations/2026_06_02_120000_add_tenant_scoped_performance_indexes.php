<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Сайдбар чатов в разрезе тенанта: WHERE company_id + is_archived, ORDER BY last_message_at.
        // Старый chats_sidebar_idx не учитывал company_id, добавленный позже мультитенантностью.
        Schema::table('chats', function (Blueprint $table): void {
            if (Schema::hasColumn('chats', 'company_id')) {
                $table->index(['company_id', 'is_archived', 'is_pinned', 'last_message_at'], 'chats_tenant_sidebar_idx');
            }
            if (Schema::hasColumn('chats', 'funnel_id')) {
                $table->index(['company_id', 'funnel_id', 'is_archived', 'is_group', 'last_message_at'], 'chats_tenant_board_idx');
            }
            if (Schema::hasColumn('chats', 'ai_orchestrator_status')) {
                $table->index(['company_id', 'ai_orchestrator_status', 'is_archived'], 'chats_tenant_attention_idx');
            }
        });

        // Фильтрация инвойсов по диапазону дат внутри тенанта.
        Schema::table('invoices', function (Blueprint $table): void {
            if (Schema::hasColumn('invoices', 'issued_at')) {
                $table->index(['company_id', 'issued_at'], 'invoices_tenant_issued_idx');
            }
        });

        // Список контактов внутри тенанта с поиском/сортировкой по телефону.
        Schema::table('contacts', function (Blueprint $table): void {
            if (Schema::hasColumn('contacts', 'company_id')) {
                $table->index(['company_id', 'phone_number'], 'contacts_tenant_phone_idx');
            }
        });
    }

    public function down(): void
    {
        Schema::table('chats', function (Blueprint $table): void {
            $table->dropIndex('chats_tenant_sidebar_idx');
            $table->dropIndex('chats_tenant_board_idx');
            $table->dropIndex('chats_tenant_attention_idx');
        });

        Schema::table('invoices', function (Blueprint $table): void {
            $table->dropIndex('invoices_tenant_issued_idx');
        });

        Schema::table('contacts', function (Blueprint $table): void {
            $table->dropIndex('contacts_tenant_phone_idx');
        });
    }
};
