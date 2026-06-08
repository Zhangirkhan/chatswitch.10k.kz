<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Http\UploadedFile;

final class ChatUploadMimeRules
{
    /** @var array<string, list<string>> */
    private const BY_TYPE = [
        'image' => ['image/jpeg', 'image/png', 'image/gif', 'image/webp'],
        'video' => ['video/mp4', 'video/webm', 'video/quicktime', 'video/3gpp'],
        'audio' => [
            'audio/mpeg', 'audio/mp4', 'audio/x-m4a', 'audio/ogg', 'audio/opus',
            'audio/wav', 'audio/webm', 'audio/aac', 'audio/x-caf',
        ],
        'voice' => [
            'audio/ogg', 'audio/opus', 'audio/mpeg', 'audio/mp4', 'audio/x-m4a',
            'audio/aac', 'audio/webm', 'audio/wav', 'audio/x-caf', 'application/octet-stream',
        ],
        'ptt' => [
            'audio/ogg', 'audio/opus', 'audio/mpeg', 'audio/mp4', 'audio/x-m4a',
            'audio/aac', 'audio/webm', 'audio/wav', 'audio/x-caf', 'application/octet-stream',
        ],
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

    /** @var list<string> */
    private const VOICE_EXTENSIONS = ['.m4a', '.wav', '.aac', '.ogg', '.opus', '.mp3', '.webm', '.caf', '.amr'];

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

    public static function accepts(UploadedFile $file, ?string $type): bool
    {
        return self::resolveAcceptedMime($file, $type) !== null;
    }

    public static function resolveAcceptedMime(UploadedFile $file, ?string $type): ?string
    {
        $allowed = self::mimetypesFor($type);
        $detected = self::normalizeMime(
            strtolower(trim(explode(';', (string) ($file->getMimeType() ?? ''))[0])),
        );
        $fromName = self::mimeFromFilename((string) $file->getClientOriginalName());

        foreach (array_filter([$detected, $fromName]) as $candidate) {
            if ($candidate === 'application/octet-stream') {
                continue;
            }
            if (in_array($candidate, $allowed, true)) {
                return self::canonicalMimeForStorage($candidate, (string) $file->getClientOriginalName(), $type);
            }
        }

        if ($detected === 'application/octet-stream'
            && in_array($type, ['voice', 'ptt', 'audio'], true)
            && self::hasVoiceExtension((string) $file->getClientOriginalName())
            && $fromName !== null
            && in_array($fromName, $allowed, true)) {
            return self::canonicalMimeForStorage($fromName, (string) $file->getClientOriginalName(), $type);
        }

        return null;
    }

    public static function canonicalMimeForStorage(string $mime, string $filename, ?string $type): string
    {
        $mime = self::normalizeMime($mime);
        $lowerName = strtolower($filename);

        if (str_ends_with($lowerName, '.webm') && ! str_contains($mime, 'webm')) {
            return 'audio/webm';
        }

        if (in_array($type, ['voice', 'ptt'], true) && str_starts_with($mime, 'video/')) {
            return 'audio/webm';
        }

        return $mime;
    }

    private static function normalizeMime(string $mime): string
    {
        return match ($mime) {
            'audio/x-m4a', 'audio/m4a' => 'audio/mp4',
            default => $mime,
        };
    }

    private static function mimeFromFilename(string $filename): ?string
    {
        $lower = strtolower($filename);

        return match (true) {
            str_ends_with($lower, '.m4a') => 'audio/mp4',
            str_ends_with($lower, '.wav') => 'audio/wav',
            str_ends_with($lower, '.aac') => 'audio/aac',
            str_ends_with($lower, '.ogg') => 'audio/ogg',
            str_ends_with($lower, '.opus') => 'audio/opus',
            str_ends_with($lower, '.mp3') => 'audio/mpeg',
            str_ends_with($lower, '.webm') => 'audio/webm',
            str_ends_with($lower, '.caf') => 'audio/x-caf',
            str_ends_with($lower, '.amr') => 'audio/amr',
            default => null,
        };
    }

    private static function hasVoiceExtension(string $filename): bool
    {
        $lower = strtolower($filename);
        foreach (self::VOICE_EXTENSIONS as $ext) {
            if (str_ends_with($lower, $ext)) {
                return true;
            }
        }

        return false;
    }
}
