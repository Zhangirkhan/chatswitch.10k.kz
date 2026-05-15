<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Chat;
use App\Models\SystemSetting;
use App\Services\Funnel\ChatFunnelStateService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

final class ChatFunnelController extends Controller
{
    public function update(Request $request, Chat $chat, ChatFunnelStateService $state): JsonResponse
    {
        $this->authorize('manageFunnel', $chat);

        if (SystemSetting::getValue('module_funnels', 'on') !== 'on') {
            abort(404);
        }

        $validated = $request->validate([
            'funnel_id' => ['nullable', 'integer', 'exists:funnels,id'],
            'funnel_stage_id' => ['nullable', 'integer', 'exists:funnel_stages,id'],
            'funnel_tracking_enabled' => ['sometimes', 'boolean'],
            'funnel_stage_locked' => ['sometimes', 'boolean'],
        ]);

        $funnelId = $validated['funnel_id'] ?? null;
        $stageId = $validated['funnel_stage_id'] ?? null;
        if (($funnelId === null) !== ($stageId === null)) {
            throw ValidationException::withMessages([
                'funnel_id' => 'Укажите воронку и этап вместе или очистите оба поля.',
            ]);
        }

        $state->applyManual($chat, $validated, $request->user());

        $chat->refresh()->load(['funnel', 'funnelStage', 'departments']);

        foreach ($state->inertiaExtras($chat) as $key => $value) {
            $chat->setAttribute($key, $value);
        }

        return response()->json([
            'success' => true,
            'chat' => $chat,
            'funnel_catalog' => $state->catalogForClient($chat),
        ]);
    }

    public function history(Request $request, Chat $chat): JsonResponse
    {
        $this->authorize('manageFunnel', $chat);

        if (SystemSetting::getValue('module_funnels', 'on') !== 'on') {
            abort(404);
        }

        $items = $chat->funnelTransitions()
            ->orderByDesc('created_at')
            ->limit(50)
            ->get()
            ->map(static function ($row): array {
                return [
                    'id' => $row->id,
                    'from_funnel_id' => $row->from_funnel_id,
                    'from_stage_id' => $row->from_stage_id,
                    'to_funnel_id' => $row->to_funnel_id,
                    'to_stage_id' => $row->to_stage_id,
                    'source' => $row->source,
                    'confidence' => $row->confidence,
                    'reason' => $row->reason,
                    'trigger_message_id' => $row->trigger_message_id,
                    'created_at' => $row->created_at?->toIso8601String(),
                ];
            });

        return response()->json(['data' => $items]);
    }
}
