<?php

declare(strict_types=1);

namespace App\Services\Whatsapp;

use App\Mail\WhatsappSessionDownAlertMail;
use App\Models\Company;
use App\Models\WhatsappSession;
use App\Services\Alerts\TelegramAlertSender;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

final class WhatsappSessionHealthMonitorService
{
    private const DOWN_SINCE_CACHE_PREFIX = 'whatsapp_session_down_since:';

    private const ALERT_SENT_CACHE_PREFIX = 'whatsapp_session_alert_sent:';

    public function __construct(
        private readonly WhatsappSessionAlertRecipientResolver $recipients,
        private readonly TelegramAlertSender $telegram,
    ) {}

    /**
     * @param  array<string, mixed>  $verify
     * @return 'alert_sent'|'tracking'|'recovered'|'skipped'
     */
    public function observe(WhatsappSession $session, array $verify): string
    {
        if (! config('accel.whatsapp_alerts.enabled', true)) {
            return 'skipped';
        }

        if ($session->desired_state === WhatsappSession::DESIRED_LOGGED_OUT) {
            $this->clearTracking($session);

            return 'skipped';
        }

        if ($this->isHealthy($verify)) {
            if ($this->clearTracking($session)) {
                return 'recovered';
            }

            return 'skipped';
        }

        if ((bool) ($verify['isInitializing'] ?? false) || (bool) ($verify['hasQR'] ?? false)) {
            return 'skipped';
        }

        $downSince = $this->downSince($session);
        if ($downSince === null) {
            $this->markDownSince($session, now());

            return 'tracking';
        }

        $downMinutes = (int) config('accel.whatsapp_alerts.down_minutes', 5);
        if ($downSince->diffInMinutes(now()) < $downMinutes) {
            return 'tracking';
        }

        if (! $this->shouldSendAlert($session)) {
            return 'tracking';
        }

        $this->sendAlerts($session, $verify, $downMinutes);
        $this->markAlertSent($session, now());

        return 'alert_sent';
    }

    /**
     * @param  array<string, mixed>  $verify
     */
    private function isHealthy(array $verify): bool
    {
        return (bool) ($verify['alive'] ?? false);
    }

    private function downSince(WhatsappSession $session): ?Carbon
    {
        $value = Cache::get($this->downSinceKey($session));

        if (! is_string($value) || $value === '') {
            return null;
        }

        try {
            return Carbon::parse($value);
        } catch (\Throwable) {
            return null;
        }
    }

    private function markDownSince(WhatsappSession $session, Carbon $at): void
    {
        Cache::put($this->downSinceKey($session), $at->toIso8601String(), now()->addDays(2));
    }

    private function markAlertSent(WhatsappSession $session, Carbon $at): void
    {
        Cache::put($this->alertSentKey($session), $at->toIso8601String(), now()->addDays(2));
    }

    private function shouldSendAlert(WhatsappSession $session): bool
    {
        $sentAt = Cache::get($this->alertSentKey($session));
        if (! is_string($sentAt) || $sentAt === '') {
            return true;
        }

        try {
            $repeatHours = max(1, (int) config('accel.whatsapp_alerts.repeat_hours', 24));

            return Carbon::parse($sentAt)->addHours($repeatHours)->lte(now());
        } catch (\Throwable) {
            return true;
        }
    }

    private function clearTracking(WhatsappSession $session): bool
    {
        $hadState = Cache::has($this->downSinceKey($session))
            || Cache::has($this->alertSentKey($session));

        Cache::forget($this->downSinceKey($session));
        Cache::forget($this->alertSentKey($session));

        return $hadState;
    }

    /**
     * @param  array<string, mixed>  $verify
     */
    private function sendAlerts(WhatsappSession $session, array $verify, int $downMinutes): void
    {
        $company = Company::query()
            ->withoutGlobalScope('tenant')
            ->find($session->company_id);

        if ($company === null) {
            return;
        }

        $lastError = trim((string) ($verify['lastError'] ?? $session->last_auth_failure_message ?? ''));
        $connectionsUrl = $company->tenantUrl('/settings/connections');
        $label = $session->display_name ?: $session->phone_number ?: $session->session_name;

        foreach ($this->recipients->tenantAdminEmails($session) as $email) {
            try {
                Mail::to($email)->send(new WhatsappSessionDownAlertMail(
                    company: $company,
                    session: $session,
                    downMinutes: $downMinutes,
                    connectionsUrl: $connectionsUrl,
                    lastError: $lastError !== '' ? $lastError : null,
                    forOps: false,
                ));
            } catch (\Throwable $e) {
                Log::warning('[whatsapp-alert] tenant email failed', [
                    'session' => $session->session_name,
                    'email' => $email,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        foreach ($this->recipients->opsEmails() as $email) {
            try {
                Mail::to($email)->send(new WhatsappSessionDownAlertMail(
                    company: $company,
                    session: $session,
                    downMinutes: $downMinutes,
                    connectionsUrl: $connectionsUrl,
                    lastError: $lastError !== '' ? $lastError : null,
                    forOps: true,
                ));
            } catch (\Throwable $e) {
                Log::warning('[whatsapp-alert] ops email failed', [
                    'session' => $session->session_name,
                    'email' => $email,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        if ($this->telegram->configured()) {
            $reasoning = is_array($verify['reasoning'] ?? null)
                ? implode(', ', $verify['reasoning'])
                : 'unknown';

            $text = implode("\n", array_filter([
                '<b>WhatsApp не восстановился</b>',
                'Компания: '.e($company->name).' ('.e((string) $company->slug).')',
                'Подключение: '.e($label),
                'Session: <code>'.e($session->session_name).'</code>',
                'Недоступно: ≥ '.$downMinutes.' мин',
                $lastError !== '' ? 'Ошибка: '.e($lastError) : null,
                'Verify: '.e($reasoning),
            ]));

            $this->telegram->send($text);
        }

        Log::warning('[whatsapp-alert] session down notification sent', [
            'session' => $session->session_name,
            'company_id' => $session->company_id,
            'down_minutes' => $downMinutes,
            'tenant_emails' => count($this->recipients->tenantAdminEmails($session)),
            'ops_emails' => count($this->recipients->opsEmails()),
            'telegram' => $this->telegram->configured(),
        ]);
    }

    private function downSinceKey(WhatsappSession $session): string
    {
        return self::DOWN_SINCE_CACHE_PREFIX.$session->id;
    }

    private function alertSentKey(WhatsappSession $session): string
    {
        return self::ALERT_SENT_CACHE_PREFIX.$session->id;
    }
}
