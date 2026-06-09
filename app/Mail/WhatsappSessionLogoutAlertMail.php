<?php

declare(strict_types=1);

namespace App\Mail;

use App\Models\Company;
use App\Models\WhatsappSession;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

final class WhatsappSessionLogoutAlertMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly Company $company,
        public readonly WhatsappSession $session,
        public readonly string $connectionsUrl,
        public readonly bool $forOps,
    ) {}

    public function envelope(): Envelope
    {
        $label = $this->sessionLabel();

        return new Envelope(
            subject: 'WhatsApp разлогинил устройство — '.$this->company->name.' ('.$label.')',
        );
    }

    public function content(): Content
    {
        return new Content(view: 'mail.whatsapp-session-logout');
    }

    public function sessionLabel(): string
    {
        return $this->session->display_name
            ?: $this->session->phone_number
            ?: $this->session->session_name;
    }
}
