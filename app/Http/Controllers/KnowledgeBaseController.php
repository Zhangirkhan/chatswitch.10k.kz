<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\KnowledgeBase\KnowledgeItemRequest;
use App\Models\Company;
use App\Models\KnowledgeAuditLog;
use App\Models\KnowledgeRule;
use App\Models\Product;
use App\Models\Service;
use App\Models\SystemSetting;
use App\Services\AI\KnowledgeContextTextFormatter;
use App\Services\Knowledge\KnowledgeAuditRecorder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Inertia\Inertia;
use Inertia\Response;

final class KnowledgeBaseController extends Controller
{
    public function __construct(
        private readonly KnowledgeContextTextFormatter $knowledgeTextFormatter,
        private readonly KnowledgeAuditRecorder $knowledgeAudit,
    ) {}

    public function products(): Response
    {
        $this->ensureSectionEnabled('products');

        return $this->render('products');
    }

    public function services(): Response
    {
        $this->ensureSectionEnabled('services');

        return $this->render('services');
    }

    public function rules(): Response
    {
        $this->ensureSectionEnabled('rules');

        return $this->render('rules');
    }

    public function storeProduct(KnowledgeItemRequest $request): JsonResponse
    {
        $this->ensureSectionEnabled('products');
        $product = Product::create($this->productPayload($request->validated()));
        $this->knowledgeAudit->recordCreated($product->fresh(), 'product', $request->user());

        return response()->json(['success' => true, 'item' => $this->transform($product)]);
    }

    public function updateProduct(KnowledgeItemRequest $request, Product $product): JsonResponse
    {
        $this->ensureSectionEnabled('products');
        $before = $this->knowledgeAudit->snapshot($product, 'product');
        $product->update($this->productPayload($request->validated()));
        $product->refresh();
        $after = $this->knowledgeAudit->snapshot($product, 'product');
        $this->knowledgeAudit->recordUpdated($product, 'product', $request->user(), $before, $after);

        return response()->json(['success' => true, 'item' => $this->transform($product)]);
    }

    public function destroyProduct(Request $request, Product $product): JsonResponse
    {
        $this->ensureSectionEnabled('products');
        $this->knowledgeAudit->recordDeleted($product, 'product', $request->user());
        $product->delete();

        return response()->json(['success' => true]);
    }

    public function storeService(KnowledgeItemRequest $request): JsonResponse
    {
        $this->ensureSectionEnabled('services');
        $service = Service::create($this->servicePayload($request->validated()));
        $this->knowledgeAudit->recordCreated($service->fresh(), 'service', $request->user());

        return response()->json(['success' => true, 'item' => $this->transform($service)]);
    }

    public function updateService(KnowledgeItemRequest $request, Service $service): JsonResponse
    {
        $this->ensureSectionEnabled('services');
        $before = $this->knowledgeAudit->snapshot($service, 'service');
        $service->update($this->servicePayload($request->validated()));
        $service->refresh();
        $after = $this->knowledgeAudit->snapshot($service, 'service');
        $this->knowledgeAudit->recordUpdated($service, 'service', $request->user(), $before, $after);

        return response()->json(['success' => true, 'item' => $this->transform($service)]);
    }

    public function destroyService(Request $request, Service $service): JsonResponse
    {
        $this->ensureSectionEnabled('services');
        $this->knowledgeAudit->recordDeleted($service, 'service', $request->user());
        $service->delete();

        return response()->json(['success' => true]);
    }

    public function storeRule(KnowledgeItemRequest $request): JsonResponse
    {
        $this->ensureSectionEnabled('rules');
        $rule = KnowledgeRule::create($this->rulePayload($request->validated()));
        $this->knowledgeAudit->recordCreated($rule->fresh(), 'rule', $request->user());

        return response()->json(['success' => true, 'item' => $this->transform($rule)]);
    }

    public function updateRule(KnowledgeItemRequest $request, KnowledgeRule $rule): JsonResponse
    {
        $this->ensureSectionEnabled('rules');
        $before = $this->knowledgeAudit->snapshot($rule, 'rule');
        $rule->update($this->rulePayload($request->validated()));
        $rule->refresh();
        $after = $this->knowledgeAudit->snapshot($rule, 'rule');
        $this->knowledgeAudit->recordUpdated($rule, 'rule', $request->user(), $before, $after);

        return response()->json(['success' => true, 'item' => $this->transform($rule)]);
    }

    public function destroyRule(Request $request, KnowledgeRule $rule): JsonResponse
    {
        $this->ensureSectionEnabled('rules');
        $this->knowledgeAudit->recordDeleted($rule, 'rule', $request->user());
        $rule->delete();

        return response()->json(['success' => true]);
    }

