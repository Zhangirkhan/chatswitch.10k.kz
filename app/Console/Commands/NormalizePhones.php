<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Contact;
use App\Models\Message;
use App\Models\User;
use App\Models\WhatsappSession;
use App\Support\PhoneFormatter;
use Illuminate\Console\Command;

final class NormalizePhones extends Command
{
    protected $signature = 'phones:normalize {--dry-run : Не применять изменения, только показать статистику}';

    protected $description = 'Приводит все номера телефонов в системе к единому формату (только цифры, например 77476644108).';

    public function handle(): int
    {
        $dry = (bool) $this->option('dry-run');

        $counts = [
            'users' => 0,
            'contacts_phone' => 0,
            'contacts_whatsapp_id' => 0,
            'sessions' => 0,
            'messages_sender_phone' => 0,
        ];

        foreach (User::whereNotNull('phone')->cursor() as $user) {
            $normalized = PhoneFormatter::normalize($user->phone);
            if ($normalized !== null && $normalized !== $user->getOriginal('phone')) {
                if (! $dry) {
                    $user->phone = $normalized;
                    $user->saveQuietly();
                }
                $counts['users']++;
            }
        }

        foreach (Contact::cursor() as $contact) {
            $phoneOrig = $contact->getOriginal('phone_number');
            $phoneNorm = PhoneFormatter::normalize($phoneOrig);
            if ($phoneNorm !== null && $phoneNorm !== $phoneOrig) {
                if (! $dry) {
                    $contact->phone_number = $phoneNorm;
                }
                $counts['contacts_phone']++;
            }

            $waOrig = $contact->getOriginal('whatsapp_id');
            if ($waOrig && ! str_contains($waOrig, '@')) {
                $waNorm = PhoneFormatter::normalize($waOrig);
                if ($waNorm !== null && $waNorm !== $waOrig) {
                    if (! $dry) {
                        $contact->whatsapp_id = $waNorm;
                    }
                    $counts['contacts_whatsapp_id']++;
                }
            }

            if (! $dry && $contact->isDirty()) {
                $contact->saveQuietly();
            }
        }

        foreach (WhatsappSession::whereNotNull('phone_number')->cursor() as $session) {
            $normalized = PhoneFormatter::normalize($session->phone_number);
            if ($normalized !== null && $normalized !== $session->getOriginal('phone_number')) {
                if (! $dry) {
                    $session->phone_number = $normalized;
                    $session->saveQuietly();
                }
                $counts['sessions']++;
            }
        }

        foreach (Message::whereNotNull('sender_phone')->cursor() as $message) {
            $normalized = PhoneFormatter::normalize($message->sender_phone);
            if ($normalized !== null && $normalized !== $message->getOriginal('sender_phone')) {
                if (! $dry) {
                    $message->sender_phone = $normalized;
                    $message->saveQuietly();
                }
                $counts['messages_sender_phone']++;
            }
        }

        $this->info($dry ? 'Dry-run завершён. Записи, которые были бы обновлены:' : 'Нормализация завершена. Обновлено записей:');
        foreach ($counts as $label => $count) {
            $this->line("  {$label}: {$count}");
        }

        return self::SUCCESS;
    }
}
