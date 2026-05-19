<?php

declare(strict_types=1);

namespace Tests\Unit\Funnel;

use App\Services\Funnel\ChatFunnelCatalogBuilder;
use PHPUnit\Framework\TestCase;

final class ChatFunnelCatalogStageMappingTest extends TestCase
{
    public function test_map_stage_preserves_index_when_switching_funnel(): void
    {
        $catalog = [
            [
                'id' => 1,
                'name' => 'A',
                'description' => null,
                'color' => '#111',
                'stages' => [
                    ['id' => 11, 'name' => 'A1', 'color' => '#111', 'position' => 0],
                    ['id' => 12, 'name' => 'A2', 'color' => '#222', 'position' => 1],
                    ['id' => 13, 'name' => 'A3', 'color' => '#333', 'position' => 2],
                ],
            ],
            [
                'id' => 2,
                'name' => 'B',
                'description' => null,
                'color' => '#444',
                'stages' => [
                    ['id' => 21, 'name' => 'B1', 'color' => '#444', 'position' => 0],
                    ['id' => 22, 'name' => 'B2', 'color' => '#555', 'position' => 1],
                ],
            ],
        ];

        $builder = new ChatFunnelCatalogBuilder;

        $this->assertSame(22, $builder->mapStagePreservingIndex($catalog, 1, 13, 2));
        $this->assertSame(21, $builder->mapStagePreservingIndex($catalog, 1, 11, 2));
    }

    public function test_map_stage_clamps_to_last_when_target_funnel_is_shorter(): void
    {
        $catalog = [
            [
                'id' => 1,
                'name' => 'A',
                'description' => null,
                'color' => '#111',
                'stages' => [
                    ['id' => 11, 'name' => 'A1', 'color' => '#111', 'position' => 0],
                    ['id' => 12, 'name' => 'A2', 'color' => '#222', 'position' => 1],
                    ['id' => 13, 'name' => 'A3', 'color' => '#333', 'position' => 2],
                ],
            ],
            [
                'id' => 2,
                'name' => 'B',
                'description' => null,
                'color' => '#444',
                'stages' => [
                    ['id' => 21, 'name' => 'B1', 'color' => '#444', 'position' => 0],
                ],
            ],
        ];

        $builder = new ChatFunnelCatalogBuilder;

        $this->assertSame(21, $builder->mapStagePreservingIndex($catalog, 1, 13, 2));
    }
}
