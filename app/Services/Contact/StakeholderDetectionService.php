<?php

declare(strict_types=1);

namespace App\Services\Contact;

use App\Models\Chat;
use App\Models\Contact;
use App\Models\ContactStakeholder;
use App\Models\Message;
use App\Support\AiFeatureFlags;
use App\Support\MessageInboundText;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;

final class StakeholderDetectionService
{
    /** @var list<string> */
    private const ROLE_PATTERNS = [
        ContactStakeholder::ROLE_DECISION_MAKER => '/(?:спрошу|согласую\s+с|реш(?:ает|ит)|нужно\s+с\s+)(?:\s+у)?\s+([а-яёa-z\s]{2,40})/iu',
        ContactStakeholder::ROLE_INFLUENCER => '/(?:посоветуюсь|обсужу)\s+(?:с\s+)?([а-яёa-z\s]{2,40})/iu',
        ContactStakeholder::ROLE_FINANCE => '/(?:бухгалтер|финанс|оплат(?:ит|у))\s*([а-яёa-z\s]{0,40})/iu',
    ];

    public function detectFromMessage(Chat $chat, Message $message): void
    {
        if ($chat->contact_id === null || ! AiFeatureFlags::enabled(AiFeatureFlags::STAKEHOLDERS, (int) $chat->company_id)) {
            return;
        }

        if (! Schema::hasTable('contact_stakeholders')) {
            return;
        }

        $text = trim(MessageInboundText::forMessage($message));
        if ($text === '') {
            return;
        }

        foreach (self::ROLE_PATTERNS as $role => $pattern) {
            if (! preg_match($pattern, $text, $matches)) {
                continue;
            }

            $name = $this->normalizeName((string) ($matches[1] ?? ''));
            if ($name === '') {
                continue;
            }

            $this->upsertStakeholder(
                (int) $chat->company_id,
                (int) $chat->contact_id,
                $name,
                $role,
                $text,
            );
        }
    }

    /**
     * @return Collection<int, ContactStakeholder>
     */
    public function forAccountContact(int $accountContactId): Collection
    {
        if (! Schema::hasTable('contact_stakeholders')) {
            return collect();
        }

        return ContactStakeholder::query()
            ->with('stakeholderContact:id,name,phone_number')
            ->where('account_contact_id', $accountContactId)
            ->orderByDesc('influence')
            ->get();
    }

    public function hasDecisionMaker(int $accountContactId): bool
    {
        return $this->forAccountContact($accountContactId)
            ->contains(static fn (ContactStakeholder $row): bool => $row->role === ContactStakeholder::ROLE_DECISION_MAKER);
    }

    public function upsertStakeholder(
        int $companyId,
        int $accountContactId,
        string $stakeholderName,
        string $role,
        ?string $notes = null,
        string $source = ContactStakeholder::SOURCE_AI,
    ): ContactStakeholder {
        $stakeholderContact = Contact::query()->firstOrCreate(
            [
                'company_id' => $companyId,
                'whatsapp_id' => 'stakeholder:'.md5($companyId.'|'.$stakeholderName),
            ],
            [
                'name' => $stakeholderName,
                'phone_number' => '',
            ],
        );

        return ContactStakeholder::query()->updateOrCreate(
            [
                'account_contact_id' => $accountContactId,
                'stakeholder_contact_id' => $stakeholderContact->id,
                'role' => $role,
            ],
            [
                'company_id' => $companyId,
                'influence' => $role === ContactStakeholder::ROLE_DECISION_MAKER ? 90 : 60,
                'notes' => $notes,
                'detected_at' => now(),
                'source' => $source,
            ],
        );
    }

    private function normalizeName(string $raw): string
    {
        $name = trim(preg_replace('/\s+/u', ' ', $raw) ?? '');
        $name = preg_replace('/^(у|с|мой|моя|наш|наша)\s+/iu', '', $name) ?? $name;
        $name = trim((string) (preg_split('/\s+(?:и|с|на|по|что)\s+/iu', $name)[0] ?? $name));
        $name = mb_convert_case(trim($name), MB_CASE_TITLE, 'UTF-8');

        if (mb_strlen($name) < 2 || mb_strlen($name) > 60) {
            return '';
        }

        return $name;
    }
}
