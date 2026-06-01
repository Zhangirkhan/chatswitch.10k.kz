<?php

declare(strict_types=1);

namespace App\Services\Marketing;

final class AiTokenCalculatorService
{
    public function __construct(
        private readonly NationalBankExchangeRateService $exchangeRate,
        private readonly AiCalculatorBenchmarkService $benchmarks,
    ) {}

    /**
     * @param  array<string, mixed>  $inputs
     * @return array{
     *     volumes: array<string, float>,
     *     inbound_per_month: int,
     *     ai_inbound_per_month: int,
     *     scenarios: list<array{
     *         id: string,
     *         label: string,
     *         description: string,
     *         type: string,
     *         calls: float,
     *         input_tokens: int,
     *         output_tokens: int,
     *         embedding_tokens: int,
     *         cost_usd: float,
     *         tokens_measured: bool
     *     }>,
     *     totals: array{
     *         input_tokens: int,
     *         output_tokens: int,
     *         embedding_tokens: int,
     *         whisper_minutes: float,
     *         api_cost_usd: float,
     *         api_cost_kzt: float,
     *         subscription_kzt: int
     *     }
     * }
     */
    public function calculate(array $inputs, ?array $benchmarkData = null): array
    {
        $defaults = config('ai_calculator.defaults', []);
        $merged = array_merge($defaults, $inputs);

        $leadsPerDay = max(1, (int) ($merged['leads_per_day'] ?? 30));
        $inboundMsgsPerLead = max(1, (int) ($merged['inbound_msgs_per_lead'] ?? 8));
        $aiReplyRate = $this->clampPercent($merged['ai_reply_rate'] ?? 70);
        $funnelEnabled = (bool) ($merged['funnel_enabled'] ?? true);
        $orchestratorRate = $this->clampPercent($merged['orchestrator_rate'] ?? 20);
        $voiceMsgRate = $this->clampPercent($merged['voice_msg_rate'] ?? 10);
        $avgVoiceDurationSec = max(5, min(120, (int) ($merged['avg_voice_duration_sec'] ?? 25)));
        $silentLeadsPerDay = max(0, (int) ($merged['silent_leads_per_day'] ?? 5));
        $operators = max(1, (int) ($merged['operators'] ?? 3));
        $operatorAiUsesPerDay = max(0, (int) ($merged['operator_ai_uses_per_day'] ?? 5));
        $translationsPerDay = max(0, (int) ($merged['translations_per_day'] ?? 2));
        $workspaceQueriesPerDay = max(0, (int) ($merged['workspace_queries_per_day'] ?? 1));
        $workDaysPerMonth = max(20, min(31, (int) ($merged['work_days_per_month'] ?? 22)));

        $benchmarkData ??= $this->benchmarks->benchmarks();
        $triggerRates = is_array($benchmarkData['trigger_rates'] ?? null) ? $benchmarkData['trigger_rates'] : [];
        $tokenEstimates = is_array($benchmarkData['token_estimates'] ?? null) ? $benchmarkData['token_estimates'] : [];

        if (isset($benchmarkData['avg_whisper_seconds']) && $benchmarkData['avg_whisper_seconds'] !== null) {
            $avgVoiceDurationSec = max(5, min(120, (int) round((float) $benchmarkData['avg_whisper_seconds'])));
        }

        $volumes = $this->volumes(
            $leadsPerDay,
            $inboundMsgsPerLead,
            $aiReplyRate,
            $funnelEnabled,
            $orchestratorRate,
            $voiceMsgRate,
            $avgVoiceDurationSec,
            $silentLeadsPerDay,
            $operators,
            $operatorAiUsesPerDay,
            $translationsPerDay,
            $workspaceQueriesPerDay,
            $workDaysPerMonth,
            $triggerRates,
        );

        $pricing = config('ai_calculator.pricing', []);
        $gptInputPer1m = (float) ($pricing['gpt-4o-mini']['input_per_1m'] ?? 0.15);
        $gptOutputPer1m = (float) ($pricing['gpt-4o-mini']['output_per_1m'] ?? 0.60);
        $embedPer1m = (float) ($pricing['text-embedding-3-small']['per_1m'] ?? 0.02);
        $whisperPerMin = (float) ($pricing['whisper']['per_minute'] ?? 0.006);
        $exchange = $this->exchangeRate->usdToKzt();
        $usdToKzt = (float) $exchange['rate'];
        $backgroundUsd = (float) config('ai_calculator.background_monthly_usd', 3.0);

        $scenarios = [];
        $totalInput = 0;
        $totalOutput = 0;
        $totalEmbed = 0;
        $totalUsd = 0.0;
        $whisperMinutes = 0.0;

        foreach (config('ai_calculator.scenarios', []) as $scenario) {
            if (! is_array($scenario)) {
                continue;
            }

            $scenarioId = (string) ($scenario['id'] ?? '');
            $volumeKey = (string) ($scenario['volume_key'] ?? '');
            $calls = (float) ($volumes[$volumeKey] ?? 0);
            $type = (string) ($scenario['type'] ?? 'chat');

            $estimate = is_array($tokenEstimates[$scenarioId] ?? null) ? $tokenEstimates[$scenarioId] : [];
            $inputPerCall = (int) ($estimate['input_tokens'] ?? $scenario['input_tokens'] ?? 0);
            $outputPerCall = (int) ($estimate['output_tokens'] ?? $scenario['output_tokens'] ?? 0);
            $tokensMeasured = (bool) ($estimate['measured'] ?? false);

            $inputTokens = 0;
            $outputTokens = 0;
            $embeddingTokens = 0;
            $costUsd = 0.0;

            if ($type === 'chat' && $calls > 0) {
                $inputTokens = (int) round($calls * $inputPerCall);
                $outputTokens = (int) round($calls * $outputPerCall);
                $costUsd = ($inputTokens / 1_000_000) * $gptInputPer1m
                    + ($outputTokens / 1_000_000) * $gptOutputPer1m;
            } elseif ($type === 'embedding' && $calls > 0) {
                $embeddingTokens = (int) round($calls * $inputPerCall);
                $costUsd = ($embeddingTokens / 1_000_000) * $embedPer1m;
            } elseif ($type === 'whisper' && $calls > 0) {
                $whisperMinutes = $calls;
                $costUsd = $whisperMinutes * $whisperPerMin;
            } elseif ($type === 'fixed_usd') {
                $calls = 1;
                $costUsd = $backgroundUsd;
            }

            $totalInput += $inputTokens;
            $totalOutput += $outputTokens;
            $totalEmbed += $embeddingTokens;
            $totalUsd += $costUsd;

            $scenarios[] = [
                'id' => $scenarioId,
                'label' => (string) ($scenario['label'] ?? ''),
                'description' => (string) ($scenario['description'] ?? ''),
                'type' => $type,
                'calls' => round($calls, 2),
                'input_tokens' => $inputTokens,
                'output_tokens' => $outputTokens,
                'embedding_tokens' => $embeddingTokens,
                'cost_usd' => round($costUsd, 4),
                'tokens_measured' => $tokensMeasured,
            ];
        }

        $apiCostUsd = round($totalUsd, 2);
        $apiCostKzt = round($apiCostUsd * $usdToKzt, 0);

        return [
            'volumes' => $volumes,
            'inbound_per_month' => (int) $volumes['inbound_per_month'],
            'ai_inbound_per_month' => (int) $volumes['ai_inbound_per_month'],
            'scenarios' => $scenarios,
            'totals' => [
                'input_tokens' => $totalInput,
                'output_tokens' => $totalOutput,
                'embedding_tokens' => $totalEmbed,
                'whisper_minutes' => round($whisperMinutes, 1),
                'api_cost_usd' => $apiCostUsd,
                'api_cost_kzt' => $apiCostKzt,
                'subscription_kzt' => (int) config('ai_calculator.subscription_kzt', 40_000),
            ],
        ];
    }

