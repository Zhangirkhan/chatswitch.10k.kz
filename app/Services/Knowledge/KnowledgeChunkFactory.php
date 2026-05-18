<?php

declare(strict_types=1);

namespace App\Services\Knowledge;

use App\Models\KnowledgeRule;
use App\Models\Product;
use App\Models\Service;
use App\Services\AI\KnowledgeContextTextFormatter;

final class KnowledgeChunkFactory
{
    public function __construct(
        private readonly KnowledgeContextTextFormatter $formatter,
    ) {}

    /**
     * @return array{content_text: string, display_line: string}|null
     */
    public function fromProduct(Product $product): ?array
    {
        if (! $product->is_active || ! $product->include_in_prompt) {
            return null;
        }

        $display = $this->formatter->formatProductLine($product);
        $parts = [
            'Товар',
            $product->name,
            $product->sku ? "SKU {$product->sku}" : null,
            $product->description,
            $product->price !== null ? "Цена {$product->price} KZT" : null,
            $product->attributes !== null ? json_encode($product->attributes, JSON_UNESCAPED_UNICODE) : null,
        ];

        return [
            'content_text' => $this->joinParts($parts),
            'display_line' => $display,
        ];
    }

    /**
     * @return array{content_text: string, display_line: string}|null
     */
    public function fromService(Service $service): ?array
    {
        if (! $service->is_active || ! $service->include_in_prompt) {
            return null;
        }

        $display = $this->formatter->formatServiceLine($service);
        $parts = [
            'Услуга',
            $service->name,
            $service->description,
            $service->duration_minutes !== null ? "Длительность {$service->duration_minutes} минут" : null,
            $service->price !== null ? "Цена {$service->price} KZT" : null,
            $service->conditions !== null ? json_encode($service->conditions, JSON_UNESCAPED_UNICODE) : null,
        ];

        return [
            'content_text' => $this->joinParts($parts),
            'display_line' => $display,
        ];
    }

    /**
     * @return array{content_text: string, display_line: string}|null
     */
    public function fromRule(KnowledgeRule $rule): ?array
    {
        if (! $rule->is_active || ! $rule->include_in_prompt) {
            return null;
        }

        $display = $this->formatter->formatRuleLine($rule);

        return [
            'content_text' => $this->joinParts([
                'Правило',
                $rule->title,
                $rule->type,
                "priority {$rule->priority}",
                $rule->content,
            ]),
            'display_line' => $display,
        ];
    }

    /**
     * @param  list<string|null>  $parts
     */
    private function joinParts(array $parts): string
    {
        return collect($parts)
            ->map(static fn (?string $part): string => trim((string) $part))
            ->filter()
            ->implode("\n");
    }
}
