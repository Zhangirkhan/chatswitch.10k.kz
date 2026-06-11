<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Plan;

final class LandingStructuredData
{
    /**
     * @param  array{locale: string, page: string, title: string, description: string, canonical_url: string}  $landingMeta
     * @return list<array<string, mixed>>
     */
    public static function graphs(array $landingMeta): array
    {
        $page = (string) ($landingMeta['page'] ?? 'home');
        $locale = (string) ($landingMeta['locale'] ?? 'kk');
        $baseUrl = LandingLocale::baseUrl();

        if ($page === 'faq') {
            return self::faqGraph($locale);
        }

        if ($page !== 'home') {
            return [];
        }

        $graphs = [
            self::organizationGraph($baseUrl),
            self::softwareApplicationGraph($landingMeta, $baseUrl),
        ];

        foreach (self::offerGraphs($locale, $baseUrl) as $offer) {
            $graphs[] = $offer;
        }

        return $graphs;
    }

    /** @return array<string, mixed> */
    private static function organizationGraph(string $baseUrl): array
    {
        return [
            '@context' => 'https://schema.org',
            '@type' => 'Organization',
            'name' => 'Accel',
            'url' => $baseUrl,
            'logo' => url('/icons/icon-512.png'),
            'email' => 'hello@accel.kz',
        ];
    }

    /**
     * @param  array{title: string, description: string}  $landingMeta
     * @return array<string, mixed>
     */
    private static function softwareApplicationGraph(array $landingMeta, string $baseUrl): array
    {
        return [
            '@context' => 'https://schema.org',
            '@type' => 'SoftwareApplication',
            'name' => 'Accel',
            'applicationCategory' => 'BusinessApplication',
            'operatingSystem' => 'Web',
            'url' => $baseUrl,
            'description' => $landingMeta['description'],
            'offers' => [
                '@type' => 'AggregateOffer',
                'priceCurrency' => 'KZT',
                'lowPrice' => '40000',
                'highPrice' => '1000000',
                'offerCount' => 2,
            ],
        ];
    }

    /** @return list<array<string, mixed>> */
    private static function offerGraphs(string $locale, string $baseUrl): array
    {
        $labels = self::planLabels($locale);

        $plans = Plan::query()
            ->where('is_active', true)
            ->whereIn('code', ['standard', 'boxed'])
            ->orderBy('price_cents')
            ->get();

        $offers = [];

        foreach ($plans as $plan) {
            $code = (string) $plan->code;
            $label = $labels[$code] ?? $plan->name;

            $offers[] = [
                '@context' => 'https://schema.org',
                '@type' => 'Offer',
                'name' => $label['name'],
                'description' => $label['description'],
                'price' => (string) (int) round($plan->price_cents / 100),
                'priceCurrency' => $plan->currency,
                'url' => $baseUrl.'/#pricing',
                'availability' => 'https://schema.org/InStock',
                'category' => $plan->isOneTime() ? 'OneTimePurchase' : 'Subscription',
            ];
        }

        return $offers;
    }

    /** @return array<string, array{name: string, description: string}> */
    private static function planLabels(string $locale): array
    {
        /** @var array<string, array<string, array{name?: string, description?: string}>> $configured */
        $configured = (array) config('landing.structured.plans', []);

        if ($configured !== []) {
            $result = [];
            foreach ($configured as $code => $byLocale) {
                $result[$code] = [
                    'name' => (string) ($byLocale[$locale]['name'] ?? $byLocale['kk']['name'] ?? $code),
                    'description' => (string) ($byLocale[$locale]['description'] ?? $byLocale['kk']['description'] ?? ''),
                ];
            }

            return $result;
        }

        return match ($locale) {
            'ru' => [
                'standard' => [
                    'name' => 'Стандарт',
                    'description' => 'Подписка на платформу Accel, 40 000 ₸ в месяц. AI-токены оплачиваются отдельно.',
                ],
                'boxed' => [
                    'name' => 'Коробочная установка',
                    'description' => 'Разовая установка платформы Accel, 1 000 000 ₸. AI-токены оплачиваются отдельно.',
                ],
            ],
            'en' => [
                'standard' => [
                    'name' => 'Standard',
                    'description' => 'Accel platform subscription, 40,000 ₸ per month. AI tokens billed separately.',
                ],
                'boxed' => [
                    'name' => 'Boxed installation',
                    'description' => 'One-time Accel platform installation, 1,000,000 ₸. AI tokens billed separately.',
                ],
            ],
            default => [
                'standard' => [
                    'name' => 'Стандарт',
                    'description' => 'Accel платформасына жазылым, айына 40 000 ₸. AI токендері бөлек төленеді.',
                ],
                'boxed' => [
                    'name' => 'Қораптық орнату',
                    'description' => 'Accel платформасын бір рет орнату, 1 000 000 ₸. AI токендері бөлек төленеді.',
                ],
            ],
        };
    }

    /** @return list<array<string, mixed>> */
    private static function faqGraph(string $locale): array
    {
        $items = self::faqItems($locale);

        if ($items === []) {
            return [];
        }

        return [[
            '@context' => 'https://schema.org',
            '@type' => 'FAQPage',
            'mainEntity' => array_map(
                static fn (array $item): array => [
                    '@type' => 'Question',
                    'name' => $item['question'],
                    'acceptedAnswer' => [
                        '@type' => 'Answer',
                        'text' => $item['answer'],
                    ],
                ],
                $items,
            ),
        ]];
    }

    /** @return list<array{question: string, answer: string}> */
    private static function faqItems(string $locale): array
    {
        /** @var array<string, list<array{question?: string, answer?: string}>> $configured */
        $configured = (array) config('landing.structured.faq', []);

        if ($configured === []) {
            return [];
        }

        $items = $configured[$locale] ?? $configured['kk'] ?? [];

        return array_values(array_filter(array_map(
            static fn (array $item): ?array => isset($item['question'], $item['answer'])
                ? ['question' => (string) $item['question'], 'answer' => (string) $item['answer']]
                : null,
            $items,
        )));
    }
}
