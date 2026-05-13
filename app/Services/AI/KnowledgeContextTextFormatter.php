<?php

declare(strict_types=1);

namespace App\Services\AI;

use App\Models\KnowledgeRule;
use App\Models\Product;
use App\Models\Service;

final class KnowledgeContextTextFormatter
{
    public function __construct(
        private readonly KnowledgeContextRepository $knowledge,
    ) {}

    /**
     * Строки блока базы знаний в том же формате, что уходит в system prompt (до обрезки/суммаризации).
     *
     * @return list<string>
     */
    public function knowledgeLines(int $companyId): array
    {
        $data = $this->knowledge->forPrompt($companyId);
        $lines = ['База знаний компании. Валюта цен: казахстанский тенге (KZT, ₸).'];

        $lines[] = 'Правила ответа:';
        foreach ($data['rules'] as $rule) {
            $lines[] = $this->formatRuleLine($rule);
        }

        $lines[] = 'Товары:';
        foreach ($data['products'] as $product) {
            $lines[] = $this->formatProductLine($product);
        }

        $lines[] = 'Услуги:';
        foreach ($data['services'] as $service) {
            $lines[] = $this->formatServiceLine($service);
        }

        return $lines;
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

    private function formatRuleLine(KnowledgeRule $rule): string
    {
        return "- {$rule->title} ({$rule->type}, priority {$rule->priority}): {$rule->content}";
    }

    private function formatProductLine(Product $product): string
    {
        $price = $product->price !== null ? ' Цена: '.$this->formatTenge($product->price).'.' : '';
        $sku = $product->sku ? " SKU: {$product->sku}." : '';
        $attributes = $this->detailsBlock('Характеристики', $product->attributes);

        return trim("- {$product->name}.{$sku}{$price} ".trim((string) $product->description).' '.$attributes);
    }

    private function formatServiceLine(Service $service): string
    {
        $duration = $service->duration_minutes !== null ? " Длительность: {$service->duration_minutes} мин." : '';
        $price = $service->price !== null ? ' Цена: '.$this->formatTenge($service->price).'.' : '';
        $conditions = $this->detailsBlock('Условия', $service->conditions);

        return trim("- {$service->name}.{$duration}{$price} ".trim((string) $service->description).' '.$conditions);
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
}
