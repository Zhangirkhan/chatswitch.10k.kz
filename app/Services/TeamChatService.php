<?php

declare(strict_types=1);

namespace App\Services;

use App\Events\TeamChatDeliveredUpdated;
use App\Events\TeamChatReadUpdated;
use App\Events\TeamMessageReceived;
use App\Models\TeamConversation;
use App\Models\TeamMessage;
use App\Models\TeamMessageMention;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class TeamChatService
{
    public function findOrCreateDirect(User $current, User $peer): TeamConversation
    {
        if ($current->id === $peer->id) {
            throw ValidationException::withMessages(['user_id' => 'Нельзя открыть чат с самим собой.']);
        }

        if (! $peer->is_active) {
            throw ValidationException::withMessages(['user_id' => 'Пользователь неактивен.']);
        }

        $companyId = $current->company_id;
        $peerCompanyId = $peer->company_id;

        if ($companyId === null || $peerCompanyId === null || (int) $companyId !== (int) $peerCompanyId) {
            throw ValidationException::withMessages(['user_id' => 'Личный чат доступен только между сотрудниками одной компании.']);
        }

        $low = min($current->id, $peer->id);
        $high = max($current->id, $peer->id);

        return DB::transaction(function () use ($companyId, $low, $high): TeamConversation {
            $conversation = TeamConversation::query()
                ->where('type', TeamConversation::TYPE_DIRECT)
                ->where('company_id', $companyId)
                ->where('user_low_id', $low)
                ->where('user_high_id', $high)
                ->lockForUpdate()
                ->first();

            if ($conversation !== null) {
                return $conversation;
            }

            $conversation = TeamConversation::query()->create([
                'company_id' => $companyId,
                'type' => TeamConversation::TYPE_DIRECT,
                'department_id' => null,
                'user_low_id' => $low,
                'user_high_id' => $high,
            ]);

            $conversation->participants()->sync([
                $low => [
                    'can_leave' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ],
                $high => [
                    'can_leave' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ],
            ]);

            return $conversation;
        });
    }

    /**
     * @param  array<int, int|string>|null  $mentionUserIds
     */
    public function sendMessage(
        User $sender,
        TeamConversation $conversation,
        string $body,
        ?string $clientMessageId = null,
        ?array $mentionUserIds = null,
        ?int $parentTeamMessageId = null,
    ): TeamChatSendResult {
        $body = trim($body);
        if ($body === '') {
            throw ValidationException::withMessages(['body' => 'Сообщение не может быть пустым.']);
        }

        if ($body !== '' && mb_strlen($body) > 16000) {
            throw ValidationException::withMessages(['body' => 'Сообщение слишком длинное.']);
        }

        $clientKey = $this->normalizeClientMessageId($clientMessageId);
        $mentionsNormalized = $this->normalizeMentionUserIds($conversation, $mentionUserIds ?? []);
        $parentIdPersisted = $this->validatedParentTeamMessageId($conversation, $parentTeamMessageId);

        return DB::transaction(function () use ($sender, $conversation, $body, $clientKey, $mentionsNormalized, $parentIdPersisted): TeamChatSendResult {
            if ($clientKey !== null) {
                $existing = TeamMessage::query()
                    ->where('team_conversation_id', $conversation->id)
                    ->where('sender_id', $sender->id)
                    ->where('client_message_id', $clientKey)
                    ->first();
                if ($existing !== null) {
                    return new TeamChatSendResult($existing, true);
                }
            }

            $preview = mb_substr(preg_replace('/\s+/u', ' ', $body) ?? '', 0, 240);

            try {
                $message = TeamMessage::query()->create([
                    'team_conversation_id' => $conversation->id,
                    'parent_team_message_id' => $parentIdPersisted,
                    'sender_id' => $sender->id,
                    'body' => $body,
                    'client_message_id' => $clientKey,
                    'mentioned_user_ids' => $mentionsNormalized,
                ]);
                $this->replaceMessageMentions($message, $mentionsNormalized);
            } catch (QueryException $e) {
                if ($clientKey === null) {
                    throw $e;
                }

                $message = TeamMessage::query()
                    ->where('team_conversation_id', $conversation->id)
                    ->where('sender_id', $sender->id)
                    ->where('client_message_id', $clientKey)
                    ->first();
                if ($message === null) {
                    throw $e;
                }

                return new TeamChatSendResult($message, true);
            }

            $conversation->forceFill([
                'last_message_at' => $message->created_at,
                'last_message_preview' => $preview,
            ])->save();

            DB::afterCommit(function () use ($message): void {
                broadcast(new TeamMessageReceived($message));
            });

            return new TeamChatSendResult($message, false);
        });
    }

    public function forwardMessage(
        User $sender,
        TeamConversation $targetConversation,
        int $sourceTeamMessageId,
        string $caption,
        ?string $clientMessageId = null,
    ): TeamChatSendResult {
        $caption = trim($caption);
        if (mb_strlen($caption) > 16000) {
            throw ValidationException::withMessages(['body' => 'Комментарий слишком длинный.']);
        }

        $source = TeamMessage::query()
            ->whereKey($sourceTeamMessageId)
            ->with(['sender:id,name', 'conversation.department:id,name', 'conversation.userLow:id,name', 'conversation.userHigh:id,name'])
            ->first();

        if ($source === null) {
            throw ValidationException::withMessages([
                'forwarded_from_team_message_id' => 'Исходное сообщение не найдено.',
            ]);
        }

        $sourceConversation = $source->conversation;
        if ($sourceConversation === null) {
            throw ValidationException::withMessages([
                'forwarded_from_team_message_id' => 'Исходное сообщение недоступно.',
            ]);
        }

        if (! $this->userIsParticipant($sender, $sourceConversation)) {
            throw ValidationException::withMessages([
                'forwarded_from_team_message_id' => 'Нет доступа к исходному сообщению.',
            ]);
        }

        if (! $this->userIsParticipant($sender, $targetConversation)) {
            throw ValidationException::withMessages([
                'team_conversation_id' => 'Нет доступа к этой беседе.',
            ]);
        }

        if (! $this->sameCompanyConversations($sourceConversation, $targetConversation)) {
            throw ValidationException::withMessages([
                'forwarded_from_team_message_id' => 'Пересылать можно только внутри одной компании.',
            ]);
        }

        $textForQuote = trim((string) $source->body);
        if ($textForQuote === '' && is_string($source->forward_quote_body) && trim($source->forward_quote_body) !== '') {
            $textForQuote = trim($source->forward_quote_body);
        }
        $quoteBody = mb_substr(preg_replace('/\s+/u', ' ', $textForQuote) ?? '', 0, 480);
        $quoteSender = $source->sender?->name ?? '…';
        $sourceTitle = $this->conversationForwardSourceTitle($sourceConversation);

        $clientKey = $this->normalizeClientMessageId($clientMessageId);

        return DB::transaction(function () use ($sender, $targetConversation, $source, $caption, $clientKey, $quoteBody, $quoteSender, $sourceTitle): TeamChatSendResult {
            if ($clientKey !== null) {
                $existing = TeamMessage::query()
                    ->where('team_conversation_id', $targetConversation->id)
                    ->where('sender_id', $sender->id)
                    ->where('client_message_id', $clientKey)
                    ->first();
                if ($existing !== null) {
                    return new TeamChatSendResult($existing, true);
                }
            }

            $preview = $caption !== ''
                ? mb_substr(preg_replace('/\s+/u', ' ', $caption) ?? '', 0, 240)
                : mb_substr('Переслано: '.$quoteBody, 0, 240);

            try {
                $message = TeamMessage::query()->create([
                    'team_conversation_id' => $targetConversation->id,
                    'sender_id' => $sender->id,
                    'body' => $caption,
                    'client_message_id' => $clientKey,
                    'mentioned_user_ids' => null,
                    'forwarded_from_team_message_id' => $source->id,
                    'forward_source_title' => $sourceTitle,
                    'forward_quote_sender_name' => $quoteSender,
                    'forward_quote_body' => $quoteBody !== '' ? $quoteBody : null,
                ]);
            } catch (QueryException $e) {
                if ($clientKey === null) {
                    throw $e;
                }

                $message = TeamMessage::query()
                    ->where('team_conversation_id', $targetConversation->id)
                    ->where('sender_id', $sender->id)
                    ->where('client_message_id', $clientKey)
                    ->first();
                if ($message === null) {
                    throw $e;
                }

                return new TeamChatSendResult($message, true);
            }

            $targetConversation->forceFill([
                'last_message_at' => $message->created_at,
                'last_message_preview' => $preview,
            ])->save();

            DB::afterCommit(function () use ($message): void {
                broadcast(new TeamMessageReceived($message));
            });

            return new TeamChatSendResult($message, false);
        });
    }

    public function markRead(User $user, TeamConversation $conversation, ?int $messageId = null): void
    {
        $pivot = $conversation->participants()->where('users.id', $user->id)->first();
        if ($pivot === null) {
            return;
        }

        $previousReadId = $pivot->pivot->last_read_message_id !== null
            ? (int) $pivot->pivot->last_read_message_id
            : 0;

        $previousDeliveredId = $pivot->pivot->last_delivered_message_id !== null
            ? (int) $pivot->pivot->last_delivered_message_id
            : 0;

        $lastId = $messageId;
        if ($lastId === null) {
            $lastId = (int) $conversation->messages()->max('id');
        }

        if ($lastId < 1) {
            return;
        }

        if ($lastId <= $previousReadId) {
            return;
        }

        $mergedDelivered = max($previousDeliveredId, $lastId);

        $conversation->participants()->updateExistingPivot($user->id, [
            'last_read_message_id' => $lastId,
            'last_read_at' => now(),
            'last_delivered_message_id' => $mergedDelivered,
            'updated_at' => now(),
        ]);

        broadcast(new TeamChatReadUpdated(
            (int) $conversation->id,
            (int) $user->id,
            $lastId,
        ));
    }

    public function markDelivered(User $user, TeamConversation $conversation, int $messageId): void
    {
        if ($messageId < 1) {
            return;
        }

        $pivot = $conversation->participants()->where('users.id', $user->id)->first();
        if ($pivot === null) {
            return;
        }

        $message = TeamMessage::query()
            ->where('team_conversation_id', $conversation->id)
            ->whereKey($messageId)
            ->first();

        if ($message === null) {
            return;
        }

        if ((int) $message->sender_id === (int) $user->id) {
            return;
        }

        $previousDeliveredId = $pivot->pivot->last_delivered_message_id !== null
            ? (int) $pivot->pivot->last_delivered_message_id
            : 0;

        if ($messageId <= $previousDeliveredId) {
            return;
        }

        $conversation->participants()->updateExistingPivot($user->id, [
            'last_delivered_message_id' => $messageId,
            'updated_at' => now(),
        ]);

        broadcast(new TeamChatDeliveredUpdated(
            (int) $conversation->id,
            (int) $user->id,
            $messageId,
        ));
    }

    /**
     * @param  array<int, int>|null  $ids
     */
    private function replaceMessageMentions(TeamMessage $message, ?array $ids): void
    {
        TeamMessageMention::query()->where('team_message_id', $message->id)->delete();
        if ($ids === null || $ids === []) {
            return;
        }

        $now = now();
        $rows = [];
        foreach ($ids as $uid) {
            $id = (int) $uid;
            if ($id < 1) {
                continue;
            }
            $rows[] = [
                'team_message_id' => $message->id,
                'user_id' => $id,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        if ($rows === []) {
            return;
        }

        foreach (array_chunk($rows, 50) as $chunk) {
            TeamMessageMention::query()->insert($chunk);
        }
    }

    private function validatedParentTeamMessageId(TeamConversation $conversation, ?int $parentId): ?int
    {
        if ($parentId === null || $parentId < 1) {
            return null;
        }

        $parent = TeamMessage::query()
            ->where('team_conversation_id', $conversation->id)
            ->whereKey($parentId)
            ->whereNull('deleted_at')
            ->first();

        if ($parent === null) {
            throw ValidationException::withMessages([
                'parent_team_message_id' => 'Сообщение для ответа не найдено.',
            ]);
        }

        if ($parent->parent_team_message_id !== null) {
            throw ValidationException::withMessages([
                'parent_team_message_id' => 'Ответ можно отправить только на сообщение в основном потоке (не на ответ).',
            ]);
        }

        return (int) $parent->id;
    }

    /**
     * @param  array<int, int|string>  $rawIds
     * @return array<int, int>|null
     */
    private function normalizeMentionUserIds(TeamConversation $conversation, array $rawIds): ?array
    {
        $ids = [];
        foreach ($rawIds as $v) {
            $id = (int) $v;
            if ($id > 0 && ! in_array($id, $ids, true)) {
                $ids[] = $id;
            }
        }

        if ($ids === []) {
            return null;
        }

        if (count($ids) > 20) {
            throw ValidationException::withMessages(['mention_user_ids' => 'Не более 20 упоминаний.']);
        }

        $participantIds = $conversation->participants()
            ->pluck('users.id')
            ->map(fn ($id) => (int) $id)
            ->all();

        foreach ($ids as $id) {
            if (! in_array($id, $participantIds, true)) {
                throw ValidationException::withMessages([
                    'mention_user_ids' => 'Можно упоминать только участников этой беседы.',
                ]);
            }
        }

        sort($ids);

        return $ids;
    }

    private function normalizeClientMessageId(?string $clientMessageId): ?string
    {
        if ($clientMessageId === null) {
            return null;
        }
        $t = trim($clientMessageId);
        if ($t === '') {
            return null;
        }

        return mb_substr($t, 0, 64);
    }

    private function userIsParticipant(User $user, TeamConversation $conversation): bool
    {
        return $conversation->participants()->where('users.id', $user->id)->exists();
    }

    private function sameCompanyConversations(TeamConversation $a, TeamConversation $b): bool
    {
        $ca = $a->company_id;
        $cb = $b->company_id;

        if ($ca === null || $cb === null) {
            return false;
        }

        return (int) $ca === (int) $cb;
    }

    private function conversationForwardSourceTitle(TeamConversation $c): string
    {
        if ($c->isDepartment() && $c->relationLoaded('department') && $c->department) {
            return $c->department->name;
        }

        if ($c->isDepartment() && $c->department_id !== null) {
            $c->loadMissing('department:id,name');
            if ($c->department) {
                return $c->department->name;
            }
        }

        if ($c->isDirect()) {
            $c->loadMissing(['userLow:id,name', 'userHigh:id,name']);
            $names = array_filter([
                $c->userLow?->name,
                $c->userHigh?->name,
            ]);
            $names = array_values(array_unique($names));
            sort($names);

            return $names === [] ? 'Личный чат' : 'ЛС: '.implode(', ', $names);
        }

        return 'Чат';
    }
}
