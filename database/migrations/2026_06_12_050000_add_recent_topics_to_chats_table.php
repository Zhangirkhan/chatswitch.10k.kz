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
            // Stores up to 2 previous active topics (JSON string[]) so multi-topic
            // conversations (delivery + price + warranty) don't lose context when the
            // user switches topics.  The primary active_topic remains the current one.
            $table->json('recent_topics')->nullable()->after('active_topic_updated_at');
        });
    }

    public function down(): void
    {
        Schema::table('chats', function (Blueprint $table): void {
            $table->dropColumn('recent_topics');
        });
    }
};
