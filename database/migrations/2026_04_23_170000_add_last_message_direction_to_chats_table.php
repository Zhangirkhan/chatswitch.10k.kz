<?php

declare(strict_types=1);

use App\Models\Chat;
use App\Models\Message;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('chats', function (Blueprint $table) {
            $table->string('last_message_direction', 16)->nullable()->after('last_message_at');
        });

        Chat::query()->orderBy('id')->chunkById(100, function ($chats): void {
            foreach ($chats as $chat) {
                $last = Message::query()
                    ->where('chat_id', $chat->id)
                    ->orderByDesc('message_timestamp')
                    ->orderByDesc('id')
                    ->first();
                if ($last) {
                    $chat->update(['last_message_direction' => $last->direction]);
                }
            }
        });
    }

    public function down(): void
    {
        Schema::table('chats', function (Blueprint $table) {
            $table->dropColumn('last_message_direction');
        });
    }
};
