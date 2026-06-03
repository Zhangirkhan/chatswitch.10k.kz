<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Company;
use App\Models\SystemSetting;
use App\Services\Calendar\AppointmentReminderSettings;
use App\Services\SuperAdmin\CompanyModuleSettingsService;
use App\Support\CompanyModules;
use App\Support\QuickReactions;
use App\Support\SlaReminderSettings;
use App\Support\SystemSettingKeys;
use App\Support\TenantCompany;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

final class SettingsController extends Controller
{
    public function __construct(
        private readonly CompanyModuleSettingsService $moduleSettings,
    ) {}

    public function index(): Response
    {
        $settings = collect(AppointmentReminderSettings::defaults())
            ->merge(QuickReactions::defaults())
            ->merge(SlaReminderSettings::defaults())
            ->merge(SystemSetting::all()->pluck('value', 'key'));

        $company = Company::query()
            ->withoutGlobalScope('tenant')
            ->findOrFail(TenantCompany::id());

        return Inertia::render('Settings/System', [
            'settings' => $settings,
            'modules' => $this->moduleSettings->payloadFor($company),
        ]);
    }

    public function update(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'settings' => 'required|array',
            'settings.*' => 'nullable|string|max:2000',
        ]);

        $settings = $this->normalizeSettings($validated['settings']);

        foreach ($settings as $key => $value) {
            if (! SystemSettingKeys::isAllowed($key)) {
                throw ValidationException::withMessages([
                    'settings' => "Недопустимый ключ настройки: {$key}",
                ]);
            }

            SystemSetting::setValue($key, $value);
        }

        return response()->json(['success' => true]);
    }

    public function updateModules(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'modules' => ['required', 'array'],
            'modules.*' => ['required', 'boolean'],
        ]);

        $company = Company::query()
            ->withoutGlobalScope('tenant')
            ->findOrFail(TenantCompany::id());

        $allowed = array_fill_keys(CompanyModules::keys(), true);
        $payload = array_intersect_key($validated['modules'], $allowed);

        $this->moduleSettings->update($company, $payload, $request->user());

        return response()->json(['success' => true]);
    }

    /**
     * @param  array<string, string|null>  $settings
     * @return array<string, string|null>
     */
    private function normalizeSettings(array $settings): array
    {
        $settings = array_filter(
            $settings,
            static fn (mixed $value, string $key): bool => ! CompanyModules::isModuleKey($key),
            ARRAY_FILTER_USE_BOTH,
        );

        if (array_key_exists(AppointmentReminderSettings::ENABLED_KEY, $settings)) {
            $enabled = $settings[AppointmentReminderSettings::ENABLED_KEY];
            if (! in_array($enabled, ['on', 'off'], true)) {
                throw ValidationException::withMessages([
                    AppointmentReminderSettings::ENABLED_KEY => 'Выберите, включены ли напоминания о записи.',
                ]);
            }
        }

        if (array_key_exists(AppointmentReminderSettings::LEAD_TIME_MINUTES_KEY, $settings)) {
            $raw = $settings[AppointmentReminderSettings::LEAD_TIME_MINUTES_KEY];
            if (! is_numeric($raw)) {
                throw ValidationException::withMessages([
                    AppointmentReminderSettings::LEAD_TIME_MINUTES_KEY => 'Укажите время напоминания в минутах.',
                ]);
            }

            $minutes = (int) $raw;
            if ($minutes < AppointmentReminderSettings::MIN_LEAD_TIME_MINUTES || $minutes > AppointmentReminderSettings::MAX_LEAD_TIME_MINUTES) {
                throw ValidationException::withMessages([
                    AppointmentReminderSettings::LEAD_TIME_MINUTES_KEY => 'Время напоминания должно быть от 5 минут до 7 дней.',
                ]);
            }

            $settings[AppointmentReminderSettings::LEAD_TIME_MINUTES_KEY] = (string) $minutes;
        }

        if (array_key_exists(SlaReminderSettings::ENABLED_KEY, $settings)) {
            $enabled = $settings[SlaReminderSettings::ENABLED_KEY];
            if (! in_array($enabled, ['on', 'off'], true)) {
                throw ValidationException::withMessages([
                    SlaReminderSettings::ENABLED_KEY => 'Выберите, включены ли SLA-напоминания.',
                ]);
            }
        }

        if (array_key_exists(SlaReminderSettings::MINUTES_KEY, $settings)) {
            $raw = $settings[SlaReminderSettings::MINUTES_KEY];
            if (! is_numeric($raw)) {
                throw ValidationException::withMessages([
                    SlaReminderSettings::MINUTES_KEY => 'Укажите время ожидания в минутах.',
                ]);
            }

            $minutes = (int) $raw;
            if ($minutes < SlaReminderSettings::MIN_MINUTES || $minutes > SlaReminderSettings::MAX_MINUTES) {
                throw ValidationException::withMessages([
                    SlaReminderSettings::MINUTES_KEY => 'Время ожидания: от '.SlaReminderSettings::MIN_MINUTES.' до '.SlaReminderSettings::MAX_MINUTES.' минут.',
                ]);
            }

            $settings[SlaReminderSettings::MINUTES_KEY] = (string) $minutes;
        }

        if (array_key_exists(QuickReactions::KEY, $settings)) {
            $raw = $settings[QuickReactions::KEY];
            $emojis = QuickReactions::normalize($raw);

            if (count($emojis) !== QuickReactions::COUNT) {
                throw ValidationException::withMessages([
                    QuickReactions::KEY => 'Укажите 5 быстрых реакций.',
                ]);
            }

            $settings[QuickReactions::KEY] = QuickReactions::encode($emojis);
        }

        return $settings;
    }
}
