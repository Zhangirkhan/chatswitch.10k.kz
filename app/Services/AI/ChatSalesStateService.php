<?php

declare(strict_types=1);

namespace App\Services\AI;

use App\Enums\EntityMemorySubjectType;
use App\Models\Chat;
use App\Models\Message;
use App\Services\AI\Orchestrator\ClientMessageIntentDetector;
use App\Services\Memory\EntityMemoryService;
use App\Support\AiFeatureFlags;
use App\Support\MessageInboundText;
use App\Models\SalesMilestone;
use Illuminate\Support\Facades\Log;

/**
 * Maintains a lightweight, deterministic sales state on each chat.
 *
 * The state is derived from EntityMemory AI-facts + the current funnel stage
 * and stored as a JSON column (`chats.sales_state`). It is updated after each
 * memory extraction turn so the orchestrator planner and PromptBuilder always
 * have a current structured view of the lead without another LLM call.
 *
 * State shape:
 * {
 *   "qualified":       bool,           // budget known AND requirements non-empty
 *   "budget_known":    bool,           // budget fact exists in memory
 *   "requirements_known": bool,        // requirements fact non-empty
 *   "objections_open": string[],       // parsed from objections fact
 *   "agreements":      string|null,    // latest agreement summary
 *   "missing_fields":  string[],       // high-value missing fields for qualification
 *   "next_action":     string          // suggested next step for the agent
 * }
 *
 * next_action values: qualify | ask_budget | ask_requirements | present_offer |
 *                     handle_objection | book_appointment | confirm_deal | follow_up
 *
 * This is purely deterministic — no LLM calls. It is cheap to compute and
 * safe to call after every turn.
 */
final class ChatSalesStateService
{
    public function __construct(
        private readonly ClientMessageIntentDetector $intentDetector,
        private readonly EntityMemoryService $entityMemory,
        private readonly ChatLeadScoreService $leadScoreService,
        private readonly ChatNurtureSequenceService $nurtureSequence,
        private readonly SalesMilestoneRecorder $milestoneRecorder,
        private readonly PlaybookSelector $playbookSelector,
    ) {}

    /**
     * Next action constants — used in planner prompt context.
     */
    public const NA_QUALIFY          = 'qualify';
    public const NA_ASK_BUDGET       = 'ask_budget';
    public const NA_ASK_REQUIREMENTS = 'ask_requirements';
    public const NA_PRESENT_OFFER    = 'present_offer';
    public const NA_HANDLE_OBJECTION = 'handle_objection';
    public const NA_BOOK_APPOINTMENT = 'book_appointment';
    public const NA_CONFIRM_DEAL     = 'confirm_deal';
    public const NA_FOLLOW_UP        = 'follow_up';

    /**
     * Return the current sales state for the chat, guaranteed fresh.
     *
     * If sales_state on the chat is null or was last updated more than
     * $maxAgeSeconds ago (default 60s), this method reads current EntityMemory
     * facts synchronously and computes the state inline — no DB write.
     *
     * This solves the race condition between the 30-second ExtractConversationMemoryJob
     * debounce and the 3-second orchestrator trigger: the planner always gets a
     * meaningful sales_state even before the extraction job has run.
     *
     * @return array<string, mixed>
     */
    public function freshState(Chat $chat, int $maxAgeSeconds = 60): array
    {
        $stored = $chat->sales_state;
        $updatedAt = $chat->sales_state_updated_at;

        $isStale = $stored === null
            || $stored === []
            || $updatedAt === null
            || $updatedAt->diffInSeconds(now()) > $maxAgeSeconds;

        if (! $isStale) {
            return (array) $stored;
        }

        // Read current EntityMemory facts (contact-scoped for best coverage).
        $contactId = $chat->contact_id;
        $facts = $contactId !== null
            ? $this->entityMemory->readAiFacts(EntityMemorySubjectType::Contact, $contactId)
            : [];

        // Preserve any deferral flag set by ProcessWhatsappInboundJob this turn.
        $preserveDeferral = is_array($stored) && ($stored['deferral_detected'] ?? false) === true;

        $computed = $this->compute($chat, $facts);

        if ($preserveDeferral) {
            $computed['deferral_detected'] = true;
            $computed['next_action'] = self::NA_FOLLOW_UP;
        }

        return $computed;
    }

