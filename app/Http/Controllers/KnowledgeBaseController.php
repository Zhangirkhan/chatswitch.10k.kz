<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\KnowledgeBase\KnowledgeItemRequest;
use App\Models\Company;
use App\Models\KnowledgeRule;
use App\Models\Product;
use App\Models\Service;
use App\Services\AI\KnowledgeContextTextFormatter;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

final class KnowledgeBaseController extends Controller
{
    public function __construct(
        private readonly KnowledgeContextTextFormatter $knowledgeTextFormatter,
    ) {}

    public function products(): Response
    {
        return $this->render('products');
    }

    public function services(): Response
    {
        return $this->render('services');
    }

    public function rules(): Response
    {
        return $this->render('rules');
    }

    public function storeProduct(KnowledgeItemRequest $request): JsonResponse
    {
        $product = Product::create($this->productPayload($request->validated()));

        return response()->json(['success' => true, 'item' => $this->transform($product)]);
    }

    public function updateProduct(KnowledgeItemRequest $request, Product $product): JsonResponse
    {
        $product->update($this->productPayload($request->validated()));

        return response()->json(['success' => true, 'item' => $this->transform($product->fresh())]);
    }

    public function destroyProduct(Product $product): JsonResponse
    {
        $product->delete();

        return response()->json(['success' => true]);
    }

    public function storeService(KnowledgeItemRequest $request): JsonResponse
    {
        $service = Service::create($this->servicePayload($request->validated()));

        return response()->json(['success' => true, 'item' => $this->transform($service)]);
    }

    public function updateService(KnowledgeItemRequest $request, Service $service): JsonResponse
    {
        $service->update($this->servicePayload($request->validated()));

        return response()->json(['success' => true, 'item' => $this->transform($service->fresh())]);
    }

    public function destroyService(Service $service): JsonResponse
    {
        $service->delete();

        return response()->json(['success' => true]);
    }

    public function storeRule(KnowledgeItemRequest $request): JsonResponse
    {
        $rule = KnowledgeRule::create($this->rulePayload($request->validated()));

        return response()->json(['success' => true, 'item' => $this->transform($rule)]);
    }

    public function updateRule(KnowledgeItemRequest $request, KnowledgeRule $rule): JsonResponse
    {
        $rule->update($this->rulePayload($request->validated()));

        return response()->json(['success' => true, 'item' => $this->transform($rule->fresh())]);
    }

    public function destroyRule(KnowledgeRule $rule): JsonResponse
    {
        $rule->delete();

        return response()->json(['success' => true]);
    }

