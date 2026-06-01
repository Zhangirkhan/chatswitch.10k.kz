<?php

declare(strict_types=1);

namespace App\Services\Marketing;

use App\Models\AiUsageEvent;
use App\Models\Message;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

final class AiCalculatorBenchmarkService
{
    /**
     * @return array{
     *     period_days: int,
     *     token_estimates: array<string, array{input_tokens: int, output_tokens: int, measured: bool, samples: int}>,
     *     trigger_rates: array<string, float>,
     *     avg_whisper_seconds: float|null,
     *     inbound_messages: int,
     *     ai_reply_events: int
     * }
     */
    public function benchmarks(): array
    {
        $days = max(7, (int) config('ai_calculator.benchmark.days', 30));
        $minSamples = max(10, (int) config('ai_calculator.benchmark.min_samples', 30));
        $since = Carbon::now()->subDays($days);

        $scenarioStats = AiUsageEvent::query()
            ->where('created_at', '>=', $since)
            ->select([
                'scenario',
                'kind',
                DB::raw('COUNT(*) as samples'),
                DB::raw('AVG(tokens_input) as avg_input'),
                DB::raw('AVG(tokens_output) as avg_output'),
                DB::raw('AVG(audio_seconds) as avg_audio'),
            ])
            ->groupBy('scenario', 'kind')
            ->get();

        $tokenEstimates = [];
        foreach (config('ai_calculator.scenarios', []) as $scenario) {
            if (! is_array($scenario)) {
                continue;
            }

            $id = (string) ($scenario['id'] ?? '');
            if ($id === '') {
                continue;
            }

            $tokenEstimates[$id] = [
                'input_tokens' => (int) ($scenario['input_tokens'] ?? 0),
                'output_tokens' => (int) ($scenario['output_tokens'] ?? 0),
                'measured' => false,
                'samples' => 0,
            ];
        }

        $whisperAvgSeconds = null;

        foreach ($scenarioStats as $row) {
            $id = (string) $row->scenario;
            $samples = (int) $row->samples;

            if ($id === 'whisper' && $row->kind === 'whisper') {
                $whisperAvgSeconds = $row->avg_audio !== null ? round((float) $row->avg_audio, 1) : null;

                continue;
            }

            if (! isset($tokenEstimates[$id]) || $samples < $minSamples) {
                continue;
            }

            $tokenEstimates[$id] = [
                'input_tokens' => max(0, (int) round((float) $row->avg_input)),
                'output_tokens' => max(0, (int) round((float) $row->avg_output)),
                'measured' => true,
                'samples' => $samples,
            ];
        }

        $inboundMessages = Message::query()
            ->where('direction', 'inbound')
            ->where('created_at', '>=', $since)
            ->count();

        $scenarioCounts = AiUsageEvent::query()
            ->where('created_at', '>=', $since)
            ->select('scenario', DB::raw('COUNT(*) as total'))
            ->groupBy('scenario')
            ->pluck('total', 'scenario');

        $aiReplyEvents = (int) ($scenarioCounts['ai_reply'] ?? 0);

        $triggerRates = [];
        foreach (config('ai_calculator.volume_triggers', []) as $volumeKey => $trigger) {
            if (! is_array($trigger)) {
                continue;
            }

            $numeratorScenario = (string) ($trigger['numerator'] ?? $volumeKey);
            $denominator = (string) ($trigger['denominator'] ?? 'inbound');
            $fallback = (float) ($trigger['fallback'] ?? 0);

            $numerator = (int) ($scenarioCounts[$numeratorScenario] ?? 0);
            $denomValue = match ($denominator) {
                'inbound' => max(1, $inboundMessages),
                'ai_reply' => max(1, $aiReplyEvents),
                'follow_up_proposal' => max(1, (int) ($scenarioCounts['follow_up_proposal'] ?? 0)),
                default => max(1, $numerator),
            };

            if ($numerator >= $minSamples && $denomValue > 0) {
                $triggerRates[$volumeKey] = round($numerator / $denomValue, 4);
            } else {
                $triggerRates[$volumeKey] = $fallback;
            }
        }

        return [
            'period_days' => $days,
            'token_estimates' => $tokenEstimates,
            'trigger_rates' => $triggerRates,
            'avg_whisper_seconds' => $whisperAvgSeconds,
            'inbound_messages' => $inboundMessages,
            'ai_reply_events' => $aiReplyEvents,
        ];
    }
}
