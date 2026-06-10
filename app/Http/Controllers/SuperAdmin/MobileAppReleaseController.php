<?php

declare(strict_types=1);

namespace App\Http\Controllers\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Models\MobileAppRelease;
use App\Services\Mobile\MobileAppReleaseService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

final class MobileAppReleaseController extends Controller
{
    public function __construct(
        private readonly MobileAppReleaseService $releaseService,
    ) {}

    public function index(): Response
    {
        return Inertia::render('SuperAdmin/MobileReleases/Index', [
            'releases' => MobileAppRelease::query()
                ->orderByDesc('platform')
                ->orderByDesc('version_code')
                ->get(),
            'platforms' => MobileAppRelease::PLATFORMS,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request);

        $this->releaseService->create(
            $data,
            $request->file('apk_file'),
        );

        return back()->with('success', 'Релиз мобильного приложения создан.');
    }

    public function update(Request $request, MobileAppRelease $mobileRelease): RedirectResponse
    {
        $data = $this->validated($request, $mobileRelease);

        $this->releaseService->update(
            $mobileRelease,
            $data,
            $request->file('apk_file'),
        );

        return back()->with('success', 'Релиз обновлён.');
    }

    public function publish(MobileAppRelease $mobileRelease): RedirectResponse
    {
        $this->releaseService->publish($mobileRelease);

        return back()->with('success', 'Релиз опубликован как актуальный.');
    }

    public function unpublish(MobileAppRelease $mobileRelease): RedirectResponse
    {
        $this->releaseService->unpublish($mobileRelease);

        return back()->with('success', 'Релиз снят с публикации.');
    }

    public function destroy(MobileAppRelease $mobileRelease): RedirectResponse
    {
        if ($mobileRelease->is_published) {
            return back()->with('error', 'Нельзя удалить опубликованный релиз — сначала снимите с публикации.');
        }

        $mobileRelease->delete();

        return back()->with('success', 'Релиз удалён.');
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, ?MobileAppRelease $release = null): array
    {
        $data = $request->validate([
            'platform' => ['required', 'string', Rule::in(MobileAppRelease::PLATFORMS)],
            'version_name' => ['required', 'string', 'max:32'],
            'version_code' => [
                'required',
                'integer',
                'min:1',
                Rule::unique('mobile_app_releases', 'version_code')
                    ->where('platform', (string) $request->input('platform'))
                    ->ignore($release?->id),
            ],
            'min_version_code' => ['nullable', 'integer', 'min:0'],
            'download_url' => ['nullable', 'string', 'max:512'],
            'release_notes' => ['nullable', 'string', 'max:10000'],
            'is_published' => ['boolean'],
            'apk_file' => ['nullable', 'file', 'max:102400'],
        ]);

        $data['min_version_code'] = (int) ($data['min_version_code'] ?? 0);
        $data['is_published'] = (bool) ($data['is_published'] ?? false);

        if ($release !== null) {
            unset($data['platform']);
        }

        return $data;
    }
}
