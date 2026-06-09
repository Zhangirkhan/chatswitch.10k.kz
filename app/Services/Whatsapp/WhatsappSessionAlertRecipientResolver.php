<?php

declare(strict_types=1);

namespace App\Services\Whatsapp;

use App\Models\Company;
use App\Models\User;
use App\Models\WhatsappSession;
use App\Services\Billing\BillingRecipientResolver;

final class WhatsappSessionAlertRecipientResolver
{
    public function __construct(
        private readonly BillingRecipientResolver $billingRecipients,
    ) {}

    /**
     * @return list<string>
     */
    public function tenantAdminEmails(WhatsappSession $session): array
    {
        $company = Company::query()
            ->withoutGlobalScope('tenant')
            ->find($session->company_id);

        if ($company === null) {
            return [];
        }

        $emails = [];

        $ownerEmail = $this->billingRecipients->resolve($company);
        if ($ownerEmail !== null) {
            $emails[] = $ownerEmail;
        }

        $adminEmails = User::query()
            ->withoutGlobalScope('tenant')
            ->where('company_id', $company->id)
            ->where('is_active', true)
            ->role('administrator')
            ->pluck('email')
            ->filter(static fn ($email): bool => is_string($email) && trim($email) !== '')
            ->map(static fn (string $email): string => trim($email))
            ->all();

        return array_values(array_unique(array_merge($emails, $adminEmails)));
    }

    /**
     * @return list<string>
     */
    public function opsEmails(): array
    {
        $raw = config('accel.whatsapp_alerts.ops_emails', []);

        if (is_string($raw)) {
            $raw = array_map('trim', explode(',', $raw));
        }

        if (! is_array($raw)) {
            return [];
        }

        return array_values(array_unique(array_filter(
            $raw,
            static fn ($email): bool => is_string($email) && trim($email) !== '' && filter_var(trim($email), FILTER_VALIDATE_EMAIL),
        )));
    }
}
