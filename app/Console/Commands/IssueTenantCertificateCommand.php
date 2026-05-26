<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\IssueTenantCertificateJob;
use App\Models\Company;
use Illuminate\Console\Command;

final class IssueTenantCertificateCommand extends Command
{
    protected $signature = 'tenant:issue-cert
        {slug : Slug компании (a-z0-9-) или "all" для всех активных тенантов}
        {--sync : Запустить синхронно, без очереди}';

    protected $description = 'Выпустить SSL-сертификат и nginx-блок для тенанта';

    public function handle(): int
    {
        $arg = $this->argument('slug');

        $slugs = $arg === 'all'
            ? Company::query()
                ->whereNotNull('slug')
                ->where('is_active', true)
                ->orderBy('id')
                ->pluck('slug')
                ->all()
            : [$arg];

        foreach ($slugs as $slug) {
            $this->components->info("→ tenant: {$slug}");

            if ($this->option('sync')) {
                IssueTenantCertificateJob::dispatchSync($slug);
            } else {
                IssueTenantCertificateJob::dispatch($slug);
            }
        }

        $this->components->info(count($slugs).' job(s) dispatched');

        return self::SUCCESS;
    }
}
