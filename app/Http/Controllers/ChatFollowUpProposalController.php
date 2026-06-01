<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\AiFollowUpProposal;
use App\Models\Chat;
use App\Services\Funnel\ConsultationFollowUpProposalService;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

final class ChatFollowUpProposalController extends Controller
{
    private const SCHEDULE_TZ = 'Asia/Almaty';

    public function __construct(
        private readonly ConsultationFollowUpProposalService $proposals,
    ) {}

    public function generate(Request $request, Chat $chat): JsonResponse
    {
        $user = $request->user();
        if ($user === null) {
            abort(403);
        }

        if (! $user->can('manageAi', $chat) && ! $user->hasAnyRole(['administrator', 'manager'])) {
            abort(403);
        }

        $this->authorize('view', $chat);

        try {
            $proposal = $this->proposals->proposeForChat($chat, $user);
        } catch (Throwable $e) {
            Log::warning('[follow-up-proposal] manual generate failed', [
                'chat_id' => $chat->id,
                'error' => $e->getMessage(),
            ]);

            return response()->json(['message' => 'Не удалось сгенерировать варианты дожима.'], 502);
        }

        if ($proposal === null) {
            return response()->json([
                'message' => 'Для этого чата нельзя сгенерировать предложения (проверьте этап воронки и стратегию дожима).',
            ], 422);
        }

        return response()->json([
            'success' => true,
            'proposal' => $this->serializeProposal($proposal),
        ]);
    }

    public function send(Request $request, Chat $chat, AiFollowUpProposal $proposal): JsonResponse
    {
        $user = $request->user();
        if ($user === null) {
            abort(403);
        }

        $this->authorize('sendMessage', $chat);

        if ((int) $proposal->chat_id !== (int) $chat->id) {
            abort(404);
        }

        $data = $request->validate([
            'variant_id' => ['required', 'string', 'max:64'],
            'body' => ['nullable', 'string', 'max:4000'],
            'scheduled_at' => ['nullable', 'date'],
        ]);

        $scheduleAt = null;
        if (! empty($data['scheduled_at'])) {
            $scheduleAt = CarbonImmutable::parse((string) $data['scheduled_at'], self::SCHEDULE_TZ);
        }

        try {
            $result = $this->proposals->sendVariant(
                $proposal,
                $user,
                (string) $data['variant_id'],
                isset($data['body']) ? (string) $data['body'] : null,
                $scheduleAt,
            );
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        } catch (Throwable $e) {
            Log::warning('[follow-up-proposal] send failed', [
                'chat_id' => $chat->id,
                'proposal_id' => $proposal->id,
                'error' => $e->getMessage(),
            ]);

            return response()->json(['message' => 'Не удалось отправить сообщение.'], 502);
        }

        return response()->json([
            'success' => true,
            'message' => $result['message']->id ? $result['message'] : null,
            'proposal' => $this->serializeProposal($result['proposal']),
            'pending_follow_up_proposal' => null,
        ]);
    }

    public function dismiss(Request $request, Chat $chat, AiFollowUpProposal $proposal): JsonResponse
    {
        $user = $request->user();
        if ($user === null) {
            abort(403);
        }

        $this->authorize('view', $chat);

        if ((int) $proposal->chat_id !== (int) $chat->id) {
            abort(404);
        }

        $this->proposals->dismiss($proposal);

        return response()->json([
            'success' => true,
            'pending_follow_up_proposal' => null,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public static function serializeProposal(AiFollowUpProposal $proposal): array
    {
        return [
            'id' => $proposal->id,
            'status' => $proposal->status,
            'proposals' => $proposal->proposals ?? [],
            'recommended_id' => $proposal->recommended_id,
            'manager_note' => $proposal->manager_note,
            'context_summary' => $proposal->context_summary,
            'created_at' => $proposal->created_at?->toIso8601String(),
        ];
    }
}
