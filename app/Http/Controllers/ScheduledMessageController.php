<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Chat;
use App\Models\ScheduledMessage;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final class ScheduledMessageController extends Controller
{
    private const SCHEDULE_TZ = 'Asia/Almaty';

    public function index(Request $request, Chat $chat): JsonResponse
    {
        $this->authorize('view', $chat);

        $items = ScheduledMessage::query()
            ->where('chat_id', $chat->id)
            ->whereIn('status', [
                ScheduledMessage::STATUS_PENDING,
                ScheduledMessage::STATUS_FAILED,
                ScheduledMessage::STATUS_SENT,
            ])
            ->with(['user:id,name'])
            ->orderByRaw("CASE status WHEN 'pending' THEN 0 WHEN 'failed' THEN 1 WHEN 'sent' THEN 2 ELSE 3 END")
            ->orderBy('scheduled_at')
            ->limit(100)
            ->get()
            ->map(fn (ScheduledMessage $message) => $this->serialize($message));

        return response()->json(['scheduled_messages' => $items]);
    }

    public function store(Request $request, Chat $chat): JsonResponse
    {
        $this->authorize('sendMessage', $chat);

        $data = $this->validatedPayload($request);
        $scheduledAt = $this->parseScheduledAt((string) $data['scheduled_at']);
        if ($scheduledAt->lessThanOrEqualTo(now()->addSeconds(20))) {
            return response()->json(['message' => 'Выберите время позже текущего.'], 422);
        }

        $chat->loadMissing('whatsappSession');
        if ($chat->whatsappSession === null) {
            return response()->json(['message' => 'У чата нет активной WhatsApp-сессии.'], 422);
        }

        $message = ScheduledMessage::create([
            'chat_id' => $chat->id,
            'whatsapp_session_id' => $chat->whatsappSession->id,
            'user_id' => $request->user()->id,
            'body' => (string) $data['body'],
            'display_body' => (string) ($data['display_body'] ?? $data['body']),
            'scheduled_at' => $scheduledAt,
            'status' => ScheduledMessage::STATUS_PENDING,
            'error' => null,
        ]);
        $message->load('user:id,name');

        return response()->json(['scheduled_message' => $this->serialize($message)], 201);
    }

    public function update(Request $request, Chat $chat, ScheduledMessage $scheduledMessage): JsonResponse
    {
        $this->authorize('sendMessage', $chat);
        $this->ensureBelongsToChat($scheduledMessage, $chat);

        if (! in_array($scheduledMessage->status, [ScheduledMessage::STATUS_PENDING, ScheduledMessage::STATUS_FAILED], true)) {
            return response()->json(['message' => 'Это отложенное сообщение уже нельзя изменить.'], 422);
        }

        $data = $this->validatedPayload($request);
        $scheduledAt = $this->parseScheduledAt((string) $data['scheduled_at']);
        if ($scheduledAt->lessThanOrEqualTo(now()->addSeconds(20))) {
            return response()->json(['message' => 'Выберите время позже текущего.'], 422);
        }

        $scheduledMessage->update([
            'body' => (string) $data['body'],
            'display_body' => (string) ($data['display_body'] ?? $data['body']),
            'scheduled_at' => $scheduledAt,
            'status' => ScheduledMessage::STATUS_PENDING,
            'error' => null,
        ]);
        $scheduledMessage->load('user:id,name');

        return response()->json(['scheduled_message' => $this->serialize($scheduledMessage)]);
    }

    public function destroy(Request $request, Chat $chat, ScheduledMessage $scheduledMessage): JsonResponse
    {
        $this->authorize('sendMessage', $chat);
        $this->ensureBelongsToChat($scheduledMessage, $chat);

        if (! in_array($scheduledMessage->status, [ScheduledMessage::STATUS_PENDING, ScheduledMessage::STATUS_FAILED], true)) {
            return response()->json(['message' => 'Это отложенное сообщение уже нельзя отменить.'], 422);
        }

        $scheduledMessage->update([
            'status' => ScheduledMessage::STATUS_CANCELLED,
            'error' => null,
        ]);

        return response()->json(['success' => true]);
    }

    /** @return array<string, mixed> */
    private function validatedPayload(Request $request): array
    {
        return $request->validate([
            'body' => ['required', 'string', 'max:4096'],
            'display_body' => ['nullable', 'string', 'max:4096'],
            'scheduled_at' => ['required', 'string', 'max:64'],
            'timezone' => ['nullable', Rule::in([self::SCHEDULE_TZ])],
        ]);
    }

    private function parseScheduledAt(string $value): CarbonImmutable
    {
        $value = trim($value);
        $formats = ['Y-m-d\TH:i', 'Y-m-d H:i:s', 'Y-m-d H:i'];
        foreach ($formats as $format) {
            $date = CarbonImmutable::createFromFormat($format, $value, self::SCHEDULE_TZ);
            if ($date instanceof CarbonImmutable) {
                return $date->setTimezone('UTC');
            }
        }

        return CarbonImmutable::parse($value, self::SCHEDULE_TZ)->setTimezone('UTC');
    }

    private function ensureBelongsToChat(ScheduledMessage $scheduledMessage, Chat $chat): void
    {
        abort_unless((int) $scheduledMessage->chat_id === (int) $chat->id, 404);
    }

    /** @return array<string, mixed> */
    private function serialize(ScheduledMessage $message): array
    {
        return [
            'id' => $message->id,
            'chat_id' => $message->chat_id,
            'body' => $message->body,
            'display_body' => $message->display_body,
            'scheduled_at' => $message->scheduled_at?->copy()->setTimezone(self::SCHEDULE_TZ)->format('Y-m-d\TH:i'),
            'scheduled_at_label' => $message->scheduled_at?->copy()->setTimezone(self::SCHEDULE_TZ)->format('d.m.Y H:i'),
            'status' => $message->status,
            'error' => $message->error,
            'sent_message_id' => $message->sent_message_id,
            'user' => $message->user ? [
                'id' => $message->user->id,
                'name' => $message->user->name,
            ] : null,
        ];
    }
}

