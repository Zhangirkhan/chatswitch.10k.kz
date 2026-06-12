<?php

declare(strict_types=1);

namespace App\Services\AI;

use App\Models\WinProbabilityModel;
use Illuminate\Support\Facades\Schema;

final class WinProbabilityModelRegistry
{
    public function activeForCompany(int $companyId): ?WinProbabilityModel
    {
        if (! Schema::hasTable('win_probability_models')) {
            return null;
        }

        return WinProbabilityModel::query()
            ->where('company_id', $companyId)
            ->where('is_active', true)
            ->orderByDesc('version')
            ->first();
    }

    /**
     * @param  list<float>  $features
     */
    public function predictProbability(WinProbabilityModel $model, array $features): float
    {
        $coefficients = is_array($model->coefficients) ? $model->coefficients : [];
        $weights = $coefficients['weights'] ?? [];
        $bias = (float) ($coefficients['bias'] ?? 0);

        if (! is_array($weights) || $weights === []) {
            return 0.5;
        }

        $z = $bias;
        foreach ($weights as $index => $weight) {
            $z += (float) $weight * (float) ($features[(int) $index] ?? 0);
        }

        return 1 / (1 + exp(-$z));
    }
}