    /**
     * Compute and persist the sales state from the given AI-facts.
     * Safe to call even when $facts is empty (will compute from prior state).
     *
     * @param  array<string, string>  $facts  from EntityMemoryService::readAiFacts
     */
    public function updateFromFacts(Chat $chat, array $facts): void
    {
        $state = $this->compute($chat, $facts);

        // Only write when something changed to avoid noise.
        $current = $chat->sales_state ?? [];
        if ($current === $state) {
            return;
        }

        $this->milestoneRecorder->recordStateTransitions(
            $chat,
            is_array($current) ? $current : [],
            $state,
            SalesMilestone::SOURCE_AI,
        );

        $chat->forceFill([
            'sales_state' => $state,
            'sales_state_updated_at' => now(),
        ])->save();

        app(WinProbabilityService::class)->compute($chat->fresh() ?? $chat);
        app(NextBestActionEngine::class)->compute($chat->fresh() ?? $chat);

        Log::debug('[sales-state] updated', [
            'chat_id' => $chat->id,
            'state' => $state,
        ]);
    }

    /**
     * Mark the chat as deferred when the client sends «подумаем», «позже», etc.
     * Sets next_action = follow_up and records deferral_detected = true in sales_state.
     * Safe to call on every inbound message; no-ops when not a deferral.
     */
    public function applyDeferralFromMessage(Chat $chat, Message $message): void
    {
        $body = trim(MessageInboundText::forMessage($message));
        if ($body === '' || ! $this->intentDetector->isDeferral($body)) {
            return;
        }

        $state = is_array($chat->sales_state) ? $chat->sales_state : [];

        // Already marked as deferred and next_action is follow_up — nothing to do.
        if (($state['deferral_detected'] ?? false) === true
            && ($state['next_action'] ?? '') === self::NA_FOLLOW_UP
        ) {
            return;
        }

        $state['next_action']       = self::NA_FOLLOW_UP;
        $state['deferral_detected'] = true;
        $state['deferral_at']       = now()->format('Y-m-d H:i');

        $chat->forceFill([
            'sales_state'            => $state,
            'sales_state_updated_at' => now(),
        ])->save();

        Log::info('[sales-state] deferral detected', [
            'chat_id' => $chat->id,
            'body'    => mb_substr($body, 0, 80),
        ]);

        $this->milestoneRecorder->record(
            $chat,
            SalesMilestone::MILESTONE_DEFERRAL,
            SalesMilestone::SOURCE_AI,
            (int) $message->id,
        );

        $this->nurtureSequence->startFromDeferral($chat->fresh() ?? $chat, $message);
    }

    /**
     * Clear the deferral flag once the client re-engages with a substantive message.
     */
    public function clearDeferralFromMessage(Chat $chat, Message $message): void
    {
        $state = $chat->sales_state;
        if (! is_array($state) || ($state['deferral_detected'] ?? false) !== true) {
            return;
        }

        $body = trim(MessageInboundText::forMessage($message));
        if ($body === '' || $this->intentDetector->isDeferral($body)) {
            return;
        }

        // Client sent something substantive — clear the deferral so next_action can be re-derived.
        unset($state['deferral_detected'], $state['deferral_at']);
        // Reset next_action so it gets re-derived on next updateFromFacts.
        unset($state['next_action']);

        $chat->forceFill([
            'sales_state'            => $state !== [] ? $state : null,
            'sales_state_updated_at' => now(),
        ])->save();

        $this->nurtureSequence->cancelForChat($chat, 're_engaged');

        $this->milestoneRecorder->record(
            $chat,
            SalesMilestone::MILESTONE_RE_ENGAGED,
            SalesMilestone::SOURCE_AI,
            (int) $message->id,
        );
    }

    /**
     * Fresh sales cycle after a repeat order — drop stale qualification from the prior deal.
     */
    public function resetForNewOrderCycle(Chat $chat): void
    {
        $chat->forceFill([
            'sales_state' => [
                'qualified' => false,
                'budget_known' => false,
                'requirements_known' => false,
                'objections_open' => [],
                'agreements' => null,
                'missing_fields' => ['requirements', 'quantity'],
                'next_action' => self::NA_ASK_REQUIREMENTS,
                'repeat_order_cycle' => true,
            ],
            'sales_state_updated_at' => now(),
        ])->save();

        $this->nurtureSequence->cancelForChat($chat, 'repeat_order');
    }