    public function promptPreview(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'company_id' => ['required', 'integer', 'exists:companies,id'],
        ]);

        $companyId = (int) $validated['company_id'];
        $lines = $this->knowledgeTextFormatter->knowledgeLines($companyId);
        $full = implode("\n", $lines);
        $displayMax = 45000;
        $truncated = mb_strlen($full) > $displayMax;
        $text = $truncated
            ? mb_substr($full, 0, $displayMax)."\n\n… (показ обрезан до {$displayMax} символов; в запросе к модели действует отдельный лимит, при большом объёме возможна суммаризация.)"
            : $full;

        return response()->json([
            'text' => $text,
            'truncated' => $truncated,
            'counts' => $this->knowledgeTextFormatter->promptEntryCounts($companyId),
            'hint' => 'Учитываются только активные записи с включённым «В промпте».',
        ]);
    }

    public function bulkProductsPrompt(Request $request): JsonResponse
    {
        $data = $request->validate([
            'ids' => ['required', 'array', 'min:1', 'max:500'],
            'ids.*' => ['integer', 'distinct', 'exists:products,id'],
            'include_in_prompt' => ['required', 'boolean'],
        ]);

        Product::query()->whereIn('id', $data['ids'])->update(['include_in_prompt' => $data['include_in_prompt']]);

        return $this->bulkPromptResponse(
            Product::query()
                ->whereIn('id', $data['ids'])
                ->with('company:id,name')
                ->orderBy('id')
                ->get(),
        );
    }

    public function bulkServicesPrompt(Request $request): JsonResponse
    {
        $data = $request->validate([
            'ids' => ['required', 'array', 'min:1', 'max:500'],
            'ids.*' => ['integer', 'distinct', 'exists:services,id'],
            'include_in_prompt' => ['required', 'boolean'],
        ]);

        Service::query()->whereIn('id', $data['ids'])->update(['include_in_prompt' => $data['include_in_prompt']]);

        return $this->bulkPromptResponse(
            Service::query()
                ->whereIn('id', $data['ids'])
                ->with('company:id,name')
                ->orderBy('id')
                ->get(),
        );
    }

    public function bulkRulesPrompt(Request $request): JsonResponse
    {
        $data = $request->validate([
            'ids' => ['required', 'array', 'min:1', 'max:500'],
            'ids.*' => ['integer', 'distinct', 'exists:knowledge_rules,id'],
            'include_in_prompt' => ['required', 'boolean'],
        ]);

        KnowledgeRule::query()->whereIn('id', $data['ids'])->update(['include_in_prompt' => $data['include_in_prompt']]);

        return $this->bulkPromptResponse(
            KnowledgeRule::query()
                ->whereIn('id', $data['ids'])
                ->with('company:id,name')
                ->orderBy('id')
                ->get(),
        );
    }

    /**
     * @param  EloquentCollection<int, Model>  $models
     */
    private function bulkPromptResponse(EloquentCollection $models): JsonResponse
    {
        $items = $models->map(fn (Model $item) => $this->transform($item))->values()->all();

        return response()->json(['success' => true, 'items' => $items]);
    }

    private function render(string $section): Response
    {
        $companies = Company::query()->orderBy('name')->get(['id', 'name']);
        if ($companies->isEmpty()) {
            $companies = collect([
                Company::create([
                    'name' => 'Тестовая компания',
                    'description' => 'Компания по умолчанию для тестовой базы знаний',
                ])->only(['id', 'name']),
            ]);
        }

        return Inertia::render('Settings/KnowledgeBase', [
            'section' => $section,
            'items' => $this->itemsFor($section),
            'companies' => $companies,
        ]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function itemsFor(string $section): array
    {
        $query = match ($section) {
            'services' => Service::query(),
            'rules' => KnowledgeRule::query(),
            default => Product::query(),
        };

        return $query
            ->with('company:id,name')
            ->orderBy('company_id')
            ->orderBy($section === 'rules' ? 'priority' : 'sort_order')
            ->orderBy('id')
            ->get()
            ->map(fn (Model $item) => $this->transform($item))
            ->values()
            ->all();
    }

    /** @param array<string, mixed> $data */
    private function productPayload(array $data): array
    {
        return [
            'company_id' => (int) $data['company_id'],
            'name' => trim((string) $data['name']),
            'sku' => $this->nullableString($data['sku'] ?? null),
            'description' => $this->nullableString($data['description'] ?? null),
            'price' => $data['price'] ?? null,
            'attributes' => $data['attributes'] ?? null,
            'is_active' => (bool) ($data['is_active'] ?? true),
            'include_in_prompt' => (bool) ($data['include_in_prompt'] ?? true),
            'sort_order' => (int) ($data['sort_order'] ?? 0),
        ];
    }

    /** @param array<string, mixed> $data */
    private function servicePayload(array $data): array
    {
        return [
            'company_id' => (int) $data['company_id'],
            'name' => trim((string) $data['name']),
            'description' => $this->nullableString($data['description'] ?? null),
            'duration_minutes' => $data['duration_minutes'] ?? null,
            'price' => $data['price'] ?? null,
            'conditions' => $data['conditions'] ?? null,
            'is_active' => (bool) ($data['is_active'] ?? true),
            'include_in_prompt' => (bool) ($data['include_in_prompt'] ?? true),
            'sort_order' => (int) ($data['sort_order'] ?? 0),
        ];
    }

    /** @param array<string, mixed> $data */
    private function rulePayload(array $data): array
    {
        return [
            'company_id' => (int) $data['company_id'],
            'title' => trim((string) $data['title']),
            'type' => $this->nullableString($data['type'] ?? null) ?? 'general',
            'content' => trim((string) ($data['content'] ?? '')),
            'priority' => (int) ($data['priority'] ?? 100),
            'is_active' => (bool) ($data['is_active'] ?? true),
            'include_in_prompt' => (bool) ($data['include_in_prompt'] ?? true),
        ];
    }

    /** @return array<string, mixed> */
    private function transform(?Model $item): array
    {
        if ($item === null) {
            return [];
        }

        $item->loadMissing('company:id,name');

        return [
            ...$item->toArray(),
            'company' => $item->getRelation('company'),
        ];
    }

    private function nullableString(mixed $value): ?string
    {
        $value = trim((string) ($value ?? ''));

        return $value !== '' ? $value : null;
    }
}
