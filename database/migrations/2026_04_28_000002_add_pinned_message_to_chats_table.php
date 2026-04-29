<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('chats', function (Blueprint $table) {
            if (!Schema::hasColumn('chats', 'pinned_message_id')) {
                $table->foreignId('pinned_message_id')
                    ->nullable()
                    ->after('is_pinned')
                    ->constrained('messages')
                    ->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        Schema::table('chats', function (Blueprint $table) {
            if (Schema::hasColumn('chats', 'pinned_message_id')) {
                $table->dropConstrainedForeignId('pinned_message_id');
            }
        });
    }
};

