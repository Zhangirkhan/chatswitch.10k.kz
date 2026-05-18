<?php

declare(strict_types=1);

namespace App\Support;

final class FunnelStageType
{
    public const LEAD = 'lead';

    public const QUALIFICATION = 'qualification';

    public const OFFER = 'offer';

    public const PAYMENT = 'payment';

    public const PRODUCTION = 'production';

    public const DELIVERY = 'delivery';

    public const DONE = 'done';

    public const OTHER = 'other';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return [
            self::LEAD,
            self::QUALIFICATION,
            self::OFFER,
            self::PAYMENT,
            self::PRODUCTION,
            self::DELIVERY,
            self::DONE,
            self::OTHER,
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function labels(): array
    {
        return [
            self::LEAD => 'Лид',
            self::QUALIFICATION => 'Квалификация',
            self::OFFER => 'Предложение',
            self::PAYMENT => 'Оплата',
            self::PRODUCTION => 'В работе',
            self::DELIVERY => 'Доставка',
            self::DONE => 'Закрыто',
            self::OTHER => 'Другое',
        ];
    }

    public static function label(string $type): string
    {
        return self::labels()[$type] ?? self::labels()[self::OTHER];
    }

    public static function normalize(?string $type): string
    {
        if ($type !== null && in_array($type, self::values(), true)) {
            return $type;
        }

        return self::OTHER;
    }

    public static function guessFromName(string $name): string
    {
        $n = mb_strtolower(trim($name));

        if ($n === '') {
            return self::OTHER;
        }

        if (self::matches($n, ['закрыт', 'успеш', 'выполнен', 'заверш', 'done', 'won'])) {
            return self::DONE;
        }

        if (self::matches($n, ['доставк', 'монтаж', 'выдач', 'установк', 'отгруз'])) {
            return self::DELIVERY;
        }

        if (self::matches($n, ['производ', 'изготов', 'сборк', 'ремонт', 'в работе', 'работ'])) {
            return self::PRODUCTION;
        }

        if (self::matches($n, ['оплат', 'предоплат', 'договор', 'счёт', 'счет', 'invoice', 'payment'])) {
            return self::PAYMENT;
        }

        if (self::matches($n, ['кп', 'предложен', 'проект', 'расчёт', 'расчет', 'смет', 'подбор', 'offer'])) {
            return self::OFFER;
        }

        if (self::matches($n, ['квалиф', 'консульт', 'диагност', 'замер', 'бриф', 'созвон', 'запись', 'приём', 'прием'])) {
            return self::QUALIFICATION;
        }

        if (self::matches($n, ['лид', 'заявк', 'первичн', 'новый', 'интерес', 'обращен', 'входящ', 'lead'])) {
            return self::LEAD;
        }

        return self::OTHER;
    }

    /**
     * @param  list<string>  $needles
     */
    private static function matches(string $haystack, array $needles): bool
    {
        foreach ($needles as $needle) {
            if (str_contains($haystack, $needle)) {
                return true;
            }
        }

        return false;
    }
}