    public function audit(Request $request): JsonResponse
    {
        if (! Schema::hasTable('knowledge_audit_logs')) {
            return response()->json([
                'data' => [],
                'current_page' => 1,
                'last_page' => 1,
                'per_page' => 30,
                'total' => 0,
            ]);
        }

        $validated = $request->validate([
            'company_id' => ['required', 'integer', 'exists:companies,id'],
            'entity_type' => ['nullable', 'string', 'in:product,service,rule'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $query = KnowledgeAuditLog::query()
            ->where('company_id', (int) $validated['company_id'])
            ->with('user:id,name')
            ->orderByDesc('id');

        if (! empty($validated['entity_type'])) {
            $query->where('entity_type', $validated['entity_type']);
        }

        $paginator = $query->paginate((int) ($validated['per_page'] ?? 30));

        return response()->json($paginator);
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
        $this->ensureSectionEnabled('products');
        $data = $request->validate([
            'ids' => ['required', 'array', 'min:1', 'max:500'],
            'ids.*' => ['integer', 'distinct', 'exists:products,id'],
            'include_in_prompt' => ['required', 'boolean'],
        ]);

        $models = Product::query()->whereIn('id', $data['ids'])->get();
        $beforeById = [];
        foreach ($models as $model) {
            $beforeById[$model->id] = $this->knowledgeAudit->snapshot($model, 'product');
        }

        Product::query()->whereIn('id', $data['ids'])->update(['include_in_prompt' => $data['include_in_prompt']]);

        $fresh = Product::query()->whereIn('id', $data['ids'])->get()->keyBy('id');
        $bulkRows = [];
        foreach ($models as $model) {
            $current = $fresh->get($model->id);
            if ($current === null) {
                continue;
            }

            $bulkRows[] = [
                'model' => $current,
                'before' => $beforeById[$model->id] ?? [],
                'after' => $this->knowledgeAudit->snapshot($current, 'product'),
            ];
        }
        $this->knowledgeAudit->recordBulkPromptFlag('product', $request->user(), $bulkRows);

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
        $this->ensureSectionEnabled('services');
        $data = $request->validate([
            'ids' => ['required', 'array', 'min:1', 'max:500'],
            'ids.*' => ['integer', 'distinct', 'exists:services,id'],
            'include_in_prompt' => ['required', 'boolean'],
        ]);

        $models = Service::query()->whereIn('id', $data['ids'])->get();
        $beforeById = [];
        foreach ($models as $model) {
            $beforeById[$model->id] = $this->knowledgeAudit->snapshot($model, 'service');
        }

        Service::query()->whereIn('id', $data['ids'])->update(['include_in_prompt' => $data['include_in_prompt']]);

        $fresh = Service::query()->whereIn('id', $data['ids'])->get()->keyBy('id');
        $bulkRows = [];
        foreach ($models as $model) {
            $current = $fresh->get($model->id);
            if ($current === null) {
                continue;
            }

            $bulkRows[] = [
                'model' => $current,
                'before' => $beforeById[$model->id] ?? [],
                'after' => $this->knowledgeAudit->snapshot($current, 'service'),
            ];
        }
        $this->knowledgeAudit->recordBulkPromptFlag('service', $request->user(), $bulkRows);

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
        $this->ensureSectionEnabled('rules');
        $data = $request->validate([
            'ids' => ['required', 'array', 'min:1', 'max:500'],
            'ids.*' => ['integer', 'distinct', 'exists:knowledge_rules,id'],
            'include_in_prompt' => ['required', 'boolean'],
        ]);

        $models = KnowledgeRule::query()->whereIn('id', $data['ids'])->get();
        $beforeById = [];
        foreach ($models as $model) {
            $beforeById[$model->id] = $this->knowledgeAudit->snapshot($model, 'rule');
        }

        KnowledgeRule::query()->whereIn('id', $data['ids'])->update(['include_in_prompt' => $data['include_in_prompt']]);

        $fresh = KnowledgeRule::query()->whereIn('id', $data['ids'])->get()->keyBy('id');
        $bulkRows = [];
        foreach ($models as $model) {
            $current = $fresh->get($model->id);
            if ($current === null) {
                continue;
            }

            $bulkRows[] = [
                'model' => $current,
                'before' => $beforeById[$model->id] ?? [],
                'after' => $this->knowledgeAudit->snapshot($current, 'rule'),
            ];
        }
        $this->knowledgeAudit->recordBulkPromptFlag('rule', $request->user(), $bulkRows);

        return $this->bulkPromptResponse(
            KnowledgeRule::query()
                ->whereIn('id', $data['ids'])
                ->with('company:id,name')
                ->orderBy('id')
                ->get(),
        );
    }

    /**
     * @param  'products'|'services'|'rules'  $section
     */
    private function ensureSectionEnabled(string $section): void
    {
        [$key, $label] = match ($section) {
            'products' => ['module_products', 'Товары'],
            'services' => ['module_services', 'Услуги'],
            'rules' => ['module_knowledge', 'База знаний'],
        };

        abort_unless(
            SystemSetting::getValue($key, 'on') === 'on',
            403,
            "Модуль «{$label}» отключён администратором.",
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
