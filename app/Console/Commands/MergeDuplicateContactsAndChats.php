<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Chat;
use App\Models\Contact;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Merges duplicate contacts (same phone_number, excluding group contacts)
 * and duplicate chats that arise from the WhatsApp @lid vs @c.us ID mismatch.
 *
 * Safe to run multiple times (idempotent).
 */
final class MergeDuplicateContactsAndChats extends Command
{
    protected $signature = 'contacts:merge-duplicates {--dry-run : Preview only, make no changes}';

    protected $description = 'Merge duplicate contacts (same phone_number) and their chats';

    public function handle(): int
    {
        $dry = (bool) $this->option('dry-run');

        if ($dry) {
            $this->info('[DRY RUN] No changes will be written.');
        }

        $this->mergeContacts($dry);
        $this->mergeDuplicateChats($dry);

        $this->info('Done.');

        return self::SUCCESS;
    }

    // ──────────────────────────────────────────────────────────────────────────

    private function mergeContacts(bool $dry): void
    {
        $this->info('=== Merging duplicate contacts by phone_number ===');

        $groups = DB::table('contacts')
            ->select('phone_number', DB::raw('COUNT(*) as cnt'))
            ->whereNotNull('phone_number')
            ->where('phone_number', '!=', '')
            // Real phone numbers are ≤15 digits; @lid numeric parts are typically ≥16
            ->whereRaw('LENGTH(phone_number) <= 15')
            // Skip group participants stored with a phone-number identical to another contact
            ->whereNotExists(function ($q): void {
                $q->from('contacts as gc')
                    ->whereColumn('gc.phone_number', 'contacts.phone_number')
                    ->where('gc.whatsapp_id', 'like', '%@g.us%');
            })
            ->groupBy('phone_number')
            ->having('cnt', '>', 1)
            ->get();

        if ($groups->isEmpty()) {
            $this->line('No duplicate contacts found.');

            return;
        }

        foreach ($groups as $group) {
            $contacts = Contact::where('phone_number', $group->phone_number)
                ->whereRaw('LENGTH(phone_number) <= 15')
                ->orderBy('id')
                ->get();

            // Choose the keeper: prefer the one whose wa_id contains @lid (real WhatsApp ID),
            // then the one with the most messages across all their chats.
            $keeper = $contacts->sortByDesc(function (Contact $c): int {
                $chatIds = Chat::where('contact_id', $c->id)->pluck('id');
                $msgs = $chatIds->isNotEmpty()
                    ? DB::table('messages')->whereIn('chat_id', $chatIds)->count()
                    : 0;

                // Bonus for @lid contact — they're the canonical WhatsApp identity
                $lidBonus = str_ends_with((string) $c->whatsapp_id, '@lid') ? 1_000_000 : 0;

                return $msgs + $lidBonus;
            })->first();

            $duplicates = $contacts->filter(fn (Contact $c) => $c->id !== $keeper->id);

            $this->line(sprintf(
                'phone=%s  keeper=#%d (wa_id=%s)  duplicates: [%s]',
                $group->phone_number,
                $keeper->id,
                $keeper->whatsapp_id,
                $duplicates->pluck('id')->implode(', '),
            ));

            if ($dry) {
                continue;
            }

            foreach ($duplicates as $dup) {
                DB::table('chats')
                    ->where('contact_id', $dup->id)
                    ->update(['contact_id' => $keeper->id]);

                // messages table may not have a contact_id column — skip gracefully
                if (DB::getSchemaBuilder()->hasColumn('messages', 'contact_id')) {
                    DB::table('messages')
                        ->where('contact_id', $dup->id)
                        ->update(['contact_id' => $keeper->id]);
                }

                $dup->delete();
            }
        }
    }

    private function mergeDuplicateChats(bool $dry): void
    {
        $this->info('=== Merging duplicate chats (same contact + session) ===');

        $groups = DB::table('chats')
            ->select(
                'contact_id',
                'whatsapp_session_id',
                DB::raw('COUNT(*) as cnt'),
                DB::raw('GROUP_CONCAT(id ORDER BY last_message_at DESC, id DESC) as chat_ids'),
            )
            ->whereNotNull('contact_id')
            ->where('is_group', 0)
            ->groupBy('contact_id', 'whatsapp_session_id')
            ->having('cnt', '>', 1)
            ->get();

        if ($groups->isEmpty()) {
            $this->line('No duplicate chats found.');

            return;
        }

        foreach ($groups as $group) {
            $ids = array_map('intval', explode(',', $group->chat_ids));
            // Keeper = chat with most recent activity (first in list)
            $keeperId = $ids[0];
            $dupIds = array_slice($ids, 1);

            $this->line(sprintf(
                'contact=%d session=%d  keeper=#%d  merging: [%s]',
                $group->contact_id,
                $group->whatsapp_session_id,
                $keeperId,
                implode(', ', $dupIds),
            ));

            if ($dry) {
                continue;
            }

            foreach ($dupIds as $dupId) {
                DB::table('messages')->where('chat_id', $dupId)->update(['chat_id' => $keeperId]);

                // Move assignments (avoid duplicates)
                DB::table('chat_assignments')
                    ->where('chat_id', $dupId)
                    ->whereNotExists(function ($q) use ($keeperId): void {
                        $q->from('chat_assignments as ca2')
                            ->whereColumn('ca2.user_id', 'chat_assignments.user_id')
                            ->where('ca2.chat_id', $keeperId);
                    })
                    ->update(['chat_id' => $keeperId]);

                // Update keeper's last_message_at if dup was more recent
                $dupChat = Chat::find($dupId);
                $keeperChat = Chat::find($keeperId);
                if ($dupChat && $keeperChat && $dupChat->last_message_at > $keeperChat->last_message_at) {
                    $keeperChat->update(['last_message_at' => $dupChat->last_message_at]);
                }

                Chat::where('id', $dupId)->delete();
            }
        }
    }
}
