<?php

declare(strict_types=1);

namespace App\Services\SuperAdmin;

use App\Models\Chat;
use App\Models\Company;
use App\Models\Contact;
use App\Models\User;
use App\Models\WhatsappSession;
use Illuminate\Support\Facades\DB;

final class TenantSandboxCleanupService
{
    public function __construct(
        private readonly TenantSandboxMarkerService $marker,
        private readonly SuperAdminAuditLogger $audit,
    ) {}

    /**
     * @return array{chats: int, contacts: int, sessions: int, skipped_chats: int}
     */
    public function clear(Company $company, ?User $actor = null): array
    {
        return DB::transaction(function () use ($company, $actor): array {
            $companyId = (int) $company->id;
            $stats = [
                'chats' => 0,
                'contacts' => 0,
                'sessions' => 0,
                'skipped_chats' => 0,
            ];

            $sandboxChatIds = Chat::query()
                ->withoutGlobalScope('tenant')
                ->where('company_id', $companyId)
                ->where('is_sandbox', true)
                ->orderBy('id')
                ->pluck('id')
                ->all();

            foreach ($sandboxChatIds as $chatId) {
                $chat = Chat::query()->withoutGlobalScope('tenant')->whereKey($chatId)->first();
                if ($chat === null) {
                    continue;
                }

                if ($this->marker->chatContainsRealMessages($chat)) {
                    $chat->forceFill(['is_sandbox' => false])->save();
                    $stats['skipped_chats']++;

                    continue;
                }

                $chat->delete();
                $stats['chats']++;
            }

            Contact::query()
                ->withoutGlobalScope('tenant')
                ->where('company_id', $companyId)
                ->where('is_sandbox', true)
                ->orderBy('id')
                ->each(function (Contact $contact) use (&$stats): void {
                    if ($this->marker->contactLinkedToRealChat((int) $contact->id)) {
                        $contact->forceFill(['is_sandbox' => false])->save();

                        return;
                    }

                    if ($contact->chats()->withoutGlobalScope('tenant')->exists()) {
                        return;
                    }

                    $contact->delete();
                    $stats['contacts']++;
                });

            WhatsappSession::query()
                ->withoutGlobalScope('tenant')
                ->where('company_id', $companyId)
                ->where('is_sandbox', true)
                ->orderBy('id')
                ->each(function (WhatsappSession $session) use (&$stats): void {
                    if ($this->marker->sessionLinkedToRealChat((int) $session->id)) {
                        $session->forceFill(['is_sandbox' => false])->save();

                        return;
                    }

                    if ($session->chats()->withoutGlobalScope('tenant')->exists()) {
                        return;
                    }

                    DB::table('user_whatsapp_session')->where('whatsapp_session_id', $session->id)->delete();
                    $session->delete();
                    $stats['sessions']++;
                });

            $this->audit->log($company, $actor, 'tenant.sandbox_cleared', $company, $stats);

            return $stats;
        });
    }
}
