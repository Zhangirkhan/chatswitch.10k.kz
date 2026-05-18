<?php

declare(strict_types=1);

namespace App\Services\Knowledge;

use App\Models\KnowledgeRule;
use App\Models\Product;
use App\Models\Service;
use Illuminate\Support\Collection;

final class KnowledgeCatalogAuditService
{
    public function __construct(
        private readonly KnowledgeCatalogLlmAuditService $llmAudit,
    ) {}

    /**
     * @return array{
     *     findings: list<array{key: string, severity: string, category: string, title: string, description: string, action: string, entity_ids?: list<int>}>,
     *     summary: array{critical: int, warning: int, info: int, total: int},
     *     llm_used: bool
     * }
     */
    public function audit(int $companyId, bool $includeLlm = false, bool $refreshLlm = false): array
    {
        $findings = [];

        $products = Product::query()
            ->where('company_id', $companyId)
            ->orderBy('id')
            ->get();

        $services = Service::query()
            ->where('company_id', $companyId)
            ->orderBy('id')
            ->get();

        $rules = KnowledgeRule::query()
            ->where('company_id', $companyId)
            ->orderBy('id')
            ->get();

        $findings = array_merge(
            $findings,
            $this->auditProducts($products),
            $this->auditServices($services),
            $this->auditRules($rules),
            $this->auditCrossCatalog($companyId, $products, $services, $rules),
        );

        $llmUsed = false;
        if ($includeLlm) {
            $llmFindings = $this->llmAudit->audit($companyId, $refreshLlm);
            if ($llmFindings !== []) {
                $llmUsed = true;
                $findings = [...$findings, ...$llmFindings];
            }
        }

        $summary = ['critical' => 0, 'warning' => 0, 'info' => 0, 'total' => count($findings)];
        foreach ($findings as $finding) {
            $severity = $finding['severity'];
            if (isset($summary[$severity])) {
                $summary[$severity]++;
            }
        }

        return ['findings' => $findings, 'summary' => $summary, 'llm_used' => $llmUsed];
    }

    /**
     * @param  Collection<int, Product>  $products
     * @return list<array{key: string, severity: string, category: string, title: string, description: string, action: string, entity_ids?: list<int>}>
     */
    private function auditProducts(Collection $products): array
    {
        $findings = [];
        $inPrompt = $products->filter(static fn (Product $p): bool => $p->is_active && $p->include_in_prompt);

        $byName = $inPrompt->groupBy(fn (Product $p): string => $this->normalizeKey($p->name));
        foreach ($byName as $key => $group) {
            if ($key === '' || $group->count() < 2) {
                continue;
            }
            $labels = $group->pluck('name')->unique()->take(5)->implode(', ');
            $findings[] = $this->finding(
                'duplicate_product_names',
                'warning',
                'Товары',
                'Похожие названия товаров в промпте',
                "AI может путать позиции: {$labels}.",
                'Объедините дубли или уточните названия и SKU.',
                $group->pluck('id')->map(static fn ($id): int => (int) $id)->all(),
            );
        }

        $bySku = $inPrompt
            ->filter(static fn (Product $p): bool => trim((string) $p->sku) !== '')
            ->groupBy(static fn (Product $p): string => mb_strtolower(trim((string) $p->sku)));
        foreach ($bySku as $sku => $group) {
            if ($group->count() < 2) {
                continue;
            }
            $findings[] = $this->finding(
                'duplicate_product_sku',
                'critical',
                'Товары',
                'Дублирующийся SKU',
                "SKU «{$sku}» используется у нескольких товаров в промпте.",
                'Назначьте уникальный SKU каждой позиции.',
                $group->pluck('id')->map(static fn ($id): int => (int) $id)->all(),
            );
        }

        $priceGroups = $inPrompt->groupBy(fn (Product $p): string => $this->normalizeKey($p->name));
        foreach ($priceGroups as $key => $group) {
            if ($key === '' || $group->count() < 2) {
                continue;
            }
            $prices = $group
                ->map(static fn (Product $p): ?string => $p->price !== null ? (string) $p->price : null)
                ->filter()
                ->unique();
            if ($prices->count() > 1) {
                $findings[] = $this->finding(
                    'conflicting_product_prices',
                    'critical',
                    'Товары',
                    'Разные цены у похожих товаров',
                    "Для «{$group->first()->name}» в промпте указано несколько цен: ".$prices->implode(', ').' ₸.',
                    'Оставьте одну актуальную цену или разведите позиции по названию.',
                    $group->pluck('id')->map(static fn ($id): int => (int) $id)->all(),
                );
            }
        }

        $missingPrice = $inPrompt->filter(static fn (Product $p): bool => $p->price === null);
        if ($missingPrice->isNotEmpty()) {
            $findings[] = $this->finding(
                'products_missing_price',
                'warning',
                'Товары',
                'В промпте есть товары без цены',
                'Позиции: '.$missingPrice->pluck('name')->take(6)->implode(', ').'.',
                'Укажите цену или явно напишите в описании, что цена по запросу.',
                $missingPrice->pluck('id')->map(static fn ($id): int => (int) $id)->all(),
            );
        }

        $missingDescription = $inPrompt->filter(static fn (Product $p): bool => trim((string) $p->description) === '');
        if ($missingDescription->isNotEmpty()) {
            $findings[] = $this->finding(
                'products_missing_description',
                'info',
                'Товары',
                'Товары в промпте без описания',
                $missingDescription->pluck('name')->take(6)->implode(', ').'.',
                'Добавьте 1–2 факта: материал, размер, срок, ограничения.',
                $missingDescription->pluck('id')->map(static fn ($id): int => (int) $id)->all(),
            );
        }

        $inactiveInPrompt = $products->filter(static fn (Product $p): bool => ! $p->is_active && $p->include_in_prompt);
        if ($inactiveInPrompt->isNotEmpty()) {
            $findings[] = $this->finding(
                'inactive_products_in_prompt',
                'warning',
                'Товары',
                'Отключённые товары всё ещё в промпте',
                $inactiveInPrompt->pluck('name')->take(6)->implode(', ').'.',
                'Снимите флаг «В промпте» или активируйте позицию.',
                $inactiveInPrompt->pluck('id')->map(static fn ($id): int => (int) $id)->all(),
            );
        }

        $nearDuplicates = $this->findNearDuplicateNames($inPrompt->values()->all());
        if ($nearDuplicates !== []) {
            $findings[] = $this->finding(
                'near_duplicate_product_names',
                'info',
                'Товары',
                'Очень похожие названия',
                implode('; ', array_slice($nearDuplicates, 0, 4)).'.',
                'Проверьте, не дубли ли это разные формулировки одной позиции.',
            );
        }

        return $findings;
    }

