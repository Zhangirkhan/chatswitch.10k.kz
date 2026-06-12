<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Company;
use App\Models\DealOutcome;
use App\Models\WinProbabilityModel;
use App\Models\WinProbabilityScore;
use App\Services\AI\WinProbabilityFeatureExtractor;
use App\Services\AI\WinProbabilityModelRegistry;
use App\Services\AI\WinProbabilityService;
use App\Support\AiFeatureFlags;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;
use Phpml\Classification\Linear\LogisticRegression;
use ReflectionClass;

final class TrainWinProbabilityModelsCommand extends Command
{
    protected $signature = 'ai:train-win-probability {--company=}';

    protected $description = 'Train logistic regression win probability models per tenant.';

    public function handle(
        WinProbabilityFeatureExtractor $extractor,
        WinProbabilityModelRegistry $registry,
        WinProbabilityService $winProbabilityService,
    ): int {
        if (! Schema::hasTable('win_probability_models')) {
            $this->error('win_probability_models table missing.');

            return self::FAILURE;
        }

        $minSamples = (int) config('ai.win_prob.min_training_samples', 200);
        $query = Company::query()->where('is_active', true)->orderBy('id');

        if ($this->option('company') !== null) {
            $query->whereKey((int) $this->option('company'));
        }

        foreach ($query->pluck('id') as $companyId) {
            $companyId = (int) $companyId;
            if (! AiFeatureFlags::enabled(AiFeatureFlags::ML_WIN_PROB, $companyId)) {
                continue;
            }

            $dataset = $this->buildDataset($companyId, $extractor);
            if (count($dataset['samples']) < $minSamples) {
                $this->line("Company {$companyId}: insufficient samples (".count($dataset['samples']).")");

                continue;
            }

            $splitAt = (int) floor(count($dataset['samples']) * 0.8);
            $trainSamples = array_slice($dataset['samples'], 0, $splitAt);
            $trainLabels = array_slice($dataset['labels'], 0, $splitAt);
            $testSamples = array_slice($dataset['samples'], $splitAt);
            $testLabels = array_slice($dataset['labels'], $splitAt);

            $classifier = new LogisticRegression(0.01, 1000, true);
            $classifier->train($trainSamples, $trainLabels);

            $rawWeights = $this->extractWeights($classifier);
            $bias = (float) ($rawWeights[0] ?? 0);
            $featureWeights = array_slice($rawWeights, 1);

            $model = new WinProbabilityModel([
                'company_id' => $companyId,
                'version' => (int) ((WinProbabilityModel::query()->where('company_id', $companyId)->max('version') ?? 0) + 1),
                'algorithm' => 'logistic_regression',
                'coefficients' => [
                    'bias' => $bias,
                    'weights' => $featureWeights,
                ],
                'feature_schema' => WinProbabilityFeatureExtractor::FEATURE_KEYS,
                'training_samples' => count($dataset['samples']),
                'metrics' => [
                    'brier_score' => $this->brierScore($registry, $featureWeights, $bias, $testSamples, $testLabels),
                ],
                'trained_at' => now(),
                'is_active' => false,
            ]);
            $model->save();

            $baselineBrier = $this->heuristicBrier($winProbabilityService, $companyId, $testSamples, $testLabels, $dataset['chats']);
            $modelBrier = (float) ($model->metrics['brier_score'] ?? 1.0);

            if ($modelBrier <= $baselineBrier) {
                WinProbabilityModel::query()
                    ->where('company_id', $companyId)
                    ->where('id', '!=', $model->id)
                    ->update(['is_active' => false]);
                $model->forceFill(['is_active' => true])->save();
                $this->info("Company {$companyId}: activated ML v{$model->version} (Brier {$modelBrier})");
            } else {
                $this->line("Company {$companyId}: ML v{$model->version} skipped (Brier {$modelBrier} > heuristic {$baselineBrier})");
            }
        }

        return self::SUCCESS;
    }

    /**
     * @return array{samples: list<list<float>>, labels: list<int>, chats: list<int>}
     */
    private function buildDataset(int $companyId, WinProbabilityFeatureExtractor $extractor): array
    {
        $samples = [];
        $labels = [];
        $chats = [];

        $outcomes = DealOutcome::query()
            ->where('company_id', $companyId)
            ->orderBy('closed_at')
            ->get(['id', 'chat_id', 'won', 'closed_at', 'sales_state_snapshot']);

        foreach ($outcomes as $outcome) {
            $scoreRow = WinProbabilityScore::query()
                ->where('chat_id', $outcome->chat_id)
                ->when($outcome->closed_at !== null, fn ($q) => $q->where('computed_at', '<=', $outcome->closed_at))
                ->orderByDesc('computed_at')
                ->first();

            $features = null;
            if ($scoreRow !== null && is_array($scoreRow->inputs_snapshot)) {
                $snapshot = $scoreRow->inputs_snapshot;
                if (isset($snapshot['features']) && is_array($snapshot['features'])) {
                    $features = array_map('floatval', $snapshot['features']);
                }
            }

            if ($features === null) {
                $state = is_array($outcome->sales_state_snapshot) ? $outcome->sales_state_snapshot : [];
                $chat = \App\Models\Chat::query()->with('funnelStage')->find($outcome->chat_id);
                if ($chat === null) {
                    continue;
                }
                $features = $extractor->vectorFromState($state, $chat, $companyId);
            }

            $samples[] = $features;
            $labels[] = $outcome->won ? 1 : 0;
            $chats[] = (int) $outcome->chat_id;
        }

        return compact('samples', 'labels', 'chats');
    }

    /**
     * @param  list<float>  $weights
     * @param  list<list<float>>  $samples
     * @param  list<int>  $labels
     */
    private function brierScore(
        WinProbabilityModelRegistry $registry,
        array $weights,
        float $bias,
        array $samples,
        array $labels,
    ): float {
        if ($samples === []) {
            return 1.0;
        }

        $model = new WinProbabilityModel([
            'coefficients' => ['weights' => $weights, 'bias' => $bias],
        ]);

        $sum = 0.0;
        foreach ($samples as $index => $sample) {
            $p = $registry->predictProbability($model, $sample);
            $y = (float) ($labels[$index] ?? 0);
            $sum += ($p - $y) ** 2;
        }

        return round($sum / count($samples), 4);
    }

    /**
     * @param  list<list<float>>  $samples
     * @param  list<int>  $labels
     * @param  list<int>  $chats
     */
    private function heuristicBrier(
        WinProbabilityService $service,
        int $companyId,
        array $samples,
        array $labels,
        array $chats,
    ): float {
        if ($samples === []) {
            return 1.0;
        }

        $sum = 0.0;
        foreach ($samples as $index => $_sample) {
            $chat = \App\Models\Chat::query()->find($chats[$index] ?? 0);
            if ($chat === null) {
                continue;
            }
            $result = $service->computeHeuristicOnly($chat);
            $p = ((int) $result['win_probability']) / 100;
            $y = (float) ($labels[$index] ?? 0);
            $sum += ($p - $y) ** 2;
        }

        return round($sum / max(1, count($samples)), 4);
    }

    /**
     * @return list<float>
     */
    private function extractWeights(LogisticRegression $classifier): array
    {
        $reflection = new ReflectionClass($classifier);
        while ($reflection !== false) {
            if ($reflection->hasProperty('weights')) {
                $property = $reflection->getProperty('weights');
                $property->setAccessible(true);
                $weights = $property->getValue($classifier);

                return is_array($weights) ? array_map('floatval', $weights) : [];
            }
            $reflection = $reflection->getParentClass();
        }

        return [];
    }
}
