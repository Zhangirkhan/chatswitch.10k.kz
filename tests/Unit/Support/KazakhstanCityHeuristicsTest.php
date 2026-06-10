<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use App\Support\KazakhstanCityHeuristics;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class KazakhstanCityHeuristicsTest extends TestCase
{
    #[Test]
    public function it_detects_delivery_destination_with_city(): void
    {
        $this->assertTrue(KazakhstanCityHeuristics::isDeliveryDestinationStatement('В Караганду мне надо'));
        $this->assertTrue(KazakhstanCityHeuristics::mentionsCity('доставка в Алматы'));
    }

    #[Test]
    public function it_detects_price_negotiation(): void
    {
        $this->assertTrue(KazakhstanCityHeuristics::isPriceNegotiation('Набор 6в1 можно за 90000?'));
        $this->assertFalse(KazakhstanCityHeuristics::isPriceNegotiation('Здравствуйте'));
    }
}
