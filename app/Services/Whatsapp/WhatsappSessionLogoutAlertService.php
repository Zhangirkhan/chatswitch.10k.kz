<?php

declare(strict_types=1);

namespace App\Services\Whatsapp;

use App\Mail\WhatsappSessionLogoutAlertMail;
use App\Models\Company;
use App\Models\WhatsappSession;
use App\Services\Alerts\TelegramAlertSender;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

final class WhatsappSessionLogoutAlertService
{
    private const ALERT_SENT_CACHE_PREFIX = 'whatsapp_session_logout_alert_sent:';

    public function __construct(
        private readonly WhatsappSessionAlertRecipientResolver $recipients,
        private readonly TelegramAlertSender $telegram,
    ) {}

    /**
     * @return 'alert_sent'|'skipped'|'deduped'
     */
    public function notify(WhatsappSession $session, ?string $reason = null): string
    {
        if (! config('accel.whatsapp_alerts.enabled', true)) {
            return 'skipped';
        }

        if (! config('accel.whatsapp_alerts.logout_enabled', true)) {
            return 'skipped';
        }

        if ($session->desired_state === WhatsappSession::DESIRED_LOGGED_OUT) {
            return 'skipped';
        }

        if (strtoupper((string) $reason) !== 'LOGOUT') {
            return 'skipped';
        }

        if (! $this->shouldSendAlert($session)) {
            return 'deduped';
        }

        $this->sendAlerts($session);
        $this->markAlertSent($session, now());

        return 'alert_sent';
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

    private function markAlertSent(WhatsappSession $session, Carbon $at): void
    {
        Cache::put($this->alertSentKey($session), $at->toIso8601String(), now()->addDays(2));
    }

    private function sendAlerts(WhatsappSession $session): void
    {
        $company = Company::query()
            ->withoutGlobalScope('tenant')
            ->find($session->company_id);

        if ($company === null) {
            return;
        }

        $connectionsUrl = $company->tenantUrl('/settings/connections');
        $label = $session->display_name ?: $session->phone_number ?: $session->session_name;

        foreach ($this->recipients->tenantAdminEmails($session) as $email) {
            try {
                Mail::to($email)->send(new WhatsappSessionLogoutAlertMail(
                    company: $company,
                    session: $session,
                    connectionsUrl: $connectionsUrl,
                    forOps: false,
                ));
            } catch (\Throwable $e) {
                Log::warning('[whatsapp-alert] logout tenant email failed', [
                    'session' => $session->session_name,
                    'email' => $email,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        foreach ($this->recipients->opsEmails() as $email) {
            try {
                Mail::to($email)->send(new WhatsappSessionLogoutAlertMail(
                    company: $company,
                    session: $session,
                    connectionsUrl: $connectionsUrl,
                    forOps: true,
                ));
            } catch (\Throwable $e) {
                Log::warning('[whatsapp-alert] logout ops email failed', [
                    'session' => $session->session_name,
                    'email' => $email,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        if ($this->telegram->configured()) {
            $text = implode("\n", [
                '<b>WhatsApp LOGOUT</b>',
                'Компания: '.e($company->name).' ('.e((string) $company->slug).')',
                'Подключение: '.e($label),
                'Session: <code>'.e($session->session_name).'</code>',
                'Нужен QR или авто-реконнект',
            ]);

            $this->telegram->send($text);
        }

        Log::warning('[whatsapp-alert] session LOGOUT notification sent', [
            'session' => $session->session_name,
            'company_id' => $session->company_id,
            'tenant_emails' => count($this->recipients->tenantAdminEmails($session)),
            'ops_emails' => count($this->recipients->opsEmails()),
            'telegram' => $this->telegram->configured(),
        ]);
    }

    private function alertSentKey(WhatsappSession $session): string
    {
        return self::ALERT_SENT_CACHE_PREFIX.$session->id;
    }
}