    /**
     * @param  Collection<int, Service>  $services
     * @return list<array{key: string, severity: string, category: string, title: string, description: string, action: string, entity_ids?: list<int>}>
     */
    private function auditServices(Collection $services): array
    {
        $findings = [];
        $inPrompt = $services->filter(static fn (Service $s): bool => $s->is_active && $s->include_in_prompt);

        $byName = $inPrompt->groupBy(fn (Service $s): string => $this->normalizeKey($s->name));
        foreach ($byName as $key => $group) {
            if ($key === '' || $group->count() < 2) {
                continue;
            }
            $findings[] = $this->finding(
                'duplicate_service_names',
                'warning',
                'Услуги',
                'Дубли названий услуг в промпте',
                $group->pluck('name')->unique()->take(5)->implode(', ').'.',
                'Объедините услуги или уточните условия в описании.',
                $group->pluck('id')->map(static fn ($id): int => (int) $id)->all(),
            );
        }

        $priceGroups = $inPrompt->groupBy(fn (Service $s): string => $this->normalizeKey($s->name));
        foreach ($priceGroups as $key => $group) {
            if ($key === '' || $group->count() < 2) {
                continue;
            }
            $prices = $group
                ->map(static fn (Service $s): ?string => $s->price !== null ? (string) $s->price : null)
                ->filter()
                ->unique();
            if ($prices->count() > 1) {
                $findings[] = $this->finding(
                    'conflicting_service_prices',
                    'critical',
                    'Услуги',
                    'Разные цены у похожих услуг',
                    "Для «{$group->first()->name}» указано несколько цен: ".$prices->implode(', ').' ₸.',
                    'Сверьте прайс и оставьте одну запись.',
                    $group->pluck('id')->map(static fn ($id): int => (int) $id)->all(),
                );
            }
        }

        $missingPrice = $inPrompt->filter(static fn (Service $s): bool => $s->price === null);
        if ($missingPrice->isNotEmpty()) {
            $findings[] = $this->finding(
                'services_missing_price',
                'warning',
                'Услуги',
                'Услуги в промпте без цены',
                $missingPrice->pluck('name')->take(6)->implode(', ').'.',
                'Укажите цену или условие «по согласованию» в описании.',
                $missingPrice->pluck('id')->map(static fn ($id): int => (int) $id)->all(),
            );
        }

        return $findings;
    }

