<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Company;
use App\Services\AI\ToneProfileAnalyzer;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

final class AnalyzeCompanyToneProfileJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public function __construct(public readonly int $companyId) {}

    public function handle(ToneProfileAnalyzer $analyzer): void
    {
        if (! Company::query()->whereKey($this->companyId)->exists()) {
            return;
        }

        try {
            $analyzer->analyzeCompany($this->companyId);
        } catch (\Throwable $e) {
            Log::warning('[ai-tone] failed to analyze company profile', [
                'company_id' => $this->companyId,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
