<?php

declare(strict_types=1);

namespace App\Services\Knowledge;

use App\Models\KnowledgeRule;
use App\Models\Product;
use App\Models\Service;
use App\Services\AI\OpenAiChatService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

final class KnowledgeCatalogLlmAuditService
{
    private const CACHE_TTL_SECONDS = 600;

    public function __construct(
        private readonly OpenAiChatService $openAi,
    ) {}

    /**
     * @return list<array{key: string, severity: string, category: string, title: string, description: string, action: string}>
     */
    public function audit(int $companyId, bool $forceRefresh = false): array
    {
        if ((string) config('services.openai.api_key') === '') {
            return [];
        }

        $cacheKey = "knowledge_catalog_llm_audit:{$companyId}";

        if (! $forceRefresh) {
            $cached = Cache::get($cacheKey);
            if (is_array($cached)) {
                return $cached;
            }
        }

        $catalog = $this->buildCatalogDigest($companyId);
        if ($catalog === '') {
            return [];
        }

        try {
            $decoded = $this->openAi->chatJson([
                [
                    'role' => 'system',
                    'content' => 'Ты аудитор базы знаний CRM. Ищи противоречия, дубли по смыслу, устаревшие или неполные формулировки. Отвечай строго JSON: {"findings":[{"severity":"critical|warning|info","title":"...","description":"...","action":"..."}]}. Не более 8 findings. Язык: русский.',
                ],
                [
                    'role' => 'user',
                    'content' => "Проверь каталог компании:\n\n{$catalog}",
                ],
            ], 0.2, 1200, new \App\Services\AI\AiUsageOptions('background', $companyId));

            $findings = $this->normalizeFindings($decoded['findings'] ?? []);
            Cache::put($cacheKey, $findings, self::CACHE_TTL_SECONDS);

            return $findings;
        } catch (Throwable $e) {
            Log::warning('[knowledge-audit-llm] failed', [
                'company_id' => $companyId,
                'error' => $e->getMessage(),
            ]);

            return [];
        }
    }

    private function buildCatalogDigest(int $companyId): string
    {
        $lines = [];

        foreach (Product::query()->where('company_id', $companyId)->where('include_in_prompt', true)->where('is_active', true)->limit(40)->get() as $product) {
            $lines[] = 'Товар: '.$product->name
                .($product->price !== null ? ' | '.$product->price.' KZT' : '')
                .($product->description ? ' | '.Str::limit((string) $product->description, 120) : '');
        }

        foreach (Service::query()->where('company_id', $companyId)->where('include_in_prompt', true)->where('is_active', true)->limit(30)->get() as $service) {
            $lines[] = 'Услуга: '.$service->name
                .($service->price !== null ? ' | '.$service->price.' KZT' : '')
                .($service->description ? ' | '.Str::limit((string) $service->description, 120) : '');
        }

        foreach (KnowledgeRule::query()->where('company_id', $companyId)->where('include_in_prompt', true)->where('is_active', true)->orderBy('priority')->limit(25)->get() as $rule) {
            $lines[] = 'Правило ['.$rule->type.']: '.$rule->title.' — '.Str::limit((string) $rule->content, 160);
        }

        return Str::limit(implode("\n", $lines), 12000, '…');
    }

    /**
     * @return list<array{key: string, severity: string, category: string, title: string, description: string, action: string}>
     */
    private function normalizeFindings(mixed $raw): array
    {
        if (! is_array($raw)) {
            return [];
        }

        $findings = [];
        foreach ($raw as $index => $row) {
            if (! is_array($row)) {
                continue;
            }

            $title = trim((string) ($row['title'] ?? ''));
            if ($title === '') {
                continue;
            }

            $severity = strtolower(trim((string) ($row['severity'] ?? 'info')));
            if (! in_array($severity, ['critical', 'warning', 'info'], true)) {
                $severity = 'info';
            }

            $findings[] = [
                'key' => 'llm_'.($index + 1),
                'severity' => $severity,
                'category' => 'AI-анализ',
                'title' => $title,
                'description' => trim((string) ($row['description'] ?? '')),
                'action' => trim((string) ($row['action'] ?? 'Уточните формулировки в базе знаний.')),
            ];
        }

        return array_slice($findings, 0, 8);
    }
}
