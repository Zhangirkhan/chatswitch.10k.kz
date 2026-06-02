<?php

declare(strict_types=1);

namespace App\Services\AI;

use App\Models\Company;
use App\Tenancy\TenantContext;

/**
 * Выбор модели OpenAI: демо-тенант может использовать более мощную модель (GPT-5.5),
 * остальные — базовую из OPENAI_MODEL.
 */
final class OpenAiModelResolver
{
    public function chatModel(?int $companyId = null): string
    {
        if ($this->isDemo($companyId)) {
            return (string) config('services.openai.demo_model', 'gpt-5.5');
        }

        return (string) config('services.openai.model', 'gpt-4o-mini');
    }

    public function isDemo(?int $companyId = null): bool
    {
        $demoSlug = $this->demoSlug();

        if ($companyId !== null && $companyId > 0) {
            $slug = Company::query()
                ->withoutGlobalScope('tenant')
                ->whereKey($companyId)
                ->value('slug');

            if (is_string($slug) && $slug !== '') {
                return $slug === $demoSlug;
            }
        }

        $context = app(TenantContext::class);

        return $context->slug() === $demoSlug;
    }

    /**
     * Для демо снимаем «потолок» 4o-mini: удваиваем запрошенный лимит с верхней границей.
     */
    public function maxTokens(?int $companyId, ?int $requested): int
    {
        $requested ??= (int) config('services.openai.default_max_tokens', 900);

        if (! $this->isDemo($companyId)) {
            return $requested;
        }

        $cap = (int) config('services.openai.demo_max_tokens_cap', 4096);
        $multiplier = (float) config('services.openai.demo_max_tokens_multiplier', 2.0);

        return (int) min(max($requested, (int) round($requested * $multiplier)), $cap);
    }

    public function requestTimeout(?int $companyId): int
    {
        if ($this->isDemo($companyId)) {
            return (int) config('services.openai.demo_timeout', 90);
        }

        return (int) config('services.openai.timeout', 45);
    }

    private function demoSlug(): string
    {
        return (string) config('services.openai.demo_slug', config('tenancy.fallback_slug', 'demo'));
    }
}
