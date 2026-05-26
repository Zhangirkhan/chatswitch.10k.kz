<?php

declare(strict_types=1);

namespace App\Services\SuperAdmin;

use App\Mail\InvoiceIssuedMail;
use App\Models\Company;
use App\Models\Invoice;
use App\Models\User;
use App\Services\Billing\BillingRecipientResolver;
use Illuminate\Support\Facades\Mail;

final class InvoiceEmailService
{
    public function __construct(
        private readonly SuperAdminAuditLogger $audit,
        private readonly BillingRecipientResolver $recipients,
    ) {}

    /**
     * @return array{sent: bool, recipient: string|null, error: string|null}
     */
    public function send(Invoice $invoice, ?User $actor, ?string $recipientOverride = null): array
    {
        $invoice->loadMissing('company.owner');
        $company = $invoice->company;

        if ($company === null) {
            return ['sent' => false, 'recipient' => null, 'error' => 'Компания не найдена.'];
        }

        $recipient = $recipientOverride ?? $this->recipients->resolve($company);

        if ($recipient === null || $recipient === '') {
            return ['sent' => false, 'recipient' => null, 'error' => 'Укажите email владельца или компании.'];
        }

        $printUrl = route('super.invoices.print', $invoice, absolute: true);

        Mail::to($recipient)->send(new InvoiceIssuedMail(
            $company,
            $invoice,
            $printUrl,
            $this->amountLabel($invoice),
        ));

        $this->audit->log($company, $actor, 'invoice.email_sent', $invoice, [
            'recipient' => $recipient,
            'number' => $invoice->number,
        ]);

        return ['sent' => true, 'recipient' => $recipient, 'error' => null];
    }

    private function amountLabel(Invoice $invoice): string
    {
        $tenge = number_format($invoice->amount_cents / 100, 0, ',', ' ');

        return $tenge.' ₸';
    }
}
