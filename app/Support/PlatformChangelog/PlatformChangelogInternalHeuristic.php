<?php

declare(strict_types=1);

namespace App\Support\PlatformChangelog;

use App\Models\PlatformChangelogEntry;

final class PlatformChangelogInternalHeuristic
{
    /** @var list<string> */
    private const USER_VISIBLE_SUBJECT_PATTERNS = [
        'configurable platform banners for web and mobile',
        'platform-wide feedback popular ranking',
        'speech dictation',
        'mobile changelog api',
        'what\'s new',
        'voice dictation',
        'feedback popular',
    ];

    /** @var list<string> */
    private const INTERNAL_SUBJECT_PATTERNS = [
        'ui-table-panel styling to platform changelog',
        'polish super admin platform banners',
        'render platform banners in document flow above layout chrome',
        'reload platform banners via inertia',
        'broadcast platform banner changes and expose delivery state',
        'platform changelog git sync',
        'super admin platform changelog',
    ];

    /** @var list<string> */
    private const INTERNAL_TITLE_PATTERNS = [
        'админ-панел',
        'админке changelog',
        'списка изменений в админ',
        'управление баннерами платформы',
        'баннеры обновляются без перезагрузки страницы',
        'excel-выгрузка компаний в super admin',
    ];

    public function shouldBeInternal(PlatformChangelogEntry $entry): bool
    {
        $subject = mb_strtolower(trim((string) $entry->source_commit_subject));
        $titleRu = mb_strtolower(trim((string) ($entry->title['ru'] ?? '')));

        if ($subject !== '') {
            foreach (self::USER_VISIBLE_SUBJECT_PATTERNS as $pattern) {
                if (str_contains($subject, $pattern)) {
                    return false;
                }
            }

            foreach (self::INTERNAL_SUBJECT_PATTERNS as $pattern) {
                if (str_contains($subject, $pattern)) {
                    return true;
                }
            }
        }

        foreach (self::INTERNAL_TITLE_PATTERNS as $pattern) {
            if ($titleRu !== '' && str_contains($titleRu, $pattern)) {
                return true;
            }
        }

        return false;
    }
}
