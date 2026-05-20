<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\BroadcastCampaign;
use App\Models\User;
use App\Models\WhatsappSession;
use App\Services\Broadcast\BroadcastCampaignService;
use App\Services\Broadcast\BroadcastSendRateLimiter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

final class BroadcastController extends Controller
{
    public function index(Request $request): Response
    {
        $this->authorizeBroadcast($request);

        $user = $request->user();
        $sessions = $this->whatsappSessionsFor($user);
        $senders = $this->senderOptionsFor($user);

        $campaigns = BroadcastCampaign::query()
            ->with(['whatsappSession:id,display_name,phone_number', 'sender:id,name', 'createdBy:id,name'])
            ->latest('id')
            ->limit(20)
            ->get()
            ->map(fn (BroadcastCampaign $c) => $this->campaignPayload($c));

        $rateLimiter = app(BroadcastSendRateLimiter::class);
        $defaultSessionId = $sessions[0]['id'] ?? null;
        $rateLimit = $defaultSessionId !== null
            ? $rateLimiter->snapshot((int) $defaultSessionId)
            : [
                'max_per_hour' => BroadcastSendRateLimiter::MAX_MESSAGES_PER_HOUR,
                'delay_seconds' => $rateLimiter->delayBetweenMessages(),
                'sent_last_hour' => 0,
                'remaining' => BroadcastSendRateLimiter::MAX_MESSAGES_PER_HOUR,
            ];

        return Inertia::render('Broadcasts/Index', [
            'sessions' => $sessions,
            'senders' => $senders,
            'campaigns' => $campaigns,
            'rateLimit' => $rateLimit,
        ]);
    }

    public function preview(Request $request, BroadcastCampaignService $service): JsonResponse
    {
        $this->authorizeBroadcast($request);

        $data = $request->validate([
            'source' => ['required', 'string', 'in:excel,filters'],
            'whatsapp_session_id' => ['required', 'integer', 'exists:whatsapp_sessions,id'],
            'file' => ['nullable', 'file', 'max:10240'],
            'filters' => ['nullable', 'array'],
            'filters.search' => ['nullable', 'string', 'max:200'],
            'filters.company_name' => ['nullable', 'string', 'max:200'],
            'filter_message' => ['nullable', 'string', 'max:4000'],
        ]);

        $session = WhatsappSession::query()->findOrFail((int) $data['whatsapp_session_id']);
        abort_unless($request->user()->can('use', $session), 403);

        $result = $data['source'] === 'excel'
            ? $service->previewFromExcel($request->file('file'), $request->user(), $session)
            : $service->previewFromFilters(
                is_array($data['filters'] ?? null) ? $data['filters'] : [],
                (string) ($data['filter_message'] ?? ''),
                $request->user(),
                $session,
            );

        return response()->json($result);
    }

    public function store(Request $request, BroadcastCampaignService $service): JsonResponse
    {
        $this->authorizeBroadcast($request);

        $data = $request->validate([
            'source' => ['required', 'string', 'in:excel,filters'],
            'whatsapp_session_id' => ['required', 'integer', 'exists:whatsapp_sessions,id'],
            'sender_user_id' => ['required', 'integer', 'exists:users,id'],
            'file' => ['nullable', 'file', 'max:10240'],
            'filters' => ['nullable', 'array'],
            'filters.search' => ['nullable', 'string', 'max:200'],
            'filters.company_name' => ['nullable', 'string', 'max:200'],
            'filter_message' => ['nullable', 'string', 'max:4000'],
        ]);

        $session = WhatsappSession::query()->findOrFail((int) $data['whatsapp_session_id']);
        abort_unless($request->user()->can('use', $session), 403);

        $sender = User::query()->findOrFail((int) $data['sender_user_id']);
        abort_unless($this->canPickSender($request->user(), $sender), 403);

        $campaign = $service->start(
            creator: $request->user(),
            sender: $sender,
            session: $session,
            source: $data['source'] === 'excel' ? BroadcastCampaign::SOURCE_EXCEL : BroadcastCampaign::SOURCE_FILTERS,
            file: $request->file('file'),
            filters: is_array($data['filters'] ?? null) ? $data['filters'] : [],
            filterMessage: $data['filter_message'] ?? null,
            previewRows: null,
        );

        return response()->json([
            'campaign' => $this->campaignPayload($campaign),
        ]);
    }

