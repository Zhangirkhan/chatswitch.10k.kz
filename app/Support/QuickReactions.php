<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\SystemSetting;
use JsonException;

final readonly class QuickReactions
{
    public const KEY = 'chat.quick_reaction_emojis';

    /** @var array<int, string> */
    public const DEFAULT_EMOJIS = ['👍', '❤️', '😂', '😮', '😢'];

    public const COUNT = 5;

    /**
     * @return array<int, string>
     */
    public static function configured(): array
    {
        return self::normalize(SystemSetting::getValue(self::KEY));
    }

    /**
     * @return array<string, string>
     */
    public static function defaults(): array
    {
        return [
            self::KEY => self::encode(self::DEFAULT_EMOJIS),
        ];
    }

    /**
     * @return array<int, string>
     */
    public static function normalize(?string $raw): array
    {
        if ($raw === null || trim($raw) === '') {
            return self::DEFAULT_EMOJIS;
        }

        try {
            $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
            if (is_array($decoded)) {
                return self::complete(self::clean($decoded));
            }
        } catch (JsonException) {
            // Fall back to a simple space/comma separated format for manual DB edits.
        }

        return self::complete(self::clean(preg_split('/[\s,]+/u', $raw) ?: []));
    }

    /**
     * @param  array<int, string>  $emojis
     */
    public static function encode(array $emojis): string
    {
        return json_encode(self::complete(self::clean($emojis)), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    /**
     * @param  array<mixed>  $values
     * @return array<int, string>
     */
    private static function clean(array $values): array
    {
        $cleaned = [];
        foreach ($values as $value) {
            if (! is_string($value)) {
                continue;
            }

            $emoji = trim($value);
            if ($emoji === '' || in_array($emoji, $cleaned, true)) {
                continue;
            }

            $cleaned[] = mb_substr($emoji, 0, 16);
        }

        return array_slice($cleaned, 0, self::COUNT);
    }

    /**
     * @param  array<int, string>  $emojis
     * @return array<int, string>
     */
    private static function complete(array $emojis): array
    {
        foreach (self::DEFAULT_EMOJIS as $emoji) {
            if (count($emojis) >= self::COUNT) {
                break;
            }

            if (! in_array($emoji, $emojis, true)) {
                $emojis[] = $emoji;
            }
        }

        return array_slice($emojis, 0, self::COUNT);
    }
}
