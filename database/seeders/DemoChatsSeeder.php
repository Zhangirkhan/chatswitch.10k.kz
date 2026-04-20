<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Chat;
use App\Models\Contact;
use App\Models\Message;
use App\Models\WhatsappSession;
use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

final class DemoChatsSeeder extends Seeder
{
    public function run(): void
    {
        DB::transaction(function (): void {
            $sessions = $this->createSessions();
            $contacts = $this->createContacts();
            $this->createChatsWithMessages($sessions, $contacts);
        });
    }

    /**
     * @return array<int, WhatsappSession>
     */
    private function createSessions(): array
    {
        $defs = [
            [
                'session_name' => 'demo-main',
                'display_name' => 'Основной номер',
                'phone_number' => '+77001112233',
                'status' => 'connected',
            ],
            [
                'session_name' => 'demo-sales',
                'display_name' => 'Отдел продаж',
                'phone_number' => '+77004445566',
                'status' => 'connected',
            ],
            [
                'session_name' => 'demo-support',
                'display_name' => 'Поддержка',
                'phone_number' => '+77007778899',
                'status' => 'qr_pending',
            ],
        ];

        $sessions = [];
        foreach ($defs as $def) {
            $sessions[] = WhatsappSession::updateOrCreate(
                ['session_name' => $def['session_name']],
                [
                    'display_name' => $def['display_name'],
                    'phone_number' => $def['phone_number'],
                    'status' => $def['status'],
                    'is_active' => true,
                    'connected_at' => $def['status'] === 'connected' ? now() : null,
                ],
            );
        }

        return $sessions;
    }

    /**
     * @return array<int, Contact>
     */
    private function createContacts(): array
    {
        $defs = [
            ['name' => 'Айгуль Нурланова', 'phone' => '+77011234501'],
            ['name' => 'Данияр Сериков', 'phone' => '+77011234502'],
            ['name' => 'Асель Кайратова', 'phone' => '+77011234503'],
            ['name' => 'Тимур Абдулов', 'phone' => '+77011234504'],
            ['name' => 'Мадина Жумабаева', 'phone' => '+77011234505'],
            ['name' => 'Руслан Токтаров', 'phone' => '+77011234506'],
            ['name' => 'Камила Ержанова', 'phone' => '+77011234507'],
            ['name' => 'Нурсултан Омаров', 'phone' => '+77011234508'],
            ['name' => 'Жанар Ахметова', 'phone' => '+77011234509'],
            ['name' => 'Бауыржан Касымов', 'phone' => '+77011234510'],
        ];

        $contacts = [];
        foreach ($defs as $def) {
            $digits = preg_replace('/\D/', '', $def['phone']);
            $contacts[] = Contact::updateOrCreate(
                ['whatsapp_id' => $digits.'@c.us'],
                [
                    'phone_number' => $def['phone'],
                    'name' => $def['name'],
                    'push_name' => $def['name'],
                    'is_business' => false,
                ],
            );
        }

        return $contacts;
    }

