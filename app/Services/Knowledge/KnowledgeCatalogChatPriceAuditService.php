<?php

declare(strict_types=1);

namespace App\Services\Knowledge;

use App\Models\Message;
use App\Models\Product;
use App\Models\Service;
use Illuminate\Support\Collection;

/**
 * Сверяет цены каталога с суммами, которые операторы/AI называли клиентам в исходящих сообщениях.
 */
final class KnowledgeCatalogChatPriceAuditService
{
    private const LOOKBACK_DAYS = 90;

    private const MIN_CATALOG_PRICE = 1000;

    /** Относительное расхождение для предупреждения. */
    private const RELATIVE_DIFF_THRESHOLD = 0.12;

    /** Абсолютное расхождение (₸), если цена небольшая. */
    private const ABSOLUTE_DIFF_THRESHOLD = 3000;

    /**
     * @return list<array{key: string, severity: string, category: string, title: string, description: string, action: string, entity_ids?: list<int>}>
     */
    public function audit(int $companyId): array
    {
        $products = Product::query()
            ->where('company_id', $companyId)
            ->where('is_active', true)
            ->where('include_in_prompt', true)
            ->whereNotNull('price')
            ->get(['id', 'name', 'price']);

        $services = Service::query()
            ->where('company_id', $companyId)
            ->where('is_active', true)
            ->where('include_in_prompt', true)
            ->whereNotNull('price')
            ->get(['id', 'name', 'price']);

        if ($products->isEmpty() && $services->isEmpty()) {
            return [];
        }

        $messages = Message::query()
            ->where('direction', 'outbound')
            ->whereNotNull('body')
            ->where('created_at', '>=', now()->subDays(self::LOOKBACK_DAYS))
            ->whereHas('chat', static fn ($q) => $q->where('company_id', $companyId))
            ->orderByDesc('id')
            ->limit(2500)
            ->get(['id', 'body']);

        if ($messages->isEmpty()) {
            return [];
        }

        $findings = [];
        $findings = array_merge(
            $findings,
            $this->auditCatalogCollection($products, $messages, 'product', 'Товары'),
            $this->auditCatalogCollection($services, $messages, 'service', 'Услуги'),
        );

        return $findings;
    }

    /**
     * @param  Collection<int, Product|Service>  $items
     * @param  Collection<int, Message>  $messages
     * @return list<array{key: string, severity: string, category: string, title: string, description: string, action: string, entity_ids?: list<int>}>
     */
    private function auditCatalogCollection(
        Collection $items,
        Collection $messages,
        string $entityPrefix,
        string $categoryLabel,
    ): array {
        $findings = [];

        foreach ($items as $item) {
            $catalogPrice = (float) $item->price;
            if ($catalogPrice < self::MIN_CATALOG_PRICE) {
                continue;
            }

            $nameKey = $this->normalizeKey($item->name);
            if ($nameKey === '' || mb_strlen($nameKey) < 4) {
                continue;
            }

            $quoted = [];
            foreach ($messages as $message) {
                $body = $this->normalizeKey((string) $message->body);
                if (! str_contains($body, $nameKey)) {
                    continue;
                }

                foreach ($this->extractPrices((string) $message->body) as $price) {
                    if ($this->differsFromCatalog($price, $catalogPrice)) {
                        $quoted[] = $price;
                    }
                }
            }

            $quoted = array_values(array_unique($quoted));
            if ($quoted === []) {
                continue;
            }

            $quotedLabel = implode(', ', array_map(
                static fn (float $p): string => number_format($p, 0, '.', ' ').' ₸',
                array_slice($quoted, 0, 4),
            ));

            $findings[] = [
                'key' => "{$entityPrefix}_chat_price_mismatch_{$item->id}",
                'severity' => 'warning',
                'category' => $categoryLabel,
                'title' => 'Цена в чате не совпадает с каталогом',
                'description' => "«{$item->name}»: в каталоге ".number_format($catalogPrice, 0, '.', ' ')." ₸, в переписке встречалось: {$quotedLabel} (за ".self::LOOKBACK_DAYS.' дн.).',
                'action' => 'Сверьте прайс в базе знаний или уточните условия (акция, комплектация) в описании позиции.',
                'entity_ids' => [(int) $item->id],
            ];
        }

        return $findings;
    }

    /**
     * @return list<float>
     */
    private function extractPrices(string $body): array
    {
        if (! preg_match_all('/(\d[\d\s]{2,7})\s*(?:₸|тг\.?|тенге|tg\b)?/iu', $body, $matches)) {
            return [];
        }

        $prices = [];
        foreach ($matches[1] as $raw) {
            $digits = (int) preg_replace('/\D+/', '', (string) $raw);
            if ($digits >= self::MIN_CATALOG_PRICE && $digits <= 99_999_999) {
                $prices[] = (float) $digits;
            }
        }

        return $prices;
    }

    private function differsFromCatalog(float $quoted, float $catalog): bool
    {
        $diff = abs($quoted - $catalog);

        if ($diff <= self::ABSOLUTE_DIFF_THRESHOLD) {
            return false;
        }

        if ($catalog <= 0) {
            return true;
        }

        return ($diff / $catalog) >= self::RELATIVE_DIFF_THRESHOLD;
    }

    private function normalizeKey(string $value): string
    {
        $value = mb_strtolower(trim($value));
        $value = preg_replace('/[^\p{L}\p{N}\s]+/u', ' ', $value) ?? $value;
        $value = preg_replace('/\s+/u', ' ', $value) ?? $value;

        return trim($value);
    }
}
