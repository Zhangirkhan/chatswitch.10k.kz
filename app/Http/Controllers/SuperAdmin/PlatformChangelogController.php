<?php

declare(strict_types=1);

namespace App\Http\Controllers\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Models\PlatformChangelogEntry;
use App\Services\PlatformChangelog\PlatformChangelogGitSyncService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

final class PlatformChangelogController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('SuperAdmin/PlatformChangelog/Index', [
            'entries' => PlatformChangelogEntry::query()
                ->orderByDesc('published_at')
                ->orderByDesc('id')
                ->get(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request);

        PlatformChangelogEntry::query()->create([
            ...$data,
            'created_by_user_id' => $request->user()?->id,
        ]);

        return back()->with('success', 'Запись changelog добавлена.');
    }

    public function update(Request $request, PlatformChangelogEntry $platformChangelog): RedirectResponse
    {
        $platformChangelog->update($this->validated($request));

        return back()->with('success', 'Запись changelog обновлена.');
    }

    public function destroy(PlatformChangelogEntry $platformChangelog): RedirectResponse
    {
        $platformChangelog->delete();

        return back()->with('success', 'Запись changelog удалена.');
    }

    public function syncFromGit(PlatformChangelogGitSyncService $sync): RedirectResponse
    {
        if (! (bool) config('changelog.git_sync.enabled', true)) {
            return back()->with('error', 'Синхронизация changelog из git отключена.');
        }

        $stats = $sync->sync();

        if ($stats['created'] === 0 && $stats['processed'] === 0 && $stats['errors'] !== []) {
            return back()->with('error', $stats['errors'][0] ?? 'Синхронизация не выполнена.');
        }

        $message = "Синхронизация завершена: создано {$stats['created']}, пропущено {$stats['skipped']}.";
        if ($stats['errors'] !== []) {
            $message .= ' Ошибки: '.implode(' ', array_slice($stats['errors'], 0, 3));
        }

        return back()->with('success', $message);
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request): array
    {
        $data = $request->validate([
            'published_at' => ['required', 'date'],
            'title_ru' => ['required', 'string', 'max:200'],
            'title_kk' => ['nullable', 'string', 'max:200'],
            'title_en' => ['nullable', 'string', 'max:200'],
            'body_ru' => ['required', 'string', 'max:10000'],
            'body_kk' => ['nullable', 'string', 'max:10000'],
            'body_en' => ['nullable', 'string', 'max:10000'],
            'is_published' => ['sometimes', 'boolean'],
            'is_user_visible' => ['sometimes', 'boolean'],
        ]);

        return [
            'published_at' => $data['published_at'],
            'title' => [
                'ru' => trim($data['title_ru']),
                'kk' => filled($data['title_kk'] ?? null) ? trim((string) $data['title_kk']) : trim($data['title_ru']),
                'en' => filled($data['title_en'] ?? null) ? trim((string) $data['title_en']) : trim($data['title_ru']),
            ],
            'body' => [
                'ru' => trim($data['body_ru']),
                'kk' => filled($data['body_kk'] ?? null) ? trim((string) $data['body_kk']) : trim($data['body_ru']),
                'en' => filled($data['body_en'] ?? null) ? trim((string) $data['body_en']) : trim($data['body_ru']),
            ],
            'is_published' => (bool) ($data['is_published'] ?? true),
            'is_user_visible' => (bool) ($data['is_user_visible'] ?? true),
        ];
    }
}
