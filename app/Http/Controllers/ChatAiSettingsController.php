<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Events\ChatAiOrchestratorUpdated;
use App\Jobs\AnalyzeCompanyToneProfileJob;
use App\Jobs\AnalyzeEmployeeToneProfileJob;
use App\Models\AiResponseLog;
use App\Models\Chat;
use App\Models\Message;
use App\Models\User;
use App\Services\AI\AiReadinessService;
use App\Services\AI\AiResponderResolver;
use App\Services\AI\ChatIdleAiReplyService;
use App\Support\TenantCompany;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final class ChatAiSettingsController extends Controller
{
    public function __construct(
        private readonly AiReadinessService $readinessService,
        private readonly AiResponderResolver $responderResolver,
        private readonly ChatIdleAiReplyService $idleAiReply,
    ) {}

    public function update(Request $request, Chat $chat): JsonResponse
    {
        $chat = $this->applyUpdate($request, $chat, forApi: false);

        return response()->json([
            'success' => true,
            'chat' => $chat,
        ]);
    }

    public function updateForApi(Request $request, Chat $chat): JsonResponse
    {
        $chat = $this->applyUpdate($request, $chat, forApi: true);

        broadcast(new ChatAiOrchestratorUpdated($chat->id, [
            'ai_enabled' => (bool) $chat->ai_enabled,
            'ai_mode' => (string) $chat->ai_mode,
            'ai_responder_user_id' => $chat->ai_responder_user_id,
        ]));

        return response()->json([
            'data' => $this->apiChatAiPayload($chat),
            'requires_confirmation' => false,
            'warnings' => [],
        ]);
    }

    private function applyUpdate(Request $request, Chat $chat, bool $forApi = false): Chat
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
        abort_unless($user !== null, 403);

        $responder = $this->resolveResponder($chat, $user, $validated['ai_responder_user_id'] ?? null)
            ?? $this->responderResolver->forChat($chat, $chat->funnel?->aiScenario);
        $companyId = $this->resolveCompanyId();

        $aiEnabled = (bool) $validated['ai_enabled'];
        $wasEnabled = (bool) $chat->ai_enabled;

        if ($aiEnabled && ! $wasEnabled && ! (bool) ($validated['confirm_risky_enable'] ?? false)) {
            $warnings = $this->enableWarnings($chat, $responder);
            if ($warnings !== []) {
                $payload = [
                    'message' => 'Перед включением AI проверьте готовность системы.',
                    'requires_confirmation' => true,
                    'warnings' => $warnings,
                    'readiness' => $this->readinessService->evaluate($companyId),
                ];
                if (! $forApi) {
                    $payload['success'] = false;
                    $payload['settings_url'] = route('settings.ai-quality');
                }
                throw new HttpResponseException(response()->json($payload, 422));
            }
        }

        $chat->loadMissing('assignments.user');

        $chat->forceFill([
            'ai_enabled' => $aiEnabled,
            'ai_mode' => (string) ($validated['ai_mode'] ?? $chat->ai_mode ?? 'auto'),
            'ai_responder_user_id' => $chat->hasManualAssignees() ? $responder?->id : null,
            'company_id' => $companyId,
        ])->save();

        if ($chat->ai_enabled && $responder !== null && $companyId !== null) {
            AnalyzeCompanyToneProfileJob::dispatch($companyId);
            AnalyzeEmployeeToneProfileJob::dispatch($responder->id, $companyId, $chat->id);
        }

        if ($chat->ai_enabled) {
            $this->dispatchReplyForLatestUnansweredInbound($chat);
        }

        $chat->load(['assignments.user', 'departments', 'aiResponder:id,name', 'funnel:id,name,color', 'funnelStage:id,name,color']);
        $chat->setAttribute('can_manage_ai', $user->can('manageAi', $chat));

        return $chat;
    }

    /**
     * @return array<string, mixed>
     */
    private function apiChatAiPayload(Chat $chat): array
    {
        return [
            'id' => $chat->id,
            'ai_enabled' => (bool) $chat->ai_enabled,
            'ai_mode' => (string) $chat->ai_mode,
            'ai_responder_user_id' => $chat->ai_responder_user_id,
            'contact_id' => $chat->contact_id,
            'funnel_id' => $chat->funnel_id,
            'funnel_stage_id' => $chat->funnel_stage_id,
        ];
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

        return $chat->assignments()->with('user')->first()?->user
            ?? $this->responderResolver->forChat($chat, $chat->funnel?->aiScenario);
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

        $this->idleAiReply->dispatchGenerateReply($chat, $latest->id, immediate: true);
    }
}
