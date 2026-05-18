<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Jobs\AnalyzeCompanyToneProfileJob;
use App\Jobs\AnalyzeEmployeeToneProfileJob;
use App\Jobs\GenerateAiReplyJob;
use App\Models\AiResponseLog;
use App\Models\Chat;
use App\Models\Message;
use App\Models\User;
use App\Services\AI\AiReadinessService;
use App\Support\TenantCompany;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final class ChatAiSettingsController extends Controller
{
    public function __construct(
        private readonly AiReadinessService $readinessService,
    ) {}

    public function update(Request $request, Chat $chat): JsonResponse
    {
        $this->authorize('manageAi', $chat);

        $validated = $request->validate([
            'ai_enabled' => ['required', 'boolean'],
            'ai_mode' => ['nullable', Rule::in(['auto', 'draft'])],
            'ai_responder_user_id' => ['nullable', 'integer', 'exists:users,id'],
            'company_id' => ['nullable', 'integer', 'exists:companies,id'],
            'confirm_risky_enable' => ['sometimes', 'boolean'],
        ]);

        $user = $request->user();
        $responder = $this->resolveResponder($chat, $user, $validated['ai_responder_user_id'] ?? null);
        $companyId = $this->resolveCompanyId();

        $aiEnabled = (bool) $validated['ai_enabled'];
        $wasEnabled = (bool) $chat->ai_enabled;

        if ($aiEnabled && ! $wasEnabled && ! (bool) ($validated['confirm_risky_enable'] ?? false)) {
            $warnings = $this->enableWarnings($chat, $responder);
            if ($warnings !== []) {
                return response()->json([
                    'success' => false,
                    'requires_confirmation' => true,
                    'message' => 'Перед включением AI проверьте готовность системы.',
                    'warnings' => $warnings,
                    'readiness' => $this->readinessService->evaluate($companyId),
                    'settings_url' => route('settings.ai-quality'),
                ], 422);
            }
        }

        $chat->forceFill([
            'ai_enabled' => $aiEnabled,
            'ai_mode' => (string) ($validated['ai_mode'] ?? $chat->ai_mode ?? 'auto'),
            'ai_responder_user_id' => $responder?->id,
            'company_id' => $companyId,
        ])->save();

        if ($chat->ai_enabled && $responder !== null && $companyId !== null) {
            AnalyzeCompanyToneProfileJob::dispatch($companyId);
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

    /**
     * @return list<string>
     */
    private function enableWarnings(Chat $chat, ?User $responder): array
    {
        $warnings = $this->readinessService->enableBlockers((int) $chat->company_id);

        if ($responder === null) {
            $warnings[] = 'Не выбран ответственный сотрудник, от имени которого AI будет отвечать.';
        }

        return array_values(array_unique($warnings));
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

    private function resolveCompanyId(): int
    {
        return TenantCompany::id();
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
