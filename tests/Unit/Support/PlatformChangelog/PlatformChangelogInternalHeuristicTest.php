<?php

declare(strict_types=1);

namespace Tests\Unit\Support\PlatformChangelog;

use App\Models\PlatformChangelogEntry;
use App\Support\PlatformChangelog\PlatformChangelogInternalHeuristic;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class PlatformChangelogInternalHeuristicTest extends TestCase
{
    use RefreshDatabase;

    public function test_detects_super_admin_changelog_admin_entries(): void
    {
        $entry = PlatformChangelogEntry::query()->create([
            'published_at' => '2026-06-11',
            'source_commit_subject' => 'Apply ui-table-panel styling to platform changelog list',
            'title' => ['ru' => 'Обновлён вид списка изменений в админ-панели', 'kk' => 'x', 'en' => 'x'],
            'body' => ['ru' => 'Super Admin changelog UI', 'kk' => 'x', 'en' => 'x'],
            'is_published' => true,
            'is_user_visible' => true,
        ]);

        $this->assertTrue(app(PlatformChangelogInternalHeuristic::class)->shouldBeInternal($entry));
    }

    public function test_does_not_hide_user_facing_feature_when_body_mentions_super_admin(): void
    {
        $entry = PlatformChangelogEntry::query()->create([
            'published_at' => '2026-06-11',
            'source_commit_subject' => 'Add platform-wide feedback popular ranking with likes API.',
            'title' => ['ru' => 'Рейтинг популярных отзывов и предложений', 'kk' => 'x', 'en' => 'x'],
            'body' => ['ru' => 'Для Super Admin добавлен виджет.', 'kk' => 'x', 'en' => 'Super Admin widget.'],
            'is_published' => true,
            'is_user_visible' => true,
        ]);

        $this->assertFalse(app(PlatformChangelogInternalHeuristic::class)->shouldBeInternal($entry));
    }

    public function test_keeps_user_facing_entries_visible(): void
    {
        $entry = PlatformChangelogEntry::query()->create([
            'published_at' => '2026-06-11',
            'source_commit_subject' => 'feat: voice dictation in chats',
            'title' => ['ru' => 'Голосовое диктование в чатах', 'kk' => 'x', 'en' => 'x'],
            'body' => ['ru' => 'Можно диктовать сообщения', 'kk' => 'x', 'en' => 'x'],
            'is_published' => true,
            'is_user_visible' => true,
        ]);

        $this->assertFalse(app(PlatformChangelogInternalHeuristic::class)->shouldBeInternal($entry));
    }
}
