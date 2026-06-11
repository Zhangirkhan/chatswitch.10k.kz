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
            $table->json('sales_state')->nullable()->after('active_topic_updated_at');
            $table->timestamp('sales_state_updated_at')->nullable()->after('sales_state');
        });
    }

    public function down(): void
    {
        Schema::table('chats', function (Blueprint $table): void {
            $table->dropColumn(['sales_state', 'sales_state_updated_at']);
        });
    }
};
