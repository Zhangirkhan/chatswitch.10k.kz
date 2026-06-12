<?php

declare(strict_types=1);

namespace App\Services\AI;

use App\Models\Chat;
use App\Models\SalesPlaybook;
use Illuminate\Support\Facades\Schema;

final class PlaybookSelector
{
    /** @var list<string> */
    private const KEYWORDS_B2B = ['b2b', 'опт', 'компан', 'юр', 'договор', 'счёт', 'тендер'];

    /** @var list<string> */
    private const KEYWORDS_SERVICES = ['запись', 'консультац', 'услуг', 'приём', 'замер'];

    /** @var list<string> */
    private const KEYWORDS_LOGISTICS = ['достав', 'логист', 'груз', 'склад', 'транспорт'];

    public function resolveForChat(Chat $chat): ?SalesPlaybook
    {
        if (! Schema::hasTable('sales_playbooks')) {
            return null;
        }

        $companyId = (int) $chat->company_id;

        $tenantPlaybook = SalesPlaybook::query()
            ->where('company_id', $companyId)
            ->where('is_active', true)
            ->orderBy('id')
            ->first();

        if ($tenantPlaybook !== null) {
            return $tenantPlaybook;
        }

        $slug = $this->inferSlugFromChat($chat);

        return SalesPlaybook::query()
            ->whereNull('company_id')
            ->where('slug', $slug)
            ->where('is_active', true)
            ->first()
            ?? SalesPlaybook::query()
                ->whereNull('company_id')
                ->where('slug', 'b2c_retail')
                ->where('is_active', true)
                ->first();
    }

    private function inferSlugFromChat(Chat $chat): string
    {
        $haystack = mb_strtolower(implode(' ', array_filter([
            (string) $chat->chat_name,
            (string) ($chat->contact?->name ?? ''),
            (string) ($chat->active_topic ?? ''),
        ])));

        if ($this->containsAny($haystack, self::KEYWORDS_LOGISTICS)) {
            return 'logistics';
        }
        if ($this->containsAny($haystack, self::KEYWORDS_SERVICES)) {
            return 'services_booking';
        }
        if ($this->containsAny($haystack, self::KEYWORDS_B2B)) {
            return 'b2b_equipment';
        }

        return 'b2c_retail';
    }

    /**
     * @param  list<string>  $needles
     */
    private function containsAny(string $haystack, array $needles): bool
    {
        foreach ($needles as $needle) {
            if (str_contains($haystack, $needle)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<string>
     */
    public function qualificationFieldOrder(?SalesPlaybook $playbook): array
    {
        if ($playbook === null) {
            return ['budget', 'requirements', 'timeline', 'decision_maker'];
        }

        $fields = $playbook->qualification_fields;
        if (! is_array($fields) || $fields === []) {
            return ['budget', 'requirements', 'timeline', 'decision_maker'];
        }

        return array_values(array_map(static fn ($f): string => (string) $f, $fields));
    }
}
