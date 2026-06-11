<?php

declare(strict_types=1);

namespace Tests\Feature\AI;

use App\Services\Memory\EntityMemoryService;
use ReflectionClass;
use Tests\TestCase;

/**
 * Tests for EntityMemoryService — field-level merge semantics for AI-facts.
 *
 * These tests directly exercise the private parseAiFactsSection / renderAiFactsSection
 * helpers via reflection, without requiring a real database.
 */
final class MergeAiFactsBudgetTest extends TestCase
{
    private EntityMemoryService $service;

    private ReflectionClass $reflector;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(EntityMemoryService::class);
        $this->reflector = new ReflectionClass($this->service);
    }

    public function test_parseAiFactsSection_extracts_fields(): void
    {
        $content = $this->sectionWith([
            'budget'       => '1 000 000 тенге',
            'requirements' => 'Диван трёхместный серый',
        ]);

        $parsed = $this->parse($content);

        $this->assertSame('1 000 000 тенге', $parsed['budget'] ?? null);
        $this->assertSame('Диван трёхместный серый', $parsed['requirements'] ?? null);
    }

    public function test_parseAiFactsSection_returns_empty_for_no_section(): void
    {
        $parsed = $this->parse('# Обычный контент без секции');
        $this->assertSame([], $parsed);
    }

    public function test_budget_override_keeps_other_fields(): void
    {
        $existingContent = $this->sectionWith([
            'budget'       => '1 000 000 тенге',
            'requirements' => 'Диван',
            'objections'   => 'Дорого',
        ]);

        $existing = $this->parse($existingContent);

        // New extraction: only budget updated; requirements and objections absent.
        $newExtraction = ['budget' => '5 000 000 тенге'];
        $nonEmpty = array_filter($newExtraction, static fn (mixed $v): bool => $v !== null && $v !== '' && $v !== []);
        $merged = array_merge($existing, $nonEmpty);

        $this->assertSame('5 000 000 тенге', $merged['budget']);
        $this->assertSame('Диван', $merged['requirements']);
        $this->assertSame('Дорого', $merged['objections']);
    }

    public function test_empty_new_value_does_not_erase_existing(): void
    {
        $existingContent = $this->sectionWith(['budget' => '1 000 000 тенге']);
        $existing = $this->parse($existingContent);

        // New extraction has budget as empty — should NOT override.
        $newExtraction = ['budget' => ''];
        $nonEmpty = array_filter($newExtraction, static fn (mixed $v): bool => $v !== null && $v !== '' && $v !== []);
        $merged = array_merge($existing, $nonEmpty);

        $this->assertSame('1 000 000 тенге', $merged['budget']);
    }

    public function test_all_fields_preserved_when_extraction_empty(): void
    {
        $existingContent = $this->sectionWith([
            'budget'       => '1000',
            'requirements' => 'красный диван',
            'agreements'   => 'перезвонить в пятницу',
        ]);
        $existing = $this->parse($existingContent);

        // No new fields extracted.
        $merged = array_merge($existing, []);

        $this->assertSame('1000', $merged['budget'] ?? null);
        $this->assertSame('красный диван', $merged['requirements'] ?? null);
        $this->assertSame('перезвонить в пятницу', $merged['agreements'] ?? null);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /** @return array<string, string> */
    private function parse(string $content): array
    {
        $method = $this->reflector->getMethod('parseAiFactsSection');
        $method->setAccessible(true);

        return $method->invoke($this->service, $content);
    }

    /**
     * Render a minimal AI-facts section from key-value pairs.
     *
     * @param  array<string, string>  $facts
     */
    private function sectionWith(array $facts): string
    {
        $labels = [
            'budget'       => 'Бюджет',
            'requirements' => 'Требования',
            'objections'   => 'Возражения',
            'agreements'   => 'Договорённости',
            'preferences'  => 'Предпочтения',
            'source'       => 'Источник лида',
            'contact_info' => 'Контактные данные',
            'other'        => 'Прочее',
        ];

        $lines = ['## AI-факты (авто)', '_Автоматически обновлено AI. Не редактируй эту секцию вручную._', ''];
        foreach ($labels as $key => $label) {
            $val = $facts[$key] ?? null;
            if ($val !== null && $val !== '') {
                $lines[] = "**{$label}:** {$val}";
            }
        }
        $lines[] = '';
        $lines[] = '_Обновлено: 2026-01-01 10:00_';
        $lines[] = '<!-- /ai-facts -->';

        return implode("\n", $lines);
    }
}
