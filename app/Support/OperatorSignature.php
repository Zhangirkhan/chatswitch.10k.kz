<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\User;

/**
 * Формирование подписи оператора для исходящих сообщений.
 *
 * Подпись дописывается перед текстом сообщения (или caption медиа), чтобы клиент
 * в WhatsApp видел, кто именно ему ответил. Используется формат bold-тэга WA (`*...*`),
 * за ним перевод строки и исходный текст:
 *
 *   *Жангирхан (Администратор)*
 *   Здравствуйте!
 *
 * Подпись сохраняется в БД (Message::body / MessageMedia caption) и улетает в WhatsApp
 * одновременно — поэтому в интерфейсе чата оператор видит ровно то же, что получил клиент.
 */
final class OperatorSignature
{
    /**
     * Перевод ключа роли (см. Spatie roles, слаги на английском) в человеко-читаемую должность.
     *
     * @var array<string, string>
     */
    private const ROLE_TITLES = [
        'administrator' => 'Администратор',
        'admin' => 'Администратор',
        'manager' => 'Менеджер',
        'employee' => 'Сотрудник',
        'operator' => 'Оператор',
        'support' => 'Поддержка',
    ];

    /**
     * Добавить подпись оператора к тексту. Если текст пуст — возвращает только подпись
     * (кейс медиа без caption: подпись идёт как caption к файлу).
     * Если пользователь не передан — возвращает текст без изменений.
     */
    public static function prepend(?User $user, string $text): string
    {
        if ($user === null) {
            return $text;
        }

        $signature = self::build($user);
        if ($signature === '') {
            return $text;
        }

        $trimmed = trim($text);

        return $trimmed === ''
            ? $signature
            : $signature."\n".$text;
    }

    /**
     * Снять подпись оператора с текста. Используется для аналитики/AI-промптов,
     * где «настоящий» ответ оператора важнее служебной плашки `*Имя (Роль)*`.
     * Если строка не похожа на нашу подпись — возвращаем исходный текст без изменений.
     */
    public static function strip(string $text): string
    {
        $trimmed = ltrim($text);
        if ($trimmed === '') {
            return $text;
        }

        // Подпись всегда первая строка вида `*...*` без переносов внутри звёздочек,
        // далее перевод строки и тело сообщения.
        if (! preg_match('/^\*[^*\n]+\*\R?/u', $trimmed, $match)) {
            return $text;
        }

        return mb_substr($trimmed, mb_strlen($match[0]));
    }

    /**
     * Построить только строку подписи без переноса и без текста:
     * `*Имя (Должность)*`.
     */
    public static function build(User $user): string
    {
        $name = trim((string) $user->name);
        if ($name === '') {
            return '';
        }

        $parts = [];
        $title = self::roleTitle($user);
        if ($title !== null) {
            $parts[] = $title;
        }

        return $parts === []
            ? '*'.$name.'*'
            : '*'.$name.' ('.implode(' · ', $parts).')*';
    }

    /**
     * Подпись для UI/mobile без WhatsApp-разметки: `Имя (Должность)`.
     */
    public static function plainLabel(?User $user): string
    {
        if ($user === null) {
            return '';
        }

        return trim(self::build($user), '*');
    }

    public static function roleLabel(?User $user): ?string
    {
        return $user !== null ? self::roleTitle($user) : null;
    }

    private static function roleTitle(User $user): ?string
    {
        try {
            $role = $user->getRoleNames()->first();
        } catch (\Throwable $e) {
            return null;
        }

        if (! is_string($role) || $role === '') {
            return null;
        }

        return self::ROLE_TITLES[strtolower($role)] ?? ucfirst($role);
    }
}
