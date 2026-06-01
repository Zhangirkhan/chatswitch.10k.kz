<?php

declare(strict_types=1);

namespace App\Support;

final class ContactFieldCatalog
{
    /**
     * @return list<array{
     *     code: string,
     *     label: string,
     *     type: string,
     *     section: string,
     *     group: string,
     *     is_system: bool,
     *     is_visible: bool,
     *     sort_order: int
     * }>
     */
    public static function systemFields(): array
    {
        return [
            ['code' => 'name', 'label' => 'Имя', 'type' => ContactFieldType::STRING, 'section' => 'basic', 'group' => 'about', 'is_system' => true, 'is_visible' => true, 'sort_order' => 10],
            ['code' => 'contact_id', 'label' => 'ID контакта', 'type' => ContactFieldType::NUMBER, 'section' => 'basic', 'group' => 'hidden', 'is_system' => true, 'is_visible' => false, 'sort_order' => 910],
            ['code' => 'funnel_stage', 'label' => 'Этап воронки', 'type' => ContactFieldType::STRING, 'section' => 'basic', 'group' => 'about', 'is_system' => true, 'is_visible' => true, 'sort_order' => 20],
            ['code' => 'funnel', 'label' => 'Воронка', 'type' => ContactFieldType::STRING, 'section' => 'basic', 'group' => 'about', 'is_system' => true, 'is_visible' => true, 'sort_order' => 21],
            ['code' => 'companies', 'label' => 'Компании / сегмент', 'type' => ContactFieldType::STRING, 'section' => 'basic', 'group' => 'about', 'is_system' => true, 'is_visible' => true, 'sort_order' => 30],
            ['code' => 'assignee', 'label' => 'Ответственный', 'type' => ContactFieldType::STRING, 'section' => 'basic', 'group' => 'about', 'is_system' => true, 'is_visible' => true, 'sort_order' => 40],
            ['code' => 'deal_progress', 'label' => 'Прогресс сделки', 'type' => ContactFieldType::NUMBER, 'section' => 'basic', 'group' => 'about', 'is_system' => true, 'is_visible' => true, 'sort_order' => 50],
            ['code' => 'phone', 'label' => 'Телефон', 'type' => ContactFieldType::STRING, 'section' => 'contacts', 'group' => 'about', 'is_system' => true, 'is_visible' => true, 'sort_order' => 60],
            ['code' => 'lead_id', 'label' => 'ID лида WhatsApp', 'type' => ContactFieldType::STRING, 'section' => 'contacts', 'group' => 'about', 'is_system' => true, 'is_visible' => true, 'sort_order' => 70],
            ['code' => 'address', 'label' => 'Адрес', 'type' => ContactFieldType::ADDRESS, 'section' => 'contacts', 'group' => 'about', 'is_system' => true, 'is_visible' => true, 'sort_order' => 80],
            ['code' => 'city', 'label' => 'Город', 'type' => ContactFieldType::STRING, 'section' => 'contacts', 'group' => 'about', 'is_system' => true, 'is_visible' => true, 'sort_order' => 81],
            ['code' => 'district', 'label' => 'Район', 'type' => ContactFieldType::STRING, 'section' => 'contacts', 'group' => 'about', 'is_system' => true, 'is_visible' => true, 'sort_order' => 82],
            ['code' => 'email', 'label' => 'E-mail', 'type' => ContactFieldType::STRING, 'section' => 'contacts', 'group' => 'about', 'is_system' => true, 'is_visible' => true, 'sort_order' => 90],
            ['code' => 'website', 'label' => 'Сайт', 'type' => ContactFieldType::LINK, 'section' => 'contacts', 'group' => 'about', 'is_system' => true, 'is_visible' => true, 'sort_order' => 100],
            ['code' => 'messenger', 'label' => 'Мессенджер', 'type' => ContactFieldType::STRING, 'section' => 'contacts', 'group' => 'about', 'is_system' => true, 'is_visible' => true, 'sort_order' => 110],
            ['code' => 'wa_channels', 'label' => 'Писал на WA-номера', 'type' => ContactFieldType::STRING, 'section' => 'contacts', 'group' => 'about', 'is_system' => true, 'is_visible' => true, 'sort_order' => 120],
            ['code' => 'company_phone', 'label' => 'Телефон компании', 'type' => ContactFieldType::STRING, 'section' => 'contacts', 'group' => 'about', 'is_system' => true, 'is_visible' => true, 'sort_order' => 130],
            ['code' => 'company_email', 'label' => 'Email компании', 'type' => ContactFieldType::STRING, 'section' => 'contacts', 'group' => 'about', 'is_system' => true, 'is_visible' => true, 'sort_order' => 131],
            ['code' => 'company_website', 'label' => 'Сайт компании', 'type' => ContactFieldType::LINK, 'section' => 'contacts', 'group' => 'about', 'is_system' => true, 'is_visible' => true, 'sort_order' => 132],
            ['code' => 'b2b_type', 'label' => 'Тип', 'type' => ContactFieldType::STRING, 'section' => 'b2b', 'group' => 'about', 'is_system' => true, 'is_visible' => true, 'sort_order' => 200],
            ['code' => 'open_tasks', 'label' => 'Задачи', 'type' => ContactFieldType::TEXT, 'section' => 'tasks_notes', 'group' => 'additional', 'is_system' => true, 'is_visible' => true, 'sort_order' => 300],
            ['code' => 'memory', 'label' => 'Память', 'type' => ContactFieldType::TEXT, 'section' => 'tasks_notes', 'group' => 'additional', 'is_system' => true, 'is_visible' => true, 'sort_order' => 310],
            ['code' => 'contact_whatsapp_id', 'label' => 'WhatsApp ID', 'type' => ContactFieldType::STRING, 'section' => 'basic', 'group' => 'hidden', 'is_system' => true, 'is_visible' => false, 'sort_order' => 920],
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function labelToCodeMap(): array
    {
        $map = [];
        foreach (self::systemFields() as $field) {
            $map[$field['label']] = $field['code'];
        }

        $map['Задача'] = 'open_tasks';

        return $map;
    }

    /**
     * @return array<string, string>
     */
    public static function groupLabels(): array
    {
        return [
            'about' => 'О контакте',
            'additional' => 'Дополнительно',
            'hidden' => 'Скрытые поля',
        ];
    }
}
