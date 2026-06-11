<?php

declare(strict_types=1);

namespace App\Http\Controllers\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\PlatformBanner;
use App\Services\SuperAdmin\SuperAdminAuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

final class PlatformBannerController extends Controller
{
    public function __construct(
        private readonly SuperAdminAuditLogger $audit,
    ) {}

    public function index(): Response
    {
        return Inertia::render('SuperAdmin/PlatformBanners/Index', [
            'banners' => PlatformBanner::query()
                ->with('company:id,name,slug')
                ->orderByDesc('is_published')
                ->orderByDesc('priority')
                ->orderByDesc('id')
                ->get(),
            'companies' => Company::query()->orderBy('name')->get(['id', 'name', 'slug']),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request);

        $banner = PlatformBanner::query()->create([
            ...$data,
            'created_by_user_id' => $request->user()?->id,
        ]);

        $this->audit->log(
            $banner->company_id !== null ? Company::query()->find($banner->company_id) : null,
            $request->user(),
            'platform_banner.created',
            $banner,
            ['targets' => $banner->targets],
        );

        return back()->with('success', 'Баннер создан.');
    }

    public function update(Request $request, PlatformBanner $platformBanner): RedirectResponse
    {
        $data = $this->validated($request);
        $platformBanner->update($data);

        $this->audit->log(
            $platformBanner->company_id !== null ? Company::query()->find($platformBanner->company_id) : null,
            $request->user(),
            'platform_banner.updated',
            $platformBanner,
            ['targets' => $platformBanner->targets],
        );

        return back()->with('success', 'Баннер обновлён.');
    }

    public function destroy(Request $request, PlatformBanner $platformBanner): RedirectResponse
    {
        $company = $platformBanner->company_id !== null
            ? Company::query()->find($platformBanner->company_id)
            : null;

        $this->audit->log($company, $request->user(), 'platform_banner.deleted', $platformBanner);

        $platformBanner->delete();

        return back()->with('success', 'Баннер удалён.');
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request): array
    {
        $data = $request->validate([
            'company_id' => ['nullable', 'integer', 'exists:companies,id'],
            'message_ru' => ['required', 'string', 'max:500'],
            'message_kk' => ['nullable', 'string', 'max:500'],
            'message_en' => ['nullable', 'string', 'max:500'],
            'background_color' => ['required', 'string', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'text_color' => ['nullable', 'string', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'starts_at' => ['nullable', 'date'],
            'ends_at' => ['nullable', 'date', 'after_or_equal:starts_at'],
            'targets' => ['required', 'string', Rule::in([
                PlatformBanner::TARGET_WEB,
                PlatformBanner::TARGET_MOBILE,
                PlatformBanner::TARGET_BOTH,
            ])],
            'priority' => ['nullable', 'integer', 'min:0', 'max:1000'],
            'is_published' => ['sometimes', 'boolean'],
        ]);

        $messageRu = trim($data['message_ru']);

        return [
            'company_id' => isset($data['company_id']) ? (int) $data['company_id'] : null,
            'message' => [
                'ru' => $messageRu,
                'kk' => filled($data['message_kk'] ?? null) ? trim((string) $data['message_kk']) : $messageRu,
                'en' => filled($data['message_en'] ?? null) ? trim((string) $data['message_en']) : $messageRu,
            ],
            'background_color' => strtolower($data['background_color']),
            'text_color' => strtolower((string) ($data['text_color'] ?? '#fffbeb')),
            'starts_at' => $data['starts_at'] ?? null,
            'ends_at' => $data['ends_at'] ?? null,
            'targets' => $data['targets'],
            'priority' => (int) ($data['priority'] ?? 0),
            'is_published' => (bool) ($data['is_published'] ?? true),
        ];
    }
}