    /**
     * @param  Collection<int, KnowledgeRule>  $rules
     * @return list<array{key: string, severity: string, category: string, title: string, description: string, action: string, entity_ids?: list<int>}>
     */
    private function auditRules(Collection $rules): array
    {
        $findings = [];
        $inPrompt = $rules->filter(static fn (KnowledgeRule $r): bool => $r->is_active && $r->include_in_prompt);

        $byTitle = $inPrompt->groupBy(fn (KnowledgeRule $r): string => $this->normalizeKey($r->title));
        foreach ($byTitle as $key => $group) {
            if ($key === '' || $group->count() < 2) {
                continue;
            }
            $findings[] = $this->finding(
                'duplicate_rule_titles',
                'warning',
                'Правила',
                'Дубли заголовков правил',
                $group->pluck('title')->unique()->take(5)->implode(', ').'.',
                'Объедините правила или разведите формулировки.',
                $group->pluck('id')->map(static fn ($id): int => (int) $id)->all(),
            );
        }

        $emptyContent = $inPrompt->filter(static fn (KnowledgeRule $r): bool => trim((string) $r->content) === '');
        if ($emptyContent->isNotEmpty()) {
            $findings[] = $this->finding(
                'rules_empty_content',
                'critical',
                'Правила',
                'Пустой текст правила в промпте',
                $emptyContent->pluck('title')->take(6)->implode(', ').'.',
                'Заполните текст правила или отключите его для AI.',
                $emptyContent->pluck('id')->map(static fn ($id): int => (int) $id)->all(),
            );
        }

        $deliveryRules = $inPrompt->filter(static function (KnowledgeRule $r): bool {
            $blob = mb_strtolower($r->title.' '.$r->content);

            return str_contains($blob, 'доставк') || str_contains($blob, 'delivery');
        });
        if ($deliveryRules->count() >= 2) {
            $hasFree = $deliveryRules->contains(static function (KnowledgeRule $r): bool {
                $blob = mb_strtolower($r->title.' '.$r->content);

                return str_contains($blob, 'бесплат') || str_contains($blob, 'free');
            });
            $hasPaid = $deliveryRules->contains(static function (KnowledgeRule $r): bool {
                $blob = mb_strtolower($r->title.' '.$r->content);

                return str_contains($blob, 'платн') || str_contains($blob, '₸') || preg_match('/\d+\s*₸/u', $blob) === 1;
            });
            if ($hasFree && $hasPaid) {
                $findings[] = $this->finding(
                    'conflicting_delivery_rules',
                    'warning',
                    'Правила',
                    'Противоречие по доставке',
                    'Есть правила про бесплатную и платную доставку одновременно.',
                    'Оставьте одно актуальное правило или уточните условия (город, сумма заказа).',
                    $deliveryRules->pluck('id')->map(static fn ($id): int => (int) $id)->all(),
                );
            }
        }

        return $findings;
    }

    /**
     * @param  Collection<int, Product>  $products
     * @param  Collection<int, Service>  $services
     * @return list<array{key: string, severity: string, category: string, title: string, description: string, action: string, entity_ids?: list<int>}>
     */
    private function auditCrossCatalog(int $companyId, Collection $products, Collection $services, Collection $rules): array
    {
        $findings = [];
        $productNames = $products
            ->filter(static fn (Product $p): bool => $p->is_active && $p->include_in_prompt)
            ->mapWithKeys(fn (Product $p): array => [$this->normalizeKey($p->name) => $p->name]);

        $overlap = [];
        foreach ($services->filter(static fn (Service $s): bool => $s->is_active && $s->include_in_prompt) as $service) {
            $key = $this->normalizeKey($service->name);
            if ($key !== '' && $productNames->has($key)) {
                $overlap[] = $service->name.' ↔ '.$productNames->get($key);
            }
        }

        if ($overlap !== []) {
            $findings[] = $this->finding(
                'product_service_name_overlap',
                'info',
                'Каталог',
                'Одинаковые названия у товара и услуги',
                implode('; ', array_slice($overlap, 0, 5)).'.',
                'Разведите формулировки, чтобы AI не смешивал сущности.',
            );
        }

        $promptCount = $products->where('is_active', true)->where('include_in_prompt', true)->count()
            + $services->where('is_active', true)->where('include_in_prompt', true)->count()
            + $rules->where('is_active', true)->where('include_in_prompt', true)->count();

        if ($promptCount > 120) {
            $findings[] = $this->finding(
                'catalog_too_large',
                'info',
                'Каталог',
                'Очень большой каталог в промпте',
                "Сейчас в промпте {$promptCount} записей. RAG помогает, но полный дамп может обрезаться.",
                'Отключите редкие позиции из промпта или держите RAG-индекс актуальным.',
            );
        }

        return $findings;
    }

    /**
     * @param  list<Product>  $products
     * @return list<string>
     */
    private function findNearDuplicateNames(array $products): array
    {
        $pairs = [];
        $count = count($products);
        for ($i = 0; $i < $count; $i++) {
            for ($j = $i + 1; $j < $count; $j++) {
                $a = $this->normalizeKey($products[$i]->name);
                $b = $this->normalizeKey($products[$j]->name);
                if ($a === '' || $b === '' || $a === $b) {
                    continue;
                }
                similar_text($a, $b, $percent);
                if ($percent >= 88.0) {
                    $pairs[] = "{$products[$i]->name} ~ {$products[$j]->name}";
                }
            }
        }

        return $pairs;
    }

    private function normalizeKey(string $value): string
    {
        $value = mb_strtolower(trim($value));
        $value = preg_replace('/[^\p{L}\p{N}\s]+/u', ' ', $value) ?? $value;
        $value = preg_replace('/\s+/u', ' ', $value) ?? $value;

        return trim($value);
    }

    /**
     * @param  list<int>  $entityIds
     * @return array{key: string, severity: string, category: string, title: string, description: string, action: string, entity_ids?: list<int>}
     */
    private function finding(
        string $key,
        string $severity,
        string $category,
        string $title,
        string $description,
        string $action,
        array $entityIds = [],
    ): array {
        $item = compact('key', 'severity', 'category', 'title', 'description', 'action');
        if ($entityIds !== []) {
            $item['entity_ids'] = $entityIds;
        }

        return $item;
    }
}
