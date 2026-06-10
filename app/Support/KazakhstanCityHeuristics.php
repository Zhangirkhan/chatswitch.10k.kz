<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Распознавание городов РК в сообщениях клиента (доставка, выезд).
 */
final class KazakhstanCityHeuristics
{
    /** @var list<string> */
    private const CITY_FRAGMENTS = [
        'алмат',
        'астан',
        'нурс',
        'караганд',
        'шымкент',
        'актоб',
        'павлодар',
        'усть-камен',
        'оскемен',
        'семей',
        'атерау',
        'костан',
        'кызыл',
        'петроп',
        'урал',
        'туркестан',
        'талдык',
        'экибаст',
        'рудн',
        'тараз',
        'кокшет',
    ];

    public static function mentionsCity(string $body): bool
    {
        $body = mb_strtolower(trim($body));
        if ($body === '') {
            return false;
        }

        foreach (self::CITY_FRAGMENTS as $fragment) {
            if (str_contains($body, $fragment)) {
                return true;
            }
        }

        return false;
    }

    public static function isDeliveryDestinationStatement(string $body): bool
    {
        if (! self::mentionsCity($body)) {
            return false;
        }

        $body = mb_strtolower(trim($body));

        return preg_match('/(?:мне\s+)?(?:надо|нужно|керек|надо\b)/u', $body) === 1
            || preg_match('/\b(?:в|во|до)\s+\p{L}/u', $body) === 1;
    }

    public static function isPriceNegotiation(string $body): bool
    {
        $body = mb_strtolower(trim($body));
        if ($body === '') {
            return false;
        }

        if (preg_match('/(?:можно|можете|могу|возможно)\s+(?:за|по)\s*[\d\s]{4,}/u', $body) === 1) {
            return true;
        }

        return preg_match('/\d{4,}/u', $body) === 1
            && preg_match('/(?:набор|за\b|скид|цен|₸|тг|тенге|стоим)/u', $body) === 1;
    }
}