    /**
     * @param  array<string, float>  $triggerRates
     * @return array<string, float|int>
     */
    public function volumes(
        int $leadsPerDay,
        int $inboundMsgsPerLead,
        float $aiReplyRate,
        bool $funnelEnabled,
        float $orchestratorRate,
        float $voiceMsgRate,
        int $avgVoiceDurationSec,
        int $silentLeadsPerDay,
        int $operators,
        int $operatorAiUsesPerDay,
        int $translationsPerDay,
        int $workspaceQueriesPerDay,
        int $workDaysPerMonth,
        array $triggerRates = [],
    ): array {
        $inboundPerDay = $leadsPerDay * $inboundMsgsPerLead;
        $inboundPerMonth = $inboundPerDay * $workDaysPerMonth;
        $aiInboundPerMonth = (int) round($inboundPerMonth * ($aiReplyRate / 100));
        $orchFraction = $orchestratorRate / 100;

        $aiReply = $aiInboundPerMonth * (1 - $orchFraction);
        $orchestrator = $aiInboundPerMonth * $orchFraction;

        $voiceMsgsPerMonth = $inboundPerMonth * ($voiceMsgRate / 100);
        $whisperMinutes = ($voiceMsgsPerMonth * $avgVoiceDurationSec) / 60;

        $deptRate = (float) ($triggerRates['dept_routing'] ?? 0.30);
        $appointmentRate = (float) ($triggerRates['appointment_intent'] ?? 0.15);
        $historyRate = (float) ($triggerRates['history_compress'] ?? 0.10);
        $ragRate = (float) ($triggerRates['rag_embed'] ?? 2.0);
        $funnelClassifyRate = (float) ($triggerRates['funnel_classify'] ?? 1.0);
        $autoFollowUpRate = (float) ($triggerRates['auto_follow_up'] ?? 0.5);

        return [
            'inbound_per_month' => $inboundPerMonth,
            'ai_inbound_per_month' => $aiInboundPerMonth,
            'ai_reply' => $aiReply,
            'dept_routing' => $inboundPerMonth * $deptRate,
            'appointment_intent' => $aiInboundPerMonth * $appointmentRate,
            'history_compress' => $aiInboundPerMonth * $historyRate,
            'rag_embed' => $aiInboundPerMonth * $ragRate,
            'funnel_classify' => $funnelEnabled ? $inboundPerMonth * $funnelClassifyRate : 0.0,
            'funnel_orchestrator' => $orchestrator,
            'follow_up_proposal' => (float) ($silentLeadsPerDay * $workDaysPerMonth),
            'auto_follow_up' => (float) ($silentLeadsPerDay * $workDaysPerMonth * $autoFollowUpRate),
            'operator_assistant' => (float) ($operators * $operatorAiUsesPerDay * $workDaysPerMonth),
            'translation' => (float) ($translationsPerDay * $workDaysPerMonth),
            'workspace_query' => (float) ($operators * $workspaceQueriesPerDay * $workDaysPerMonth),
            'whisper_minutes' => $whisperMinutes,
            'background' => 1.0,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function payloadForFrontend(): array
    {
        $defaults = config('ai_calculator.defaults', []);
        $benchmarkData = $this->benchmarks->benchmarks();
        $exchange = $this->exchangeRate->usdToKzt();
        $result = $this->calculate($defaults, $benchmarkData);

        $scenarios = [];
        foreach (config('ai_calculator.scenarios', []) as $scenario) {
            if (! is_array($scenario)) {
                continue;
            }

            $id = (string) ($scenario['id'] ?? '');
            $estimate = is_array($benchmarkData['token_estimates'][$id] ?? null)
                ? $benchmarkData['token_estimates'][$id]
                : [];

            $scenarios[] = array_merge($scenario, [
                'input_tokens' => (int) ($estimate['input_tokens'] ?? $scenario['input_tokens'] ?? 0),
                'output_tokens' => (int) ($estimate['output_tokens'] ?? $scenario['output_tokens'] ?? 0),
                'tokens_measured' => (bool) ($estimate['measured'] ?? false),
            ]);
        }

        return [
            'model' => (string) config('ai_calculator.model', 'gpt-4o-mini'),
            'defaults' => $defaults,
            'presets' => config('ai_calculator.presets', []),
            'scenarios' => $scenarios,
            'pricing' => config('ai_calculator.pricing', []),
            'background_monthly_usd' => (float) config('ai_calculator.background_monthly_usd', 3.0),
            'subscription_kzt' => (int) config('ai_calculator.subscription_kzt', 40_000),
            'exchange_rate' => $exchange,
            'benchmarks' => [
                'period_days' => $benchmarkData['period_days'],
                'trigger_rates' => $benchmarkData['trigger_rates'],
                'has_measurements' => ($benchmarkData['inbound_messages'] ?? 0) > 0
                    || ($benchmarkData['ai_reply_events'] ?? 0) > 0,
            ],
            'initial' => $result,
        ];
    }

    private function clampPercent(mixed $value): float
    {
        return max(0.0, min(100.0, (float) $value));
    }
}
