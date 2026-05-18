<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Company;
use App\Services\Knowledge\KnowledgeCatalogAuditService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

final class ScheduledKnowledgeCatalogAuditCommand extends Command
{
    protected $signature = 'knowledge:catalog-audit {company_id? : Ограничить одной компанией}';

    protected $description = 'Запускает эвристический аудит каталога БЗ (без LLM) и пишет сводку в лог';

    public function handle(KnowledgeCatalogAuditService $catalogAudit): int
    {
        $query = Company::query()->orderBy('id');
        $companyArg = $this->argument('company_id');
        if ($companyArg !== null) {
            $query->whereKey((int) $companyArg);
        }

        $withIssues = [];
        $clean = 0;

        $query->chunkById(50, function ($companies) use ($catalogAudit, &$withIssues, &$clean): void {
            foreach ($companies as $company) {
                $result = $catalogAudit->audit((int) $company->id, false, false);
                $summary = $result['summary'];
                $issueCount = ($summary['critical'] ?? 0) + ($summary['warning'] ?? 0) + ($summary['info'] ?? 0);
                if ($issueCount > 0) {
                    $withIssues[] = [
                        'company_id' => (int) $company->id,
                        'summary' => $summary,
                    ];
                } else {
                    $clean++;
                }
            }
        });

        Log::info('knowledge.catalog_audit.scheduled', [
            'companies_with_findings' => count($withIssues),
            'companies_clean' => $clean,
            'samples' => array_slice($withIssues, 0, 12),
        ]);

        if ($withIssues !== []) {
            Log::warning('knowledge.catalog_audit.scheduled_has_findings', [
                'company_ids' => array_column($withIssues, 'company_id'),
            ]);
        }

        $this->info(sprintf(
            'Done. Companies with findings: %d, clean: %d.',
            count($withIssues),
            $clean,
        ));

        return self::SUCCESS;
    }
}
