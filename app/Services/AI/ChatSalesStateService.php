<?php

declare(strict_types=1);

namespace App\Services\AI;

use App\Models\Chat;
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

        $chat->forceFill([
            'sales_state' => $state,
            'sales_state_updated_at' => now(),
        ])->save();

        Log::debug('[sales-state] updated', [
            'chat_id' => $chat->id,
            'state' => $state,
        ]);
    }

    /**
     * Return a human-readable summary of the current sales state for prompt injection.
     * Returns empty string when no meaningful state exists yet.
     */
    public function promptSummary(Chat $chat): string
    {
        $state = $chat->sales_state;
        if (! is_array($state) || $state === []) {
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
        $budget       = trim($facts['budget'] ?? '');
        $requirements = trim($facts['requirements'] ?? '');
        $objectionsFact = trim($facts['objections'] ?? '');
        $agreements   = trim($facts['agreements'] ?? '');

        $budgetKnown       = $budget !== '';
        $requirementsKnown = $requirements !== '';
        $qualified         = $budgetKnown && $requirementsKnown;

        // Parse comma/semicolon separated objections into a list.
        $objectionsOpen = [];
        if ($objectionsFact !== '') {
            $objectionsOpen = array_values(array_filter(
                array_map('trim', preg_split('/[;,]+/', $objectionsFact) ?: []),
                fn (string $s): bool => $s !== '',
            ));
        }

        // Derive missing fields for qualification.
        $missingFields = [];
        if (! $budgetKnown) {
            $missingFields[] = 'бюджет';
        }
        if (! $requirementsKnown) {
            $missingFields[] = 'требования';
        }

        // Determine next action.
        $nextAction = $this->deriveNextAction(
            $chat,
            $budgetKnown,
            $requirementsKnown,
            $qualified,
            $objectionsOpen,
            $agreements,
        );

        return [
            'qualified'          => $qualified,
            'budget_known'       => $budgetKnown,
            'requirements_known' => $requirementsKnown,
            'objections_open'    => $objectionsOpen,
            'agreements'         => $agreements !== '' ? $agreements : null,
            'missing_fields'     => $missingFields,
            'next_action'        => $nextAction,
        ];
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
    ): string {
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

        // Fall back to qualification gaps.
        if (! $budgetKnown) {
            return self::NA_ASK_BUDGET;
        }

        if (! $requirementsKnown) {
            return self::NA_ASK_REQUIREMENTS;
        }

        if (! $qualified) {
            return self::NA_QUALIFY;
        }

        // Qualified with no open objections and no concrete stage signal.
        if ($agreements !== '') {
            return self::NA_CONFIRM_DEAL;
        }

        return self::NA_PRESENT_OFFER;
    }
}
