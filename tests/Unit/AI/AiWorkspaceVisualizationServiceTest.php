<?php

declare(strict_types=1);

namespace Tests\Unit\AI;

use App\Services\AI\AiWorkspaceVisualizationService;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class AiWorkspaceVisualizationServiceTest extends TestCase
{
    private AiWorkspaceVisualizationService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(AiWorkspaceVisualizationService::class);
    }

    public function test_normalizes_chart_from_ai_payload(): void
    {
        $items = $this->service->resolve(
            [
                [
                    'type' => 'chart',
                    'title' => 'Тест',
                    'chart_type' => 'bar',
                    'labels' => ['A', 'B'],
                    'datasets' => [['label' => 'Серия', 'data' => [2, 5]]],
                ],
            ],
            'покажи график',
            [],
            [],
            false,
            false,
        );

        $this->assertCount(1, $items);
        $this->assertSame('chart', $items[0]['type']);
        $this->assertSame('bar', $items[0]['chart_type']);
        $this->assertSame(['A', 'B'], $items[0]['labels']);
    }

    public function test_normalizes_mermaid_from_ai_payload(): void
    {
        $items = $this->service->resolve(
            [
                [
                    'type' => 'mermaid',
                    'title' => 'Процесс',
                    'code' => "flowchart TD\n  A[Клиент] --> B[Менеджер]",
                ],
            ],
            'схема процесса',
            [],
            [],
            false,
            false,
        );

        $this->assertCount(1, $items);
        $this->assertSame('mermaid', $items[0]['type']);
        $this->assertStringContainsString('flowchart TD', (string) $items[0]['code']);
    }

    public function test_strips_mermaid_code_fences(): void
    {
        $items = $this->service->resolve(
            [
                [
                    'type' => 'mermaid',
                    'code' => "```mermaid\nflowchart TD\n  A --> B\n```",
                ],
            ],
            'схема',
            [],
            [],
            false,
            false,
        );

        $this->assertCount(1, $items);
        $this->assertSame("flowchart TD\n  A --> B", $items[0]['code']);
    }

    public function test_builds_chart_from_media_results_when_user_asks_for_graph(): void
    {
        $media = [
            ['mime_type' => 'application/pdf', 'message_at' => '2026-05-01T10:00:00Z'],
            ['mime_type' => 'image/jpeg', 'message_at' => '2026-05-02T10:00:00Z'],
            ['mime_type' => 'image/png', 'message_at' => '2026-05-02T12:00:00Z'],
        ];

        $items = $this->service->resolve([], 'покажи график файлов по типам', [], $media, false, true);

        $this->assertNotEmpty($items);
        $this->assertSame('chart', $items[0]['type']);
        $this->assertContains('PDF', $items[0]['labels']);
        $this->assertContains('Фото', $items[0]['labels']);
    }

    public function test_prefers_data_chart_over_ai_mermaid_for_diagram_request(): void
    {
        $contacts = [
            ['name' => 'Nexo', 'unread_count' => 3],
            ['name' => 'sany', 'unread_count' => 1],
        ];

        $items = $this->service->resolve(
            [
                [
                    'type' => 'mermaid',
                    'title' => 'Процесс работы с клиентами',
                    'code' => "flowchart TD\n  A[Клиент] --> B[Менеджер]",
                ],
            ],
            'Клиенты с непрочитанными — диаграмма',
            $contacts,
            [],
            true,
            false,
        );

        $this->assertCount(1, $items);
        $this->assertSame('chart', $items[0]['type']);
    }

    public function test_keeps_mermaid_for_explicit_process_diagram_request(): void
    {
        $items = $this->service->resolve(
            [
                [
                    'type' => 'mermaid',
                    'title' => 'Процесс',
                    'code' => "flowchart TD\n  A[Клиент] --> B[Менеджер]",
                ],
            ],
            'схема процесса работы с клиентами',
            [],
            [],
            false,
            false,
        );

        $this->assertCount(1, $items);
        $this->assertSame('mermaid', $items[0]['type']);
    }

    #[DataProvider('unsafeMermaidProvider')]
    public function test_rejects_unsafe_mermaid(string $code): void
    {
        $items = $this->service->resolve(
            [['type' => 'mermaid', 'code' => $code]],
            'схема',
            [],
            [],
            false,
            false,
        );

        $this->assertSame([], $items);
    }

    /**
     * @return list<array{string}>
     */
    public static function unsafeMermaidProvider(): array
    {
        return [
            ['<script>alert(1)</script>'],
            ["flowchart TD\n  click A \"javascript:alert(1)\""],
        ];
    }
}
