<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Сопоставление текста клиента с отделом по названию, описанию и типовым формулировкам
 * (бухгалтерия, HR, продажи, замер и т.д.) — до/после LLM.
 *
 * @phpstan-type DepartmentCatalogEntry array{id: int, name: string, description: string|null, funnels: list<string>}
 */
final class DepartmentIntentMatcher
{
    /**
     * Фраза в сообщении клиента → подсказки, которые должны встретиться в name/description отдела.
     *
     * @var array<string, list<string>>
     */
    private const CLIENT_TRIGGERS = [
        'бухгалт' => ['бухгалт', 'учет', 'учёт', 'финанс', 'налог', 'счёт', 'счет', 'оплат', 'реквизит', 'накладн', 'сверк', 'invoice'],
        'кадр' => ['кадр', 'hr', 'персонал', 'рекрут', 'сотрудник', 'штат', 'увольн', 'отпуск', 'трудов'],
        'замер' => ['замер', 'замерщик', 'монтаж', 'установ', 'окон', 'окна', 'кухн', 'пластик'],
        'продаж' => ['продаж', 'купить', 'заказ', 'цен', 'стоим', 'каталог', 'ассортимент', 'подобрать'],
        'достав' => ['достав', 'курьер', 'отгруз', 'получить заказ'],
        'сервис' => ['сервис', 'гарант', 'ремонт', 'поломк', 'рекламац', 'жалоб'],
        'юрист' => ['юрист', 'договор', 'претенз'],
    ];

    /**
     * @param  list<DepartmentCatalogEntry>  $catalog
     */
    public function match(string $messageBody, array $catalog): ?array
    {
        $body = mb_strtolower(trim(OperatorSignature::strip($messageBody)));
        if ($body === '' || $catalog === []) {
            return null;
        }

        $best = null;
        $bestScore = 0;

        foreach ($catalog as $department) {
            $score = $this->scoreDepartment($body, $department);
            if ($score > $bestScore) {
                $bestScore = $score;
                $best = $department;
            }
        }

        $minScore = (int) config('funnel.department_routing.keyword_min_score', 6);
        if ($best === null || $bestScore < $minScore) {
            return null;
        }

        return $best;
    }

    /**
     * @param  DepartmentCatalogEntry  $department
     */
    private function scoreDepartment(string $body, array $department): int
    {
        $name = mb_strtolower(trim((string) $department['name']));
        $description = mb_strtolower(trim((string) ($department['description'] ?? '')));
        $haystack = trim($name.' '.$description);
        if ($haystack === '') {
            return 0;
        }

        $score = 0;

        foreach (self::CLIENT_TRIGGERS as $trigger => $deptHints) {
            if (! str_contains($body, $trigger)) {
                continue;
            }

            if (str_contains($haystack, $trigger)) {
                $score += 12;
            }

            foreach ($deptHints as $hint) {
                if (str_contains($haystack, $hint)) {
                    $score += 4;
                }
            }
        }

        if ($name !== '' && str_contains($body, $name)) {
            $score += 15;
        }

        foreach (preg_split('/\s+/u', $name) ?: [] as $word) {
            if (mb_strlen($word) >= 4 && str_contains($body, $word)) {
                $score += 6;
            }
        }

        return $score;
    }

    /**
     * Отдел первичного приёма, если клиент только поздоровался.
     *
     * @param  list<DepartmentCatalogEntry>  $catalog
     */
    public function receptionDepartment(array $catalog): ?array
    {
        $hints = ['продаж', 'приём', 'прием', 'консульт', 'клиентск', 'общий', 'ресепшн', 'reception', 'sales'];

        foreach ($hints as $hint) {
            foreach ($catalog as $department) {
                $name = mb_strtolower((string) $department['name']);
                if (str_contains($name, $hint)) {
                    return $department;
                }
            }
        }

        return null;
    }
}