    /**
     * Return a human-readable summary of the current sales state for prompt injection.
     * Returns empty string when no meaningful state exists yet.
     */
    public function promptSummary(Chat $chat): string
    {
        return $this->promptSummaryFromState($chat, $chat->sales_state ?? []);
    }

    /**
     * Same as promptSummary but works from a pre-computed state array (e.g. freshState).
     *
     * @param  array<string, mixed>  $state
     */
    public function promptSummaryFromState(Chat $chat, array $state): string
    {
        if ($state === []) {
            return '';
        }

        $lines = [];

        if (($state['qualified'] ?? false) === true) {
            $lines[] = 'Лид квалифицирован (бюджет и потребности известны).';
        } elseif (($state['budget_known'] ?? false) === false || ($state['requirements_known'] ?? false) === false) {
            $missing = $state['missing_fields'] ?? [];
            if ($missing !== []) {
                $lines[] = 'Ещё не выяснено: '.implode(', ', $missing).'.';
            }
        }

        $agreements = $state['agreements'] ?? null;
        if ($agreements !== null && $agreements !== '') {
            $lines[] = 'Договорённости: '.\Illuminate\Support\Str::limit($agreements, 120, '…');
        }

        $objections = $state['objections_open'] ?? [];
        if ($objections !== []) {
            $lines[] = 'Открытые возражения: '.implode('; ', array_slice($objections, 0, 3)).'.';
        }

        $grade = $state['grade'] ?? null;
        $score = $state['score'] ?? null;
        if ($grade !== null && $score !== null) {
            $lines[] = "Качество лида: {$grade} ({$score}/100).";
        }

        $nextAction = $state['next_action'] ?? null;
        if ($nextAction !== null && $nextAction !== '') {
            $actionLabels = [
                self::NA_QUALIFY          => 'Задать квалификационный вопрос',
                self::NA_ASK_BUDGET       => 'Уточнить бюджет',
                self::NA_ASK_REQUIREMENTS => 'Уточнить требования',
                self::NA_PRESENT_OFFER    => 'Сделать предложение',
                self::NA_HANDLE_OBJECTION => 'Обработать возражение',
                self::NA_BOOK_APPOINTMENT => 'Записать / назначить встречу',
                self::NA_CONFIRM_DEAL     => 'Закрыть сделку',
                self::NA_FOLLOW_UP        => 'Напомнить / уточнить статус',
            ];
            $label = $actionLabels[$nextAction] ?? $nextAction;
            $lines[] = "Рекомендуемый шаг: {$label}.";
        }

        if ($lines === []) {
            return '';
        }

        return "Статус продажи:\n".implode("\n", $lines);
    }

    /**
     * Compute the state from facts + funnel stage (no DB writes).
     *
     * @param  array<string, string>  $facts
     * @return array<string, mixed>
     */
    public function compute(Chat $chat, array $facts): array
    {
        $budget           = trim($facts['budget'] ?? '');
        $requirements     = trim($facts['requirements'] ?? '');
        $timeline         = trim($facts['timeline'] ?? '');
        $decisionMaker    = trim($facts['decision_maker'] ?? '');
        $objectionsFact   = trim($facts['objections'] ?? '');
        $agreements       = trim($facts['agreements'] ?? '');

        $budgetKnown          = $budget !== '';
        $requirementsKnown    = $requirements !== '';
        $timelineKnown        = $timeline !== '';
        $decisionMakerKnown   = $decisionMaker !== '';
        // Qualified = at minimum budget AND requirements are known.
        // timeline and decision_maker are tracked in missing_fields but don't gate qualification.
        $qualified            = $budgetKnown && $requirementsKnown;

        // Parse comma/semicolon separated objections into a list.
        $objectionsOpen = [];
        if ($objectionsFact !== '') {
            $objectionsOpen = array_values(array_filter(
                array_map('trim', preg_split('/[;,]+/', $objectionsFact) ?: []),
                fn (string $s): bool => $s !== '',
            ));
        }

        // Derive missing fields for qualification (shown in prompts and sales_state).
        $missingFields = [];
        if (! $budgetKnown) {
            $missingFields[] = 'бюджет';
        }
        if (! $requirementsKnown) {
            $missingFields[] = 'требования';
        }
        if (! $timelineKnown) {
            $missingFields[] = 'срок';
        }
        if (! $decisionMakerKnown) {
            $missingFields[] = 'кто решает';
        }

        // Determine next action.
        $existingState = is_array($chat->sales_state) ? $chat->sales_state : [];
        $deferralDetected = ($existingState['deferral_detected'] ?? false) === true;

        $nextAction = $this->deriveNextAction(
            $chat,
            $budgetKnown,
            $requirementsKnown,
            $qualified,
            $objectionsOpen,
            $agreements,
            $timelineKnown,
            $decisionMakerKnown,
        );

        $partial = [
            'qualified'            => $qualified,
            'budget_known'         => $budgetKnown,
            'requirements_known'   => $requirementsKnown,
            'timeline_known'       => $timelineKnown,
            'decision_maker_known' => $decisionMakerKnown,
            'objections_open'      => $objectionsOpen,
            'agreements'           => $agreements !== '' ? $agreements : null,
            'missing_fields'       => $missingFields,
            'next_action'          => $nextAction,
            'deferral_detected'    => $deferralDetected,
        ];

        if (AiFeatureFlags::enabled(AiFeatureFlags::LEAD_SCORING, $chat->company_id)) {
            $scored = $this->leadScoreService->score($chat, $partial, $facts);
            $partial['score'] = $scored['score'];
            $partial['grade'] = $scored['grade'];
            $partial['score_factors'] = $scored['score_factors'];
        }

        return $partial;
    }

