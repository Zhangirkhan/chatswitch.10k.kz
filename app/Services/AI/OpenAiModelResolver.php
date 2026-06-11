<?php

declare(strict_types=1);

namespace App\Services\AI;

use App\Models\Company;
use App\Tenancy\TenantContext;

/**
 * Параметры OpenAI-запросов: модель из OPENAI_MODEL, для demo — повышенные лимиты.
 *
 * Per-task model overrides: add keys like services.openai.models.funnel_orchestrator
 * (or env OPENAI_MODEL_FUNNEL_ORCHESTRATOR) to use a different model for a specific
 * scenario without changing the global default.
 *
 * Supported scenarios: ai_reply, funnel_orchestrator, funnel_classify, memory_extraction,
 * history_compress, dept_routing, background, appointment_intent, operator_assistant,
 * workspace_query, workspace_client_summary, translation.
 */
final class OpenAiModelResolver
{
    /**
     * Return the best model for a given usage scenario.
     * Falls back to the global default when no per-task override is configured.
     */
    public function chatModel(?int $companyId = null, ?string $scenario = null): string
    {
        if ($scenario !== null) {
            $key = 'services.openai.models.'.str_replace(['-', ' '], '_', $scenario);
            $taskModel = (string) config($key, '');
            if ($taskModel !== '') {
                return $taskModel;
            }
        }

        return (string) config('services.openai.model', 'gpt-5.5');
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
     * Для demo удваиваем запрошенный лимит с верхней границей.
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

        return (int) config('services.openai.timeout', 90);
    }

    public function usesMaxCompletionTokens(?int $companyId = null): bool
    {
        $configured = config('services.openai.use_max_completion_tokens');
        if ($configured !== null) {
            return filter_var($configured, FILTER_VALIDATE_BOOL);
        }

        $model = mb_strtolower(trim($this->chatModel($companyId)));

        return preg_match('/^(o[0-9]|gpt-4\.1|gpt-5)/', $model) === 1;
    }

    public function supportsCustomTemperature(?int $companyId = null): bool
    {
        $configured = config('services.openai.supports_custom_temperature');
        if ($configured !== null) {
            return filter_var($configured, FILTER_VALIDATE_BOOL);
        }

        $model = mb_strtolower(trim($this->chatModel($companyId)));

        return preg_match('/^(o[0-9]|gpt-5)/', $model) !== 1;
    }

    private function demoSlug(): string
    {
        return (string) config('services.openai.demo_slug', config('tenancy.fallback_slug', 'demo'));
    }
}
