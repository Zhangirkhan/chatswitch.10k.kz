<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\UserDevice;
use App\Services\Push\FcmAccessTokenProvider;
use Illuminate\Console\Command;

final class PushDiagnosticsCommand extends Command
{
    protected $signature = 'push:diagnostics';

    protected $description = 'Проверка готовности FCM push (токены, credentials, queue)';

    public function handle(FcmAccessTokenProvider $tokenProvider): int
    {
        $enabled = (bool) config('services.firebase.enabled', false);
        $credentials = (string) config('services.firebase.credentials', '');

        $this->line('FIREBASE_FCM_ENABLED: '.($enabled ? 'true' : 'false'));
        $this->line('FIREBASE_CREDENTIALS: '.($credentials !== '' ? $credentials : '(not set)'));
        $this->line('Credentials file exists: '.(is_file($credentials) ? 'yes' : 'no'));

        if (is_file($credentials)) {
            $json = json_decode((string) file_get_contents($credentials), true);
            $projectId = is_array($json) ? (string) ($json['project_id'] ?? '') : '';
            $this->line('Firebase project_id: '.($projectId !== '' ? $projectId : '(missing)'));
            if ($projectId !== '' && $projectId !== 'accel-d52ce') {
                $this->warn('Expected project_id accel-d52ce — check service account matches google-services.json');
            }
        }

        if ($enabled && $tokenProvider->isConfigured()) {
            $token = $tokenProvider->getAccessToken();
            $this->line('FCM OAuth token: '.($token !== null ? 'ok' : 'FAILED'));
        } else {
            $this->warn('FCM sender disabled or credentials missing — push jobs will no-op.');
        }

        $deviceCount = UserDevice::query()->withoutGlobalScope('tenant')->count();
        $this->line('user_devices rows: '.$deviceCount);

        UserDevice::query()
            ->withoutGlobalScope('tenant')
            ->latest('updated_at')
            ->limit(5)
            ->get(['id', 'user_id', 'company_id', 'platform', 'fcm_token', 'updated_at'])
            ->each(function (UserDevice $device): void {
                $this->line(sprintf(
                    '  #%d user=%d company=%d platform=%s token=%s… updated=%s',
                    $device->id,
                    $device->user_id,
                    $device->company_id,
                    $device->platform,
                    substr($device->fcm_token, 0, 16),
                    $device->updated_at?->toIso8601String() ?? '—',
                ));
            });

        return self::SUCCESS;
    }
}
