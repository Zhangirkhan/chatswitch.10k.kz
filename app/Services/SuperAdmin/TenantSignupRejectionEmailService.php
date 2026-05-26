<?php

declare(strict_types=1);

namespace App\Services\SuperAdmin;

use App\Mail\TenantSignupRejectedMail;
use App\Models\TenantSignupRequest;
use App\Models\User;
use Illuminate\Support\Facades\Mail;

final class TenantSignupRejectionEmailService
{
    public function __construct(
        private readonly SuperAdminAuditLogger $audit,
    ) {}

    /**
     * @return array{sent: bool, recipient: string|null, error: string|null}
     */
    public function send(TenantSignupRequest $signupRequest, ?User $actor = null): array
    {
        if ($signupRequest->email === null || $signupRequest->email === '') {
            return ['sent' => false, 'recipient' => null, 'error' => 'У заявки не указан email.'];
        }

        try {
            Mail::to($signupRequest->email)->send(new TenantSignupRejectedMail($signupRequest));
        } catch (\Throwable $e) {
            report($e);

            return [
                'sent' => false,
                'recipient' => $signupRequest->email,
                'error' => 'Не удалось отправить письмо: '.$e->getMessage(),
            ];
        }

        $this->audit->log(null, $actor, 'tenant.rejection_email_sent', $signupRequest, [
            'recipient' => $signupRequest->email,
            'signup_request_id' => $signupRequest->id,
            'company_name' => $signupRequest->company_name,
        ]);

        return ['sent' => true, 'recipient' => $signupRequest->email, 'error' => null];
    }
}
