<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Company;
use App\Services\Knowledge\KnowledgeQualityScoreService;
use Illuminate\Console\Command;

final class ComputeKnowledgeQualityScoresCommand extends Command
{
    protected $signature = 'knowledge:compute-quality-scores {--company=}';

    protected $description = 'Compute Knowledge Quality Scores for indexed chunks.';

    public function handle(KnowledgeQualityScoreService $service): int
    {
        $companyId = $this->option('company');
        $query = Company::query()->where('is_active', true)->orderBy('id');

        if ($companyId !== null) {
            $query->whereKey((int) $companyId);
        }

        $total = 0;
        $query->pluck('id')->each(function (int $id) use ($service, &$total): void {
            $count = $service->computeForCompany($id);
            $total += $count;
            $this->line("Company {$id}: {$count} chunks scored");
        });

        $this->info("Knowledge quality scores updated for {$total} chunks.");

        return self::SUCCESS;
    }
}