    /**
     * @param  list<string>  $objectionsOpen
     */
    private function deriveNextAction(
        Chat $chat,
        bool $budgetKnown,
        bool $requirementsKnown,
        bool $qualified,
        array $objectionsOpen,
        string $agreements,
        bool $timelineKnown = false,
        bool $decisionMakerKnown = false,
    ): string {
        // If the current state already has deferral_detected, keep follow_up until re-engagement.
        $existingState = $chat->sales_state;
        if (is_array($existingState) && ($existingState['deferral_detected'] ?? false) === true) {
            return self::NA_FOLLOW_UP;
        }

        // Open objections take priority — address them first.
        if ($objectionsOpen !== []) {
            return self::NA_HANDLE_OBJECTION;
        }

        // Use funnel stage name as a signal.
        $stageName = mb_strtolower((string) ($chat->funnelStage?->name ?? $chat->funnel_stage_id ?? ''));

        if (str_contains($stageName, 'запис') || str_contains($stageName, 'замер') || str_contains($stageName, 'встреч')) {
            return self::NA_BOOK_APPOINTMENT;
        }

        if (str_contains($stageName, 'оплат') || str_contains($stageName, 'счёт') || str_contains($stageName, 'договор')) {
            return self::NA_CONFIRM_DEAL;
        }

        if (str_contains($stageName, 'предложен') || str_contains($stageName, 'коммерч') || str_contains($stageName, 'оффер')) {
            return self::NA_PRESENT_OFFER;
        }

        $playbook = $this->playbookSelector->resolveForChat($chat);
        foreach ($this->playbookSelector->qualificationFieldOrder($playbook) as $field) {
            $known = match ($field) {
                'budget' => $budgetKnown,
                'requirements' => $requirementsKnown,
                'timeline' => $timelineKnown,
                'decision_maker' => $decisionMakerKnown,
                default => true,
            };

            if ($known) {
                continue;
            }

            return match ($field) {
                'budget' => self::NA_ASK_BUDGET,
                'requirements' => self::NA_ASK_REQUIREMENTS,
                default => self::NA_QUALIFY,
            };
        }

        // Fall back to qualification gaps in BANT priority order.
        if (! $budgetKnown) {
            return self::NA_ASK_BUDGET;
        }

        if (! $requirementsKnown) {
            return self::NA_ASK_REQUIREMENTS;
        }

        if (! $qualified) {
            return self::NA_QUALIFY;
        }

        // Qualified but still missing BANT completeness fields (timeline, DM).
        if (! $timelineKnown || ! $decisionMakerKnown) {
            return self::NA_QUALIFY;
        }

        // Fully qualified with no open objections and no concrete stage signal.
        if ($agreements !== '') {
            return self::NA_CONFIRM_DEAL;
        }

        return self::NA_PRESENT_OFFER;
    }
}