    /**
     * @param  array<int, WhatsappSession>  $sessions
     * @param  array<int, Contact>  $contacts
     */
    private function createChatsWithMessages(array $sessions, array $contacts): void
    {
        $scenarios = [
            [
                'contact_idx' => 0, 'session_idx' => 0, 'unread' => 2, 'is_pinned' => true,
                'messages' => [
                    ['in', 'Добрый день! Хотела уточнить по заказу №10245.'],
                    ['out', 'Здравствуйте, Айгуль! Заказ уже собран, отправим сегодня.'],
                    ['in', 'Отлично, спасибо! Когда ждать курьера?'],
                    ['out', 'Курьер будет у вас между 14:00 и 17:00.'],
                    ['in', 'Поняла, буду на месте.'],
                    ['in', 'И ещё: можно ли добавить подарочную упаковку?'],
                ],
            ],
            [
                'contact_idx' => 1, 'session_idx' => 1, 'unread' => 0, 'is_pinned' => true,
                'messages' => [
                    ['out', 'Данияр, добрый день! Готовы обсудить условия договора?'],
                    ['in', 'Да, конечно. Когда удобно созвониться?'],
                    ['out', 'Давайте в 16:00 по Астане.'],
                    ['in', '👍 Договорились.'],
                ],
            ],
            [
                'contact_idx' => 2, 'session_idx' => 0, 'unread' => 5, 'is_pinned' => false,
                'messages' => [
                    ['in', 'Здравствуйте'],
                    ['in', 'У меня вопрос по счёту-фактуре'],
                    ['in', 'Номер счёта 4521'],
                    ['in', 'Сумма не совпадает с договором'],
                    ['in', 'Можете проверить?'],
                ],
            ],
            [
                'contact_idx' => 3, 'session_idx' => 1, 'unread' => 0, 'is_pinned' => false,
                'messages' => [
                    ['in', 'Привет! Акция ещё действует?'],
                    ['out', 'Здравствуйте, Тимур! Да, до конца месяца скидка 15%.'],
                    ['in', 'Супер, оформляю заказ.'],
                    ['out', 'Отличный выбор 😊'],
                ],
            ],
            [
                'contact_idx' => 4, 'session_idx' => 0, 'unread' => 0, 'is_pinned' => false,
                'messages' => [
                    ['out', 'Мадина, отправили вам каталог на почту.'],
                    ['in', 'Получила, спасибо. Изучу и отвечу.'],
                ],
            ],
            [
                'contact_idx' => 5, 'session_idx' => 2, 'unread' => 1, 'is_pinned' => false,
                'messages' => [
                    ['in', 'Не приходит код подтверждения.'],
                    ['out', 'Попробуйте запросить повторно через 2 минуты.'],
                    ['in', 'Всё равно не приходит.'],
                ],
            ],
            [
                'contact_idx' => 6, 'session_idx' => 1, 'unread' => 0, 'is_pinned' => false,
                'messages' => [
                    ['in', 'Добрый вечер, интересует опт.'],
                    ['out', 'Камила, добрый вечер! Пришлю прайс утром.'],
                ],
            ],
            [
                'contact_idx' => 7, 'session_idx' => 0, 'unread' => 0, 'is_pinned' => false,
                'messages' => [
                    ['out', 'Нурсултан, напоминаю про оплату счёта.'],
                    ['in', 'Оплатил вчера, чек выслал на почту.'],
                    ['out', 'Спасибо, подтверждаю получение.'],
                ],
            ],
            [
                'contact_idx' => 8, 'session_idx' => 2, 'unread' => 3, 'is_pinned' => false,
                'messages' => [
                    ['in', 'Здравствуйте, техподдержка?'],
                    ['in', 'Сайт не открывается уже час.'],
                    ['in', 'Это срочно'],
                ],
            ],
            [
                'contact_idx' => 9, 'session_idx' => 1, 'unread' => 0, 'is_pinned' => false,
                'messages' => [
                    ['in', 'Здравствуйте! Где можно забрать заказ?'],
                    ['out', 'Пункт выдачи — ул. Абая 52, с 10:00 до 20:00.'],
                    ['in', 'Спасибо, подъеду завтра.'],
                ],
            ],
        ];

        $baseTime = Carbon::now()->subDays(2);

        foreach ($scenarios as $sIndex => $scenario) {
            $contact = $contacts[$scenario['contact_idx']];
            $session = $sessions[$scenario['session_idx']];

            $chat = Chat::updateOrCreate(
                [
                    'whatsapp_chat_id' => $contact->whatsapp_id,
                    'whatsapp_session_id' => $session->id,
                ],
                [
                    'contact_id' => $contact->id,
                    'chat_name' => $contact->name,
                    'is_group' => false,
                    'unread_count' => $scenario['unread'],
                    'is_pinned' => $scenario['is_pinned'],
                    'is_archived' => false,
                ],
            );

            $chat->messages()->delete();

            $cursor = $baseTime->copy()->addMinutes($sIndex * 90);
            $lastText = '';
            $lastAt = $cursor->copy();

            foreach ($scenario['messages'] as $i => [$direction, $body]) {
                $cursor = $cursor->copy()->addMinutes(random_int(2, 25));
                $isInbound = $direction === 'in';

                Message::create([
                    'chat_id' => $chat->id,
                    'whatsapp_session_id' => $session->id,
                    'whatsapp_message_id' => 'demo_'.$chat->id.'_'.$i,
                    'direction' => $isInbound ? 'inbound' : 'outbound',
                    'type' => 'chat',
                    'body' => $body,
                    'sender_phone' => $isInbound ? $contact->phone_number : $session->phone_number,
                    'sender_name' => $isInbound ? $contact->name : ($session->display_name ?? 'Agent'),
                    'sent_by_user_id' => null,
                    'is_forwarded' => false,
                    'ack' => $isInbound ? 'delivered' : 'read',
                    'message_timestamp' => $cursor,
                    'created_at' => $cursor,
                    'updated_at' => $cursor,
                ]);

                $lastText = $body;
                $lastAt = $cursor->copy();
            }

            $chat->update([
                'last_message_text' => $lastText,
                'last_message_at' => $lastAt,
            ]);
        }
    }
}