    public function show(Request $request, BroadcastCampaign $campaign): JsonResponse
    {
        $this->authorizeBroadcast($request);

        $campaign->load(['whatsappSession:id,display_name,phone_number', 'sender:id,name']);

        $items = $campaign->items()
            ->orderBy('row_number')
            ->limit(200)
            ->get(['id', 'row_number', 'phone_raw', 'status', 'skip_reason', 'error', 'contact_id', 'chat_id', 'processed_at']);

        return response()->json([
            'campaign' => $this->campaignPayload($campaign),
            'items' => $items,
        ]);
    }

    private function authorizeBroadcast(Request $request): void
    {
        $user = $request->user();
        abort_unless(
            $user && $user->hasAnyRole(['administrator', 'manager']),
            403,
            'Рассылки доступны администратору и руководителю.',
        );
    }

    /** @return list<array<string, mixed>> */
    private function whatsappSessionsFor(User $user): array
    {
        $query = WhatsappSession::query()->where('is_active', true);
        if (! $user->hasRole('administrator')) {
            $query->whereIn(
                'id',
                $user->whatsappSessions()->pluck('whatsapp_sessions.id'),
            );
        }

        return $query
            ->orderBy('display_name')
            ->get(['id', 'session_name', 'display_name', 'phone_number', 'status'])
            ->map(fn (WhatsappSession $s) => [
                'id' => $s->id,
                'label' => trim((string) ($s->display_name ?: $s->phone_number ?: $s->session_name)),
                'phone_number' => $s->phone_number,
                'status' => $s->status,
            ])
            ->values()
            ->all();
    }

    /** @return list<array{id: int, name: string}> */
    private function senderOptionsFor(User $user): array
    {
        if ($user->hasRole('administrator')) {
            return User::query()
                ->where('is_active', true)
                ->orderBy('name')
                ->get(['id', 'name'])
                ->map(fn (User $u) => ['id' => $u->id, 'name' => $u->name])
                ->values()
                ->all();
        }

        return [['id' => $user->id, 'name' => $user->name]];
    }

    private function canPickSender(User $actor, User $sender): bool
    {
        if ($actor->hasRole('administrator')) {
            return true;
        }

        return $actor->id === $sender->id;
    }

    /** @return array<string, mixed> */
    private function campaignPayload(BroadcastCampaign $campaign): array
    {
        return [
            'id' => $campaign->id,
            'status' => $campaign->status,
            'source' => $campaign->source,
            'delay_seconds' => $campaign->delay_seconds,
            'total_rows' => $campaign->total_rows,
            'ready_count' => $campaign->ready_count,
            'sent_count' => $campaign->sent_count,
            'skipped_count' => $campaign->skipped_count,
            'failed_count' => $campaign->failed_count,
            'started_at' => $campaign->started_at?->toIso8601String(),
            'completed_at' => $campaign->completed_at?->toIso8601String(),
            'session' => $campaign->whatsappSession ? [
                'id' => $campaign->whatsappSession->id,
                'label' => trim((string) ($campaign->whatsappSession->display_name ?: $campaign->whatsappSession->phone_number)),
            ] : null,
            'sender' => $campaign->sender ? [
                'id' => $campaign->sender->id,
                'name' => $campaign->sender->name,
            ] : null,
            'created_by' => $campaign->createdBy ? [
                'id' => $campaign->createdBy->id,
                'name' => $campaign->createdBy->name,
            ] : null,
        ];
    }
}
