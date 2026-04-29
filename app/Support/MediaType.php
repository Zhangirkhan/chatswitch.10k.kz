<?php

declare(strict_types=1);

namespace App\Support;

final class MediaType
{
    public static function detect(string $mime, ?string $hint = null): string
    {
        if ($hint && in_array($hint, ['image', 'video', 'audio', 'voice', 'ptt', 'sticker', 'gif', 'document'], true)) {
            return $hint;
        }

        return match (true) {
            str_starts_with($mime, 'image/gif') => 'gif',
            str_starts_with($mime, 'image/webp') => 'sticker',
            str_starts_with($mime, 'image/') => 'image',
            str_starts_with($mime, 'video/') => 'video',
            $mime === 'audio/ogg' => 'voice',
            str_starts_with($mime, 'audio/') => 'audio',
            default => 'document',
        };
    }

    public static function previewText(string $type, ?string $caption = null): string
    {
        $caption = trim((string) $caption);
        if ($caption !== '') {
            return $caption;
        }

        return match ($type) {
            'image' => '📷 Фото',
            'video' => '🎥 Видео',
            'audio' => '🎵 Аудио',
            'voice', 'ptt' => '🎤 Голосовое сообщение',
            'sticker' => '🌟 Стикер',
            'gif' => '🎬 GIF',
            'document' => '📄 Документ',
            default => '📎 Вложение',
        };
    }
}
