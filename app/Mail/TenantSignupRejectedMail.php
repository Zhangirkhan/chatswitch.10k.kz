<?php

declare(strict_types=1);

namespace App\Mail;

use App\Models\TenantSignupRequest;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

final class TenantSignupRejectedMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly TenantSignupRequest $signupRequest,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Заявка на Accel — '.$this->signupRequest->company_name,
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'mail.tenant-signup-rejected',
        );
    }
}
