<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use App\Support\ClientProfileFieldHelper;
use PHPUnit\Framework\TestCase;

final class ClientProfileFieldHelperTest extends TestCase
{
    public function test_detects_duplicate_label(): void
    {
        $fields = [
            ['label' => 'Имя', 'value' => 'sany', 'source' => 'crm'],
        ];

        $this->assertTrue(ClientProfileFieldHelper::isDuplicate($fields, [
            'label' => 'Имя',
            'value' => 'sany',
            'source' => 'ai',
        ]));
    }

    public function test_detects_overlapping_address_values(): void
    {
        $fields = [
            ['label' => 'Адрес', 'value' => 'мой адрес геодезическая 12', 'source' => 'chat'],
        ];

        $this->assertTrue(ClientProfileFieldHelper::isDuplicate($fields, [
            'label' => 'Адрес',
            'value' => 'геодезическая 12',
            'source' => 'ai',
        ]));
    }

    public function test_merge_unique_skips_duplicates(): void
    {
        $fields = [
            ['label' => 'Имя', 'value' => 'sany', 'source' => 'crm'],
        ];

        $merged = ClientProfileFieldHelper::mergeUnique($fields, [
            ['label' => 'Имя', 'value' => 'sany', 'source' => 'ai'],
            ['label' => 'Роль', 'value' => 'Администратор ESL', 'source' => 'ai'],
        ]);

        $this->assertCount(2, $merged);
        $this->assertSame('Роль', $merged[1]['label']);
    }
}
