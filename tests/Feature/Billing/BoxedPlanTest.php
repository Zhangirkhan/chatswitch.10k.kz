<?php

declare(strict_types=1);

namespace Tests\Feature\Billing;

use App\Models\Plan;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class BoxedPlanTest extends TestCase
{
    use RefreshDatabase;

    public function test_boxed_plan_is_seeded_by_migration(): void
    {
        $plan = Plan::query()->where('code', 'boxed')->first();

        $this->assertNotNull($plan);
        $this->assertSame('Коробочная установка', $plan->name);
        $this->assertSame(100_000_000, $plan->price_cents);
        $this->assertSame('KZT', $plan->currency);
        $this->assertSame('once', $plan->interval);
        $this->assertSame(0, $plan->trial_days);
        $this->assertTrue($plan->is_active);
        $this->assertTrue($plan->isOneTime());
        $this->assertSame('1 000 000 ₸ разово', $plan->priceLabel());
    }
}
