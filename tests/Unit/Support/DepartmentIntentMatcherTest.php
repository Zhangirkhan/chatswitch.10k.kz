<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use App\Support\DepartmentIntentMatcher;
use Tests\TestCase;

final class DepartmentIntentMatcherTest extends TestCase
{
    public function test_matches_accounting_when_client_asks_for_accountant(): void
    {
        $matcher = new DepartmentIntentMatcher();
        $catalog = [
            ['id' => 1, 'name' => 'HR-отдел', 'description' => 'Кадры и персонал', 'funnels' => []],
            ['id' => 2, 'name' => 'Бухгалтерия', 'description' => 'Счета, оплата, акты', 'funnels' => []],
        ];

        $match = $matcher->match('здравствуйте, свяжите с бухгалтером', $catalog);

        $this->assertNotNull($match);
        $this->assertSame(2, $match['id']);
    }

    public function test_does_not_match_hr_for_accountant_request(): void
    {
        $matcher = new DepartmentIntentMatcher();
        $catalog = [
            ['id' => 1, 'name' => 'HR-отдел', 'description' => 'Кадры', 'funnels' => []],
            ['id' => 2, 'name' => 'Отдел продаж', 'description' => 'Консультации', 'funnels' => []],
        ];

        $this->assertNull($matcher->match('свяжите с бухгалтером', $catalog));
    }
}
