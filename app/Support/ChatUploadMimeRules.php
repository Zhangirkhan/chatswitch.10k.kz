<?php

declare(strict_types=1);

namespace App\Support;

final class ChatUploadMimeRules
{
    /** @var array<string, list<string>> */
    private const BY_TYPE = [
        'image' => ['image/jpeg', 'image/png', 'image/gif', 'image/webp'],
        'video' => ['video/mp4', 'video/webm', 'video/quicktime', 'video/3gpp'],
        'audio' => ['audio/mpeg', 'audio/mp4', 'audio/ogg', 'audio/wav', 'audio/webm', 'audio/aac'],
        'voice' => ['audio/ogg', 'audio/mpeg', 'audio/mp4', 'audio/aac', 'audio/webm'],
        'ptt' => ['audio/ogg', 'audio/mpeg', 'audio/mp4', 'audio/aac'],
        'sticker' => ['image/webp'],
        'gif' => ['image/gif'],
        'document' => [
            'application/pdf',
            'application/msword',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'application/vnd.ms-excel',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'text/plain',
            'application/zip',
            'application/octet-stream',
        ],
    ];

    /** @return list<string> */
    public static function mimetypesFor(?string $type): array
    {
        if ($type === null || $type === '') {
            return self::allMimetypes();
        }

        return self::BY_TYPE[$type] ?? self::allMimetypes();
    }

    /** @return list<string> */
    public static function allMimetypes(): array
    {
        $all = [];

        foreach (self::BY_TYPE as $mimes) {
            $all = array_merge($all, $mimes);
        }

        return array_values(array_unique($all));
    }
}
