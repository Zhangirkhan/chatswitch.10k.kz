<?php

declare(strict_types=1);

use App\Models\TeamMessage;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('team_conversation_user', function (Blueprint $table): void {
            $table->unsignedBigInteger('last_delivered_message_id')->nullable()->after('last_read_message_id');
        });

        Schema::table('team_conversation_user', function (Blueprint $table): void {
            $table->foreign('last_delivered_message_id')->references('id')->on('team_messages')->nullOnDelete();
        });

        // Прочитано подразумевает доставку до того же id (обратная совместимость для старых строк pivot).
        DB::table('team_conversation_user')
            ->whereNotNull('last_read_message_id')
            ->update([
                'last_delivered_message_id' => DB::raw('last_read_message_id'),
            ]);

        Schema::create('team_message_mentions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('team_message_id')->constrained('team_messages')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['team_message_id', 'user_id']);
            $table->index(['user_id', 'id']);
        });

        $this->backfillMentionsFromJson();
    }

    public function down(): void
    {
        Schema::dropIfExists('team_message_mentions');

        Schema::table('team_conversation_user', function (Blueprint $table): void {
            $table->dropForeign(['last_delivered_message_id']);
        });

        Schema::table('team_conversation_user', function (Blueprint $table): void {
            $table->dropColumn('last_delivered_message_id');
        });
    }

    private function backfillMentionsFromJson(): void
    {
        TeamMessage::query()
            ->whereNotNull('mentioned_user_ids')
            ->orderBy('id')
            ->chunkById(200, function ($messages): void {
                $now = now();
                $inserts = [];
                foreach ($messages as $m) {
                    $decoded = $m->mentioned_user_ids;
                    if (! is_array($decoded) || $decoded === []) {
                        continue;
                    }
                    $msgId = (int) $m->id;
                    foreach ($decoded as $uid) {
                        $id = (int) $uid;
                        if ($id < 1) {
                            continue;
                        }
                        $inserts[] = [
                            'team_message_id' => $msgId,
                            'user_id' => $id,
                            'created_at' => $now,
                            'updated_at' => $now,
                        ];
                    }
                }
                if ($inserts === []) {
                    return;
                }
                foreach (array_chunk($inserts, 100) as $chunk) {
                    DB::table('team_message_mentions')->insertOrIgnore($chunk);
                }
            });
    }
};
