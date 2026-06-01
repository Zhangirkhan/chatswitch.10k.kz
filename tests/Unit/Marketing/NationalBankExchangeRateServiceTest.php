<?php

declare(strict_types=1);

namespace Tests\Unit\Marketing;

use App\Services\Marketing\NationalBankExchangeRateService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class NationalBankExchangeRateServiceTest extends TestCase
{
    public function test_fetches_usd_rate_from_nbk_xml(): void
    {
        Cache::forget('marketing.nbk_usd_kzt');

        Http::fake([
            'nationalbank.kz/*' => Http::response(<<<'XML'
<?xml version="1.0" encoding="utf-8"?>
<rates>
    <item>
        <title>USD</title>
        <description>485.55</description>
        <quant>1</quant>
    </item>
</rates>
XML),
        ]);

        $rate = app(NationalBankExchangeRateService::class)->usdToKzt();

        $this->assertSame(485.55, $rate['rate']);
        $this->assertSame('nbk', $rate['source']);
    }

    public function test_falls_back_when_nbk_unavailable(): void
    {
        Cache::forget('marketing.nbk_usd_kzt');

        Http::fake([
            'nationalbank.kz/*' => Http::response('', 500),
        ]);

        $rate = app(NationalBankExchangeRateService::class)->usdToKzt();

        $this->assertSame('fallback', $rate['source']);
        $this->assertGreaterThan(0, $rate['rate']);
    }
}
