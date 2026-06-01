<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Company;
use App\Models\CompanyPromotion;
use App\Support\TenantCompany;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

final class PromotionController extends Controller
{
    public function index(): Response
    {
        $companyId = TenantCompany::id();

        $promotions = CompanyPromotion::query()
            ->where('company_id', $companyId)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get()
            ->map(fn (CompanyPromotion $promo): array => $this->serialize($promo));

        return Inertia::render('Settings/Promotions', [
            'promotions' => $promotions,
            'ai_promotions_enabled' => (bool) (Company::query()
                ->whereKey(TenantCompany::id())
                ->value('ai_promotions_enabled') ?? true),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validated($request);
        $promo = CompanyPromotion::query()->create([
            ...$data,
            'company_id' => TenantCompany::id(),
        ]);

        return response()->json(['success' => true, 'promotion' => $this->serialize($promo)]);
    }

    public function update(Request $request, CompanyPromotion $promotion): JsonResponse
    {
        abort_if((int) $promotion->company_id !== TenantCompany::id(), 404);

        $promotion->update($this->validated($request));

        return response()->json(['success' => true, 'promotion' => $this->serialize($promotion->fresh())]);
    }

    public function destroy(CompanyPromotion $promotion): JsonResponse
    {
        abort_if((int) $promotion->company_id !== TenantCompany::id(), 404);

        $promotion->delete();

        return response()->json(['success' => true]);
    }

    public function updateSettings(Request $request): JsonResponse
    {
        $data = $request->validate([
            'ai_promotions_enabled' => ['required', 'boolean'],
        ]);

        Company::query()
            ->whereKey(TenantCompany::id())
            ->update(['ai_promotions_enabled' => (bool) $data['ai_promotions_enabled']]);

        return response()->json([
            'success' => true,
            'ai_promotions_enabled' => (bool) $data['ai_promotions_enabled'],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public static function serialize(CompanyPromotion $promo): array
    {
        return [
            'id' => $promo->id,
            'name' => $promo->name,
            'discount_type' => $promo->discount_type,
            'percent' => $promo->percent,
            'fixed_amount' => $promo->fixed_amount !== null ? (string) $promo->fixed_amount : null,
            'buy_quantity' => $promo->buy_quantity,
            'get_quantity' => $promo->get_quantity,
            'benefit_summary' => $promo->benefitSummary() ?: null,
            'valid_from' => $promo->valid_from?->format('Y-m-d'),
            'valid_until' => $promo->valid_until?->format('Y-m-d'),
            'conditions' => $promo->conditions,
            'is_active' => $promo->is_active,
            'sort_order' => $promo->sort_order,
            'is_currently_valid' => $promo->isCurrentlyValid(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:160'],
            'discount_type' => ['required', 'string', Rule::in(CompanyPromotion::types())],
            'percent' => ['nullable', 'integer', 'min:1', 'max:100'],
            'fixed_amount' => ['nullable', 'numeric', 'min:0'],
            'buy_quantity' => ['nullable', 'integer', 'min:1', 'max:99'],
            'get_quantity' => ['nullable', 'integer', 'min:1', 'max:99'],
            'valid_from' => ['nullable', 'date'],
            'valid_until' => ['nullable', 'date', 'after_or_equal:valid_from'],
            'conditions' => ['nullable', 'string', 'max:2000'],
            'is_active' => ['sometimes', 'boolean'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:9999'],
        ]);

        $type = (string) $data['discount_type'];

        return [
            'name' => trim((string) $data['name']),
            'discount_type' => $type,
            'percent' => $type === CompanyPromotion::TYPE_PERCENT
                ? max(1, min(100, (int) ($data['percent'] ?? 0)))
                : null,
            'fixed_amount' => $type === CompanyPromotion::TYPE_FIXED
                ? ($data['fixed_amount'] ?? null)
                : null,
            'buy_quantity' => $type === CompanyPromotion::TYPE_BOGO
                ? max(1, min(99, (int) ($data['buy_quantity'] ?? 1)))
                : null,
            'get_quantity' => $type === CompanyPromotion::TYPE_BOGO
                ? max(1, min(99, (int) ($data['get_quantity'] ?? 1)))
                : null,
            'valid_from' => $data['valid_from'] ?? null,
            'valid_until' => $data['valid_until'] ?? null,
            'conditions' => isset($data['conditions']) ? trim((string) $data['conditions']) : null,
            'is_active' => (bool) ($data['is_active'] ?? true),
            'sort_order' => (int) ($data['sort_order'] ?? 0),
        ];
    }
}
