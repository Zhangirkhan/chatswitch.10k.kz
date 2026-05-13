<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Jobs\AnalyzeEmployeeToneProfileJob;
use App\Jobs\GenerateAiReplyJob;
use App\Models\AiResponseLog;
use App\Models\Chat;
use App\Models\Company;
use App\Models\Message;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final class ChatAiSettingsController extends Controller
{
    public function update(Request $request, Chat $chat): JsonResponse
    {
        $this->authorize('manageAi', $chat);

        $validated = $request->validate([
            'ai_enabled' => ['required', 'boolean'],
            'ai_mode' => ['nullable', Rule::in(['auto', 'draft'])],
            'ai_responder_user_id' => ['nullable', 'integer', 'exists:users,id'],
            'company_id' => ['nullable', 'integer', 'exists:companies,id'],
        ]);

        $user = $request->user();
        $responder = $this->resolveResponder($chat, $user, $validated['ai_responder_user_id'] ?? null);
        $companyId = $this->resolveCompanyId($chat, $responder, $user, $validated['company_id'] ?? null);

        $aiEnabled = (bool) $validated['ai_enabled'];

        $chat->forceFill([
            'ai_enabled' => $aiEnabled,
            'ai_mode' => (string) ($validated['ai_mode'] ?? $chat->ai_mode ?? 'auto'),
            'ai_responder_user_id' => $responder?->id,
            'company_id' => $companyId,
        ])->save();

        if ($chat->ai_enabled && $responder !== null && $companyId !== null) {
            AnalyzeEmployeeToneProfileJob::dispatch($responder->id, $companyId, $chat->id);
        }

        if ($chat->ai_enabled) {
            $this->dispatchReplyForLatestUnansweredInbound($chat);
        }

        $chat->load(['assignments.user', 'departments', 'aiResponder:id,name']);
        $chat->setAttribute('can_manage_ai', $user->can('manageAi', $chat));

        return response()->json([
            'success' => true,
            'chat' => $chat,
        ]);
    }

    private function resolveResponder(Chat $chat, User $actor, mixed $requestedUserId): ?User
    {
        if ($requestedUserId !== null) {
            $candidate = User::query()->whereKey((int) $requestedUserId)->first();
            if ($candidate !== null && ($actor->hasRole('administrator') || $chat->assignments()->where('user_id', $candidate->id)->exists())) {
                return $candidate;
            }
        }

        if ($chat->ai_responder_user_id !== null) {
            $assigned = $chat->assignments()->where('user_id', $chat->ai_responder_user_id)->exists();
            if ($assigned) {
                return User::query()->whereKey($chat->ai_responder_user_id)->first();
            }
        }

        $lastOutboundUserId = Message::query()
            ->where('chat_id', $chat->id)
            ->where('direction', 'outbound')
            ->whereNotNull('sent_by_user_id')
            ->whereIn('sent_by_user_id', $chat->assignments()->select('user_id'))
            ->orderByDesc('message_timestamp')
            ->orderByDesc('id')
            ->value('sent_by_user_id');

        if ($lastOutboundUserId !== null) {
            return User::query()->whereKey((int) $lastOutboundUserId)->first();
        }

        if ($chat->assignments()->where('user_id', $actor->id)->exists()) {
            return $actor;
        }

        return $chat->assignments()->with('user')->first()?->user;
    }

    private function resolveCompanyId(Chat $chat, ?User $responder, User $actor, mixed $requestedCompanyId): ?int
    {
        if ($requestedCompanyId !== null && Company::query()->whereKey((int) $requestedCompanyId)->exists()) {
            return (int) $requestedCompanyId;
        }

        return $chat->company_id
            ?? $responder?->company_id
            ?? $actor->company_id
            ?? Company::query()->orderBy('id')->value('id');
    }

    private function dispatchReplyForLatestUnansweredInbound(Chat $chat): void
    {
        $latest = Message::query()
            ->where('chat_id', $chat->id)
            ->whereIn('direction', ['inbound', 'outbound'])
            ->orderByDesc('message_timestamp')
            ->orderByDesc('id')
            ->first(['id', 'direction']);

        if ($latest === null || $latest->direction !== 'inbound') {
            return;
        }

        $mode = $chat->ai_mode === 'draft' ? 'draft' : 'auto';
        if (AiResponseLog::query()->where('trigger_message_id', $latest->id)->where('mode', $mode)->exists()) {
            return;
        }

        GenerateAiReplyJob::dispatch($chat->id, $latest->id);
    }
}
