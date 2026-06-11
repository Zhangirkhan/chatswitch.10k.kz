<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\PlatformChangelogEntry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final class MobileChangelogController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $data = $request->validate([
            'locale' => ['nullable', 'string', Rule::in(['ru', 'kk', 'en'])],
            'limit' => ['nullable', 'integer', 'min:1', 'max:100'],
            'app_version' => ['nullable', 'string', 'max:32'],
        ]);

        $locale = (string) ($data['locale'] ?? 'ru');
        $limit = (int) ($data['limit'] ?? 50);

        $entries = PlatformChangelogEntry::query()
            ->visibleToUsers()
            ->orderByDesc('published_at')
            ->orderByDesc('id')
            ->limit($limit)
            ->get([
                'id',
                'published_at',
                'title',
                'body',
                'git_commit_hash',
                'source_commit_subject',
            ]);

        return response()->json([
            'data' => [
                'entries' => $entries
                    ->map(static function (PlatformChangelogEntry $entry) use ($locale): array {
                        $text = PlatformChangelogEntry::pickTranslation($entry->title, $locale)
                            ?? PlatformChangelogEntry::pickTranslation($entry->body, $locale)
                            ?? '';

                        return [
                            'id' => $entry->id,
                            'app_version' => null,
                            'text' => $text,
                            'published_at' => $entry->published_at?->startOfDay()->utc()->toIso8601String(),
                            'git_commit_hash' => $entry->git_commit_hash,
                            'git_commit_subject' => $entry->source_commit_subject,
                            'source' => $entry->git_commit_hash !== null ? 'git' : 'manual',
                        ];
                    })
                    ->values()
                    ->all(),
            ],
        ]);
    }
}
