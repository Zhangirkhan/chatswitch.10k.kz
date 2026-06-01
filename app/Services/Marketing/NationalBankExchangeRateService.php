<?php

declare(strict_types=1);

namespace App\Services\Marketing;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

final class NationalBankExchangeRateService
{
    private const CACHE_KEY = 'marketing.nbk_usd_kzt';

    public function __construct(
        private readonly CacheRepository $cache,
    ) {}

    /**
     * @return array{rate: float, date: string, source: string}
     */
    public function usdToKzt(): array
    {
        $cached = $this->cache->get(self::CACHE_KEY);
        if (is_array($cached) && isset($cached['rate'], $cached['date'])) {
            return [
                'rate' => (float) $cached['rate'],
                'date' => (string) $cached['date'],
                'source' => (string) ($cached['source'] ?? 'nbk'),
            ];
        }

        $fetched = $this->fetchFromNbk();
        if ($fetched !== null) {
            $this->cache->put(self::CACHE_KEY, $fetched, now()->addHours(6));

            return $fetched;
        }

        $fallback = (float) config('ai_calculator.pricing.usd_to_kzt', 510);

        return [
            'rate' => $fallback,
            'date' => now()->format('d.m.Y'),
            'source' => 'fallback',
        ];
    }

    /**
     * @return array{rate: float, date: string, source: string}|null
     */
    private function fetchFromNbk(): ?array
    {
        $date = now()->format('d.m.Y');

        try {
            $response = Http::timeout(10)
                ->get('https://nationalbank.kz/rss/get_rates.cfm', ['fdate' => $date]);

            if (! $response->successful()) {
                return null;
            }

            $xml = @simplexml_load_string($response->body());
            if ($xml === false) {
                return null;
            }

            foreach ($xml->item as $item) {
                if ((string) ($item->title ?? '') !== 'USD') {
                    continue;
                }

                $description = (float) str_replace(',', '.', (string) ($item->description ?? '0'));
                $quant = max(1, (int) ($item->quant ?? 1));
                $rate = round($description / $quant, 2);

                if ($rate <= 0) {
                    return null;
                }

                return [
                    'rate' => $rate,
                    'date' => $date,
                    'source' => 'nbk',
                ];
            }
        } catch (Throwable $e) {
            Log::warning('[nbk-rate] failed to fetch USD/KZT', ['error' => $e->getMessage()]);
        }

        return null;
    }
}
