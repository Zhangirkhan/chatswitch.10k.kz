<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\PlatformChangelogEntry;
use Illuminate\Database\Seeder;

final class PlatformChangelogSeeder extends Seeder
{
    public function run(): void
    {
        if (PlatformChangelogEntry::query()->exists()) {
            return;
        }

        $entries = [
            [
                'published_at' => '2026-06-11',
                'title' => [
                    'ru' => 'Excel-выгрузка компаний в Super Admin',
                    'kk' => 'Super Admin-де компанияларды Excel-ге экспорттау',
                    'en' => 'Company Excel export in Super Admin',
                ],
                'body' => [
                    'ru' => 'В списке компаний появилась кнопка «Выгрузить Excel». В файле — БИН, адрес, вид деятельности, тариф, подписка, владелец и другие поля. Фильтры списка учитываются при выгрузке.',
                    'kk' => 'Компаниялар тізімінде «Excel жүктеу» батырмасы пайда болды. Файлда БСН, мекенжай, қызмет түрі, тариф, жазылым, иесі және басқа өрістер бар.',
                    'en' => 'The companies list now has an “Export Excel” button with BIN, address, activity, plan, subscription, owner and other fields. List filters apply to the export.',
                ],
            ],
            [
                'published_at' => '2026-06-11',
                'title' => [
                    'ru' => 'FAQ на отдельной странице',
                    'kk' => 'FAQ жеке бетте',
                    'en' => 'FAQ on a dedicated page',
                ],
                'body' => [
                    'ru' => 'Ответы на частые вопросы перенесены на accel.kz/faq — по категориям. На главной остался короткий блок со ссылкой.',
                    'kk' => 'Жиі қойылатын сұрақтар accel.kz/faq бетіне категориялар бойынша көшірілді. Басты бетте қысқа блок қалды.',
                    'en' => 'FAQ answers moved to accel.kz/faq by category. The home page now shows a short teaser link.',
                ],
            ],
            [
                'published_at' => '2026-06-11',
                'title' => [
                    'ru' => 'Changelog в настройках',
                    'kk' => 'Баптаулардағы changelog',
                    'en' => 'Changelog in settings',
                ],
                'body' => [
                    'ru' => 'В настройках появился раздел «Что нового» — здесь публикуем изменения платформы Accel.',
                    'kk' => 'Баптаулarda «Не жаңалық» бөлімі пайда болды — Accel платформасының өзгерістері осында.',
                    'en' => 'Settings now include “What’s new” — platform updates for Accel appear here.',
                ],
            ],
        ];

        foreach ($entries as $entry) {
            PlatformChangelogEntry::query()->create([
                ...$entry,
                'is_published' => true,
            ]);
        }
    }
}
