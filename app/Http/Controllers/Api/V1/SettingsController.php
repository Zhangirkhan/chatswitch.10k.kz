<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\SystemSetting;
use App\Services\Calendar\AppointmentReminderSettings;
use App\Support\CompanyModules;
use App\Support\MobileApiSettingKeys;
use App\Support\QuickReactions;
use App\Support\SlaReminderSettings;
use Illuminate\Http\JsonResponse;

final class SettingsController extends Controller
{
    public function show(): JsonResponse
    {
        $allowedKeys = MobileApiSettingKeys::allowed();

        $settings = collect(AppointmentReminderSettings::defaults())
            ->merge(QuickReactions::defaults())
            ->merge(SlaReminderSettings::defaults())
            ->merge(
                SystemSetting::query()
                    ->whereIn('key', $allowedKeys)
                    ->pluck('value', 'key'),
            )
            ->only($allowedKeys);

        return response()->json([
            'app_version' => (string) config('app.version', '1.0.0'),
            'settings' => $settings,
            'modules' => collect(CompanyModules::keys())
                ->mapWithKeys(fn (string $key): array => [
                    $key => SystemSetting::getValue($key, 'on'),
                ])
                ->all(),
        ]);
    }
}
