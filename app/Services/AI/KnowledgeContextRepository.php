<?php

declare(strict_types=1);

namespace App\Services\AI;

use App\Models\KnowledgeRule;
use App\Models\Product;
use App\Models\Service;

final class KnowledgeContextRepository
{
    /** @return array{products: array<int, Product>, services: array<int, Service>, rules: array<int, KnowledgeRule>} */
    public function forPrompt(int $companyId): array
    {
        return [
            'products' => Product::query()
                ->where('company_id', $companyId)
                ->where('is_active', true)
                ->where('include_in_prompt', true)
                ->orderBy('sort_order')
                ->orderBy('id')
                ->limit(80)
                ->get()
                ->all(),
            'services' => Service::query()
                ->where('company_id', $companyId)
                ->where('is_active', true)
                ->where('include_in_prompt', true)
                ->orderBy('sort_order')
                ->orderBy('id')
                ->limit(80)
                ->get()
                ->all(),
            'rules' => KnowledgeRule::query()
                ->where('company_id', $companyId)
                ->where('is_active', true)
                ->where('include_in_prompt', true)
                ->orderBy('priority')
                ->orderBy('id')
                ->limit(60)
                ->get()
                ->all(),
        ];
    }
}
