<?php

declare(strict_types=1);

namespace App\Mail;

use App\Models\BillingReminderLog;
use App\Models\Company;
use Carbon\CarbonInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

final class PaymentReminderMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly Company $company,
        public readonly string $kind,
        public readonly CarbonInterface $dueAt,
        public readonly int $daysBefore,
        public readonly string $amountLabel,
        public readonly string $loginUrl,
        public readonly ?string $invoicePrintUrl,
    ) {}

    public function envelope(): Envelope
    {
        $subject = $this->kind === BillingReminderLog::KIND_TRIAL_ENDING
            ? 'Окончание пробного периода — '.$this->company->name
            : 'Напоминание об оплате подписки — '.$this->company->name;

        return new Envelope(subject: $subject);
    }

    public function content(): Content
    {
        return new Content(view: 'mail.payment-reminder');
    }
}
