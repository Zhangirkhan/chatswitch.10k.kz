<?php

declare(strict_types=1);

namespace Tests\Feature\Landing;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

final class CalculatorPageTest extends TestCase
{
    use RefreshDatabase;

    private function landingHost(): string
    {
        return config('tenancy.root_domain', 'accel.kz');
    }

    public function test_calculator_route_is_not_public(): void
    {
        $host = $this->landingHost();
        URL::forceRootUrl('https://'.$host);

        $this->withoutVite()
            ->withServerVariables(['HTTP_HOST' => $host])
            ->get("https://{$host}/calculator")
            ->assertNotFound();
    }
}
