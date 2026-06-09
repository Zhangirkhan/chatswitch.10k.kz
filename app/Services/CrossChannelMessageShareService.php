<?php

declare(strict_types=1);

namespace App\Services;

use App\Jobs\SendOutboundMessageJob;
use App\Models\Contact;
use App\Models\Message;
use App\Models\TeamConversation;
use App\Models\TeamMessage;
use App\Models\User;
use App\Models\WhatsappSession;
use App\Support\OutboundSenderDisplayName;
use App\Support\SharedMessageQuote;
use Illuminate\Validation\ValidationException;

final class CrossChannelMessageShareService
{
    public function __construct(
        private readonly ChatService $chatService,
        private readonly TeamChatService $teamChatService,
    ) {}

    /**
     * @param  list<int>  $contactIds
     */
    public function shareTeamMessageToClients(
        User $user,
        TeamMessage $source,
        array $contactIds,
        int $whatsappSessionId,
        string $caption = '',
    ): int {
        $source->loadMissing([
            'sender:id,name',
            'attachments',
            'conversation.department:id,name',
            'conversation.userLow:id,name',
            'conversation.userHigh:id,name',
        ]);

        $conversation = $source->conversation;
        if ($conversation === null) {
            throw ValidationException::withMessages([
                'team_message_id' => 'Исходное сообщение недоступно.',
            ]);
        }

        if (! $this->teamChatService->userCanAccessConversation($user, $conversation)) {
            throw ValidationException::withMessages([
                'team_message_id' => 'Нет доступа к исходному сообщению.',
            ]);
        }

        $session = WhatsappSession::query()->find($whatsappSessionId);
        if ($session === null) {
            throw ValidationException::withMessages([
                'whatsapp_session_id' => 'Сессия WhatsApp не найдена.',
            ]);
        }

        abort_unless($user->can('use', $session), 403, 'Этот номер WhatsApp вам не назначен.');

        $contactIds = array_values(array_unique(array_filter(array_map('intval', $contactIds), fn (int $id) => $id > 0)));
        if ($contactIds === []) {
            throw ValidationException::withMessages(['contact_ids' => 'Выберите хотя бы один контакт.']);
        }

        $contacts = Contact::query()->whereIn('id', $contactIds)->get();
        if ($contacts->isEmpty()) {
            throw ValidationException::withMessages(['contact_ids' => 'Контакты не найдены.']);
        }

        $outboundBody = $this->composeClientOutboundBodyFromTeamShare($user, $source, trim($caption));

        $sent = 0;

        foreach ($contacts as $contact) {
            $chat = $this->chatService->findForwardTargetChatForContact($contact, $session);

            $outbound = Message::query()->create([
                'chat_id' => $chat->id,
                'whatsapp_session_id' => $session->id,
                'whatsapp_message_id' => null,
                'direction' => 'outbound',
                'type' => 'chat',
                'body' => $outboundBody,
                'metadata' => [
                    'shared_from_team_message_id' => $source->id,
                ],
                'sent_by_user_id' => $user->id,
                'sender_name' => OutboundSenderDisplayName::resolve($user),
                'is_forwarded' => false,
                'quoted_message_id' => null,
                'ack' => 'pending',
                'message_timestamp' => now(),
            ]);

            SendOutboundMessageJob::dispatch($outbound->id, 'text', [
                'body' => $outboundBody,
                'quoted_message_id' => null,
            ]);
            $this->chatService->refreshChatLastMessageSnapshot($chat);
            $sent++;
        }

        return $sent;
    }

    /**
     * @param  list<int>  $teamConversationIds
     * @return array{sent: int, messages: list<TeamMessage>}
     */
    public function shareWhatsappMessageToTeam(
        User $user,
        Message $source,
        array $teamConversationIds,
        string $caption = '',
    ): array {
        $source->loadMissing(['chat.contact', 'media', 'sentByUser:id,name']);

        $chat = $source->chat;
        if ($chat === null) {
            throw ValidationException::withMessages([
                'message_id' => 'Чат сообщения не найден.',
            ]);
        }

        if (! $user->can('view', $chat)) {
            throw ValidationException::withMessages([
                'message_id' => 'Нет доступа к этому сообщению.',
            ]);
        }

        $teamConversationIds = array_values(array_unique(array_filter(array_map('intval', $teamConversationIds), fn (int $id) => $id > 0)));
        if ($teamConversationIds === []) {
            throw ValidationException::withMessages(['team_conversation_ids' => 'Выберите хотя бы одну беседу.']);
        }

        $sent = 0;
        $messages = [];

        foreach ($teamConversationIds as $conversationId) {
            $conversation = TeamConversation::query()->find($conversationId);
            if ($conversation === null) {
                continue;
            }

            $result = $this->teamChatService->shareFromWhatsappMessage(
                $user,
                $conversation,
                $source,
                trim($caption),
                null,
            );
            $sent++;
            $messages[] = $result->message;
        }

        if ($sent === 0) {
            throw ValidationException::withMessages(['team_conversation_ids' => 'Беседы не найдены или недоступны.']);
        }

        return ['sent' => $sent, 'messages' => $messages];
    }

    /**
     * Текст для клиента в WhatsApp: без меток «переслано», только содержимое и комментарий оператора.
     */
    public function composeClientOutboundBodyFromTeamShare(User $user, TeamMessage $source, string $caption): string
    {
        $conversation = $source->conversation;
        $convTitle = $conversation !== null
            ? $this->teamChatService->conversationTitleForShare($conversation)
            : '';
        [, , $messageText] = SharedMessageQuote::fromTeamMessage($source, $convTitle);
        $messageText = trim($messageText);
        $caption = trim($caption);

        $parts = array_values(array_filter([$messageText, $caption], static fn (string $p): bool => $p !== ''));
        if ($parts === []) {
            throw ValidationException::withMessages([
                'body' => 'Нечего отправить: в сообщении нет текста, добавьте комментарий.',
            ]);
        }

        return OperatorSignature::prepend($user, implode("\n\n", $parts));
    }
}
