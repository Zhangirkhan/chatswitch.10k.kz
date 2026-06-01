<?php

declare(strict_types=1);

namespace App\Services\Promotion;

use App\Models\Company;
use App\Models\CompanyPromotion;
use App\Models\FunnelStageAiRule;
use Illuminate\Support\Collection;

final class CompanyPromotionCatalog
{
    /**
     * @return Collection<int, CompanyPromotion>
     */
    public function activeForCompany(int $companyId): Collection
    {
        return CompanyPromotion::query()
            ->where('company_id', $companyId)
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get()
            ->filter(fn (CompanyPromotion $promo): bool => $promo->isCurrentlyValid())
            ->values();
    }

    public function isEnabledForCompany(int $companyId): bool
    {
        if ($companyId <= 0) {
            return false;
        }

        $enabled = Company::query()->whereKey($companyId)->value('ai_promotions_enabled');

        return $enabled === null || (bool) $enabled;
    }

    public function isEnabledForRule(FunnelStageAiRule $rule): bool
    {
        $companyId = (int) ($rule->company_id ?? 0);
        if (! $this->isEnabledForCompany($companyId)) {
            return false;
        }

        return $rule->follow_up_use_promotions !== false;
    }

    /**
     * @return list<array{id: string, label: string, type?: string, benefit?: string|null, percent: int|null, fixed_amount?: string|null, buy_quantity?: int|null, get_quantity?: int|null, valid_until: string|null, note: string|null}>
     */
    public function promptItemsForCompany(int $companyId): array
    {
        if (! $this->isEnabledForCompany($companyId)) {
            return [];
        }

        return $this->activeForCompany($companyId)
            ->map(fn (CompanyPromotion $promo): array => $promo->toPromptArray())
            ->values()
            ->all();
    }

    /**
     * @return list<array{id: string, label: string, type?: string, benefit?: string|null, percent: int|null, fixed_amount?: string|null, buy_quantity?: int|null, get_quantity?: int|null, valid_until: string|null, note: string|null}>
     */
    public function promptItemsForRule(FunnelStageAiRule $rule): array
    {
        if (! $this->isEnabledForRule($rule)) {
            return [];
        }

        $companyId = (int) ($rule->company_id ?? 0);
        if ($companyId <= 0) {
            return $this->legacyPromptItems($rule);
        }

        $ids = array_values(array_filter(
            array_map('intval', is_array($rule->follow_up_promotion_ids) ? $rule->follow_up_promotion_ids : []),
            static fn (int $id): bool => $id > 0,
        ));

        if ($ids !== []) {
            $promos = CompanyPromotion::query()
                ->where('company_id', $companyId)
                ->whereIn('id', $ids)
                ->where('is_active', true)
                ->orderBy('sort_order')
                ->orderBy('id')
                ->get()
                ->filter(fn (CompanyPromotion $promo): bool => $promo->isCurrentlyValid());

            return $promos->map(fn (CompanyPromotion $promo): array => $promo->toPromptArray())->values()->all();
        }

        return $this->promptItemsForCompany($companyId);
    }

    /**
     * @param  list<array{id: string, label: string, type?: string, benefit?: string|null, percent?: int|null, valid_until?: string|null, note?: string|null}>  $items
     */
    public function formatPromptBlock(array $items, string $emptyText = 'Активные акции не заданы — не предлагай скидки и промо.'): string
    {
        if ($items === []) {
            return $emptyText;
        }

        $lines = [];
        foreach ($items as $item) {
            $parts = array_filter([
                'id='.$item['id'],
                ($item['label'] ?? '') !== '' ? $item['label'] : null,
                ! empty($item['benefit'])
                    ? $item['benefit']
                    : (isset($item['percent']) && $item['percent'] !== null ? $item['percent'].'%' : null),
                ! empty($item['valid_until']) ? 'до '.$item['valid_until'] : null,
                ! empty($item['note']) ? $item['note'] : null,
            ]);
            if ($parts !== []) {
                $lines[] = '- '.implode(', ', $parts);
            }
        }

        if ($lines === []) {
            return $emptyText;
        }

        return "Активные акции компании (можно предлагать клиенту, не выдумывай другие):\n".implode("\n", $lines);
    }

    /**
     * @return list<array{id: string, label: string, type?: string, benefit?: string|null, percent: int|null, fixed_amount?: string|null, buy_quantity?: int|null, get_quantity?: int|null, valid_until: string|null, note: string|null}>
     */
    private function legacyPromptItems(FunnelStageAiRule $rule): array
    {
        $items = is_array($rule->follow_up_allowed_promos) ? $rule->follow_up_allowed_promos : [];
        $result = [];

        foreach ($items as $index => $item) {
            if (! is_array($item)) {
                continue;
            }
            $id = trim((string) ($item['id'] ?? ''));
            if ($id === '') {
                $id = 'promo_'.($index + 1);
            }
            $result[] = [
                'id' => $id,
                'label' => trim((string) ($item['label'] ?? '')),
                'percent' => isset($item['percent']) ? (int) $item['percent'] : null,
                'valid_until' => isset($item['valid_until']) ? (string) $item['valid_until'] : null,
                'note' => isset($item['note']) ? trim((string) $item['note']) : null,
            ];
        }

        return $result;
    }
}
