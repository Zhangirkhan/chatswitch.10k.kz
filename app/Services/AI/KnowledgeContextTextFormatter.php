<?php

declare(strict_types=1);

namespace App\Services\AI;

use App\Models\KnowledgeRule;
use App\Models\Product;
use App\Models\Service;
use App\Services\Knowledge\KnowledgeRagRetriever;

final class KnowledgeContextTextFormatter
{
    public function __construct(
        private readonly KnowledgeContextRepository $knowledge,
        private readonly KnowledgeRagRetriever $ragRetriever,
    ) {}

    /**
     * Строки блока базы знаний в том же формате, что уходит в system prompt (до обрезки/суммаризации).
     *
     * @return list<string>
     */
    public function knowledgeLines(int $companyId, ?string $query = null, ?string $domain = null): array
    {
        if ($this->ragRetriever->shouldUseForQuery($query)) {
            $ragLines = $this->ragRetriever->retrieveLines($companyId, (string) $query, $domain);
            if ($ragLines !== []) {
                return $this->ragHeader($query, true, $ragLines);
            }
        }

        $data = $this->knowledge->forPrompt($companyId);
        $bodyLines = ['Правила ответа:'];
        foreach ($data['rules'] as $rule) {
            $bodyLines[] = $this->formatRuleLine($rule);
        }

        $bodyLines[] = 'Товары:';
        foreach ($data['products'] as $product) {
            $bodyLines[] = $this->formatProductLine($product);
        }

        $bodyLines[] = 'Услуги:';
        foreach ($data['services'] as $service) {
            $bodyLines[] = $this->formatServiceLine($service);
        }

        return $this->ragHeader($query, false, $bodyLines);
    }

    /**
     * @return array{rules: int, products: int, services: int}
     */
    public function promptEntryCounts(int $companyId): array
    {
        $data = $this->knowledge->forPrompt($companyId);

        return [
            'rules' => count($data['rules']),
            'products' => count($data['products']),
            'services' => count($data['services']),
        ];
    }

    public function formatRuleLine(KnowledgeRule $rule): string
    {
        return "- {$rule->title} ({$rule->type}, priority {$rule->priority}): {$rule->content}";
    }

    public function formatProductLine(Product $product): string
    {
        $price = $product->price !== null ? ' Цена: '.$this->formatTenge($product->price).'.' : '';
        $sku = $product->sku ? " SKU: {$product->sku}." : '';
        $attributes = $this->detailsBlock('Характеристики', $product->attributes);

        return $this->factsLine('Товар', "[id={$product->id}] {$product->name}", [
            $sku,
            $price,
            trim((string) $product->description),
            $attributes,
        ]);
    }

    public function formatServiceLine(Service $service): string
    {
        $duration = $service->duration_minutes !== null ? " Длительность: {$service->duration_minutes} мин." : '';
        $price = $service->price !== null ? ' Цена: '.$this->formatTenge($service->price).'.' : '';
        $conditions = $this->detailsBlock('Условия', $service->conditions);

        return $this->factsLine('Услуга', $service->name, [
            $duration,
            $price,
            trim((string) $service->description),
            $conditions,
        ]);
    }

    /**
     * @param  list<string>  $parts
     */
    private function factsLine(string $type, string $name, array $parts): string
    {
        $facts = collect($parts)
            ->map(static fn (string $part): string => trim($part, " \t\n\r\0\x0B."))
            ->filter()
            ->implode(' | ');

        return $facts !== '' ? "- {$type}: {$name} | {$facts}" : "- {$type}: {$name}";
    }

    private function formatTenge(mixed $price): string
    {
        $amount = is_numeric($price) ? (float) $price : 0.0;
        $formatted = number_format($amount, (float) $amount === floor($amount) ? 0 : 2, ',', ' ');

        return "{$formatted} ₸";
    }

    /**
     * @param  array<string, mixed>|null  $details
     */
    private function detailsBlock(string $label, ?array $details): string
    {
        if ($details === null || $details === []) {
            return '';
        }

        $pairs = collect($details)
            ->map(function (mixed $value, string $key): ?string {
                if ($value === null || $value === '') {
                    return null;
                }

                if (is_array($value)) {
                    $value = implode(', ', array_map(static fn (mixed $item): string => (string) $item, $value));
                } elseif (is_bool($value)) {
                    $value = $value ? 'да' : 'нет';
                }

                return "{$key}: {$value}";
            })
            ->filter()
            ->implode('; ');

        return $pairs !== '' ? "{$label}: {$pairs}." : '';
    }

    /**
     * @param  list<string>  $bodyLines
     * @return list<string>
     */
    private function ragHeader(?string $query, bool $usedRag, array $bodyLines): array
    {
        $header = [
            'База знаний компании. Валюта цен: казахстанский тенге (KZT, ₸).',
            'Используй эти записи как факты для точного ответа. Не превращай каждый товар или услугу в одинаковую рекламную карточку; отвечай только по вопросу клиента.',
        ];

        if ($usedRag && trim((string) $query) !== '') {
            $header[] = 'Подбор записей: RAG (релевантные фрагменты по запросу клиента).';
        }

        return [...$header, ...$bodyLines];
    }
}
