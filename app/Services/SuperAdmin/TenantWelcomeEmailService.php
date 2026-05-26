<?php

declare(strict_types=1);

namespace App\Services\SuperAdmin;

use App\Mail\TenantWelcomeMail;
use App\Models\Company;
use App\Models\User;
use Illuminate\Support\Facades\Mail;

final class TenantWelcomeEmailService
{
    public function __construct(
        private readonly SuperAdminAuditLogger $audit,
    ) {}

    /**
     * @return array{sent: bool, recipient: string|null, error: string|null}
     */
    public function send(
        Company $company,
        User $owner,
        string $temporaryPassword,
        ?User $actor = null,
    ): array {
        $company->loadMissing('plan');

        if ($owner->email === null || $owner->email === '') {
            return ['sent' => false, 'recipient' => null, 'error' => 'У заявки не указан email.'];
        }

        $loginUrl = $company->tenantUrl('/login');
        $trialDays = (int) ($company->plan?->trial_days ?? config('billing.trial_days', 14));

        try {
            Mail::to($owner->email)->send(new TenantWelcomeMail(
                $company,
                $owner,
                $loginUrl,
                $temporaryPassword,
                $trialDays,
            ));
        } catch (\Throwable $e) {
            report($e);

            return [
                'sent' => false,
                'recipient' => $owner->email,
                'error' => 'Не удалось отправить письмо: '.$e->getMessage(),
            ];
        }

        $this->audit->log($company, $actor, 'tenant.welcome_email_sent', $owner, [
            'recipient' => $owner->email,
            'login_url' => $loginUrl,
        ]);

        return ['sent' => true, 'recipient' => $owner->email, 'error' => null];
    }
}
