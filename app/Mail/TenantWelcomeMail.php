<?php

declare(strict_types=1);

namespace App\Mail;

use App\Models\Company;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

final class TenantWelcomeMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly Company $company,
        public readonly User $owner,
        public readonly string $loginUrl,
        public readonly string $temporaryPassword,
        public readonly int $trialDays,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Ваш аккаунт Accel готов — '.$this->company->name,
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'mail.tenant-welcome',
        );
    }
}
