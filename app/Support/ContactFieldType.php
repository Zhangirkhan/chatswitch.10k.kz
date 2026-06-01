<?php

declare(strict_types=1);

namespace App\Support;

final class ContactFieldType
{
    public const STRING = 'string';

    public const TEXT = 'text';

    public const LIST = 'list';

    public const DATE = 'date';

    public const DATETIME = 'datetime';

    public const ADDRESS = 'address';

    public const LINK = 'link';

    public const FILE = 'file';

    public const MONEY = 'money';

    public const BOOLEAN = 'boolean';

    public const NUMBER = 'number';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return [
            self::STRING,
            self::TEXT,
            self::LIST,
            self::DATE,
            self::DATETIME,
            self::ADDRESS,
            self::LINK,
            self::FILE,
            self::MONEY,
            self::BOOLEAN,
            self::NUMBER,
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function labels(): array
    {
        return [
            self::STRING => 'Строка',
            self::TEXT => 'Текст',
            self::LIST => 'Список',
            self::DATE => 'Дата',
            self::DATETIME => 'Дата/время',
            self::ADDRESS => 'Адрес',
            self::LINK => 'Ссылка',
            self::FILE => 'Файл',
            self::MONEY => 'Деньги',
            self::BOOLEAN => 'Да/Нет',
            self::NUMBER => 'Число',
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function descriptions(): array
    {
        return [
            self::LIST => 'Выбор одного или нескольких значений из списка',
            self::DATETIME => 'Дата и время с календарём',
            self::DATE => 'Дата с календарём',
            self::ADDRESS => 'Хранение адресной информации',
            self::LINK => 'Ссылки на веб-страницы',
            self::FILE => 'Изображения и документы',
            self::MONEY => 'Денежные значения с валютой',
            self::BOOLEAN => 'Ответ «да» или «нет»',
            self::NUMBER => 'Числовые данные для отчётов',
            self::STRING => 'Короткая текстовая строка',
            self::TEXT => 'Многострочный текст',
        ];
    }

    public static function normalize(?string $type): string
    {
        if ($type !== null && in_array($type, self::values(), true)) {
            return $type;
        }

        return self::STRING;
    }
}
