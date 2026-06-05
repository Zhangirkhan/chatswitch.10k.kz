<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Company;
use App\Services\ChatService;
use App\Tenancy\TenantContext;
use Illuminate\Console\Command;

/**
 * Удаляет служебные WA-сообщения (e2e_notification и т.п.) и пустые «призрачные» чаты.
 */
final class PruneGhostWhatsappChatsCommand extends Command
{
    protected $signature = 'chats:prune-ghost-whatsapp {--company= : Slug тенанта (по умолчанию — все активные)}';

    protected $description = 'Remove WhatsApp service messages and empty ghost chats (@lid / e2e_notification)';

    public function handle(ChatService $chatService, TenantContext $tenantContext): int
    {
        $companySlug = $this->option('company');
        $companies = Company::query()
            ->withoutGlobalScope('tenant')
            ->when(
                is_string($companySlug) && $companySlug !== '',
                fn ($query) => $query->where('slug', $companySlug),
            )
            ->where('is_active', true)
            ->orderBy('id')
            ->get(['id', 'slug']);

        if ($companies->isEmpty()) {
            $this->warn('No matching tenants found.');

            return self::FAILURE;
        }

        $totals = [
            'ignored_messages' => 0,
            'deleted_chats' => 0,
            'fixed_contacts' => 0,
        ];

        foreach ($companies as $company) {
            $tenantContext->setCompany($company);
            $result = $chatService->pruneGhostWhatsappChats();

            foreach ($totals as $key => $value) {
                $totals[$key] = $value + (int) ($result[$key] ?? 0);
            }

            if (array_sum($result) > 0) {
                $this->line(sprintf(
                    '  %s: -%d service msgs, -%d chats, fixed %d contacts',
                    $company->slug,
                    $result['ignored_messages'],
                    $result['deleted_chats'],
                    $result['fixed_contacts'],
                ));
            }
        }

        $tenantContext->clear();

        $this->info(sprintf(
            'Done. Removed %d service messages, deleted %d ghost chats, fixed %d contacts.',
            $totals['ignored_messages'],
            $totals['deleted_chats'],
            $totals['fixed_contacts'],
        ));

        return self::SUCCESS;
    }
}
