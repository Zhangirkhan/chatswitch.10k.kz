<?php

declare(strict_types=1);

namespace App\Mail;

use App\Models\Company;
use App\Models\Invoice;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

final class InvoiceIssuedMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly Company $company,
        public readonly Invoice $invoice,
        public readonly string $printUrl,
        public readonly string $amountLabel,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Счёт '.$this->invoice->number.' — '.$this->company->name,
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'mail.invoice-issued',
        );
    }
}
