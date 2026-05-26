<?php

declare(strict_types=1);

namespace App\Support;

final class CompanyModules
{
    /** @return list<string> */
    public static function keys(): array
    {
        return array_keys(self::definitions());
    }

    /**
     * @return array<string, array{label: string, description: string, inertia_key: string}>
     */
    public static function definitions(): array
    {
        return [
            'module_tasks' => [
                'label' => 'Задачи и отделы',
                'description' => 'Раздел «Организация»: отделы, задачи, комментарии и архив.',
                'inertia_key' => 'tasks',
            ],
            'module_calendar' => [
                'label' => 'Календарь записей',
                'description' => 'Записи с повторениями (час, день, месяц).',
                'inertia_key' => 'calendar',
            ],
            'module_analytics' => [
                'label' => 'Аналитика диалогов',
                'description' => 'Раздел «Аналитика» и страница /analytics/dialogs.',
                'inertia_key' => 'analytics',
            ],
            'module_funnels' => [
                'label' => 'Воронки продаж',
                'description' => 'Этапы сделок, доска воронок и аналитика.',
                'inertia_key' => 'funnels',
            ],
            'module_products' => [
                'label' => 'Товары',
                'description' => 'Каталог товаров в базе знаний для AI.',
                'inertia_key' => 'products',
            ],
            'module_services' => [
                'label' => 'Услуги',
                'description' => 'Услуги, цены и условия в базе знаний для AI.',
                'inertia_key' => 'services',
            ],
            'module_knowledge' => [
                'label' => 'База знаний',
                'description' => 'Правила и инструкции для ответов AI.',
                'inertia_key' => 'knowledge',
            ],
            'module_ai_quality' => [
                'label' => 'AI и качество',
                'description' => 'Журнал ошибок AI и оценки ответов.',
                'inertia_key' => 'ai_quality',
            ],
        ];
    }

    public static function isModuleKey(string $key): bool
    {
        return array_key_exists($key, self::definitions());
    }

    /** @return array<string, string> */
    public static function defaultValues(): array
    {
        return array_fill_keys(self::keys(), 'on');
    }
}
