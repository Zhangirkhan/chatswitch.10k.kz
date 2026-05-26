<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Contact;
use App\Models\Message;
use App\Models\TeamMessage;
use App\Models\TeamMessageAttachment;

final class SharedMessageQuote
{
    /**
     * @return array{0: string, 1: string, 2: string} title, sender, body
     */
    public static function fromTeamMessage(TeamMessage $source, string $conversationTitle): array
    {
        $sourceTitle = 'тим-чат · '.$conversationTitle;
        $quoteSender = (string) ($source->sender?->name ?? '…');
        $textForQuote = trim((string) $source->body);
        if ($textForQuote === '' && is_string($source->forward_quote_body) && trim($source->forward_quote_body) !== '') {
            $textForQuote = trim($source->forward_quote_body);
        }

        if ($textForQuote === '' && $source->relationLoaded('attachments') && $source->attachments->isNotEmpty()) {
            $names = $source->attachments
                ->map(fn (TeamMessageAttachment $a) => '📎 '.($a->original_name ?: 'файл'))
                ->take(3)
                ->all();
            $textForQuote = implode(', ', $names);
            if ($source->attachments->count() > 3) {
                $textForQuote .= ' …';
            }
        }

        $quoteBody = mb_substr(preg_replace('/\s+/u', ' ', $textForQuote) ?? '', 0, 480);

        return [$sourceTitle, $quoteSender, $quoteBody];
    }

    /**
     * @return array{0: string, 1: string, 2: string}
     */
    public static function fromWhatsappMessage(Message $source): array
    {
        $chat = $source->chat;
        $contactLabel = self::whatsappContactLabel($chat?->chat_name, $chat?->contact);
        $sourceTitle = 'WhatsApp · '.$contactLabel;

        if ($source->direction === 'outbound') {
            $quoteSender = (string) ($source->sentByUser?->name ?? $source->sender_name ?? 'Оператор');
        } else {
            $quoteSender = trim((string) ($source->sender_name ?? ''));
            if ($quoteSender === '') {
                $quoteSender = $contactLabel;
            }
        }

        $raw = trim((string) ($source->body ?? ''));
        if ($raw === '') {
            $raw = MediaType::previewText($source->type ?: 'chat', null);
        }

        $quoteBody = mb_substr(preg_replace('/\s+/u', ' ', $raw) ?? '', 0, 480);

        return [$sourceTitle, $quoteSender, $quoteBody];
    }

    public static function quoteBlock(string $sourceTitle, string $quoteSender, string $quoteBody): string
    {
        $lines = ['[Переслано · '.$sourceTitle.']'];
        if ($quoteBody !== '') {
            $lines[] = $quoteSender.': '.$quoteBody;
        }

        return implode("\n", $lines);
    }

    private static function whatsappContactLabel(?string $chatName, ?Contact $contact): string
    {
        $chatName = trim((string) $chatName);
        if ($chatName !== '') {
            return $chatName;
        }

        if ($contact === null) {
            return 'Клиент';
        }

        $saved = trim((string) ($contact->name ?? ''));
        $push = trim((string) ($contact->push_name ?? ''));
        $phone = trim((string) ($contact->phone_number ?? ''));

        if ($saved !== '') {
            return $saved;
        }
        if ($push !== '') {
            return $push;
        }
        if ($phone !== '') {
            return $phone;
        }

        return 'Клиент';
    }
}
