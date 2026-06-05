<?php

declare(strict_types=1);

namespace App\Services\SuperAdmin;

use App\Models\Chat;
use App\Models\Company;
use App\Models\Contact;
use App\Models\Message;
use App\Models\WhatsappSession;
use App\Services\Company\DemoChatsFactory;
use Illuminate\Support\Facades\DB;

final class TenantSandboxMarkerService
{
    public function backfillAllCompanies(): void
    {
        Company::query()
            ->withoutGlobalScope('tenant')
            ->orderBy('id')
            ->each(fn (Company $company) => $this->backfillCompany($company));
    }

    public function backfillCompany(Company $company): void
    {
        $companyId = (int) $company->id;
        $demoPhones = DemoChatsFactory::demoPhoneDigits();

        Contact::query()
            ->withoutGlobalScope('tenant')
            ->where('company_id', $companyId)
            ->whereIn('phone_number', $demoPhones)
            ->where('whatsapp_id', 'like', '%.'.$companyId.'@c.us')
            ->update(['is_sandbox' => true]);

        Chat::query()
            ->withoutGlobalScope('tenant')
            ->where('company_id', $companyId)
            ->whereIn('contact_id', Contact::query()
                ->withoutGlobalScope('tenant')
                ->where('company_id', $companyId)
                ->where('is_sandbox', true)
                ->select('id'))
            ->update(['is_sandbox' => true]);

        Chat::query()
            ->withoutGlobalScope('tenant')
            ->where('company_id', $companyId)
            ->orderBy('id')
            ->each(function (Chat $chat): void {
                if (! $this->chatContainsOnlyDemoMessages($chat)) {
                    return;
                }

                if ($this->chatContainsRealMessages($chat)) {
                    return;
                }

                $chat->forceFill(['is_sandbox' => true])->save();
            });

        WhatsappSession::query()
            ->withoutGlobalScope('tenant')
            ->where('company_id', $companyId)
            ->whereIn('phone_number', DemoChatsFactory::demoSessionPhoneDigits())
            ->update(['is_sandbox' => true]);
    }

    public function chatContainsOnlyDemoMessages(Chat $chat): bool
    {
        return Message::query()
            ->where('chat_id', $chat->id)
            ->exists()
            && ! $this->chatContainsRealMessages($chat);
    }

    public function chatContainsRealMessages(Chat $chat): bool
    {
        return Message::query()
            ->where('chat_id', $chat->id)
            ->get(['whatsapp_message_id'])
            ->contains(static fn (Message $message): bool => ! DemoChatsFactory::isDemoWhatsappMessageId($message->whatsapp_message_id));
    }

    public function contactLinkedToRealChat(int $contactId): bool
    {
        return Chat::query()
            ->withoutGlobalScope('tenant')
            ->where('contact_id', $contactId)
            ->where('is_sandbox', false)
            ->exists();
    }

    public function sessionLinkedToRealChat(int $sessionId): bool
    {
        return Chat::query()
            ->withoutGlobalScope('tenant')
            ->where('whatsapp_session_id', $sessionId)
            ->where('is_sandbox', false)
            ->exists();
    }
}
