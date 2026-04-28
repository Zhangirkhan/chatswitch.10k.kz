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
            $table->foreignId('community_id')
                ->nullable()
                ->after('contact_id')
                ->constrained('communities')
                ->nullOnDelete();

            $table->index(['community_id']);
        });
    }

    public function down(): void
    {
        Schema::table('chats', function (Blueprint $table) {
            $table->dropConstrainedForeignId('community_id');
        });
    }
};
