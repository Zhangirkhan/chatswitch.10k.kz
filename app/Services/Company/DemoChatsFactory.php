<?php

declare(strict_types=1);

namespace App\Services\Company;

use App\Models\Chat;
use App\Models\Company;
use App\Models\Contact;
use App\Models\Funnel;
use App\Models\FunnelStage;
use App\Models\Message;
use App\Models\WhatsappSession;
use Carbon\Carbon;

final class DemoChatsFactory
{
    /**
     * @return array{chats: int, messages: int}
     */
    public function seedForCompany(Company $company): array
    {
        $sessions = WhatsappSession::query()
            ->withoutGlobalScope('tenant')
            ->where('company_id', $company->id)
            ->orderBy('id')
            ->get()
            ->all();

        if ($sessions === []) {
            return ['chats' => 0, 'messages' => 0];
        }

        $contacts = $this->createContacts($company);
        $funnel = Funnel::query()
            ->withoutGlobalScope('tenant')
            ->where('company_id', $company->id)
            ->where('name', 'Универсальная продажа')
            ->first();

        $stagesByName = $funnel instanceof Funnel
            ? FunnelStage::query()
                ->where('funnel_id', $funnel->id)
                ->pluck('id', 'name')
            : collect();

        $messageCount = 0;
        $chatCount = 0;

        foreach ($this->scenarios() as $sIndex => $scenario) {
            $contact = $contacts[$scenario['contact_idx']];
            $session = $sessions[$scenario['session_idx'] % count($sessions)];

            $stageName = $scenario['funnel_stage'] ?? null;
            $stageId = $stageName !== null ? $stagesByName->get($stageName) : null;

            $chat = Chat::query()->withoutGlobalScope('tenant')->updateOrCreate(
                [
                    'whatsapp_chat_id' => $contact->whatsapp_id,
                    'whatsapp_session_id' => $session->id,
                ],
                [
                    'company_id' => $company->id,
                    'contact_id' => $contact->id,
                    'chat_name' => $contact->name,
                    'is_group' => false,
                    'unread_count' => $scenario['unread'],
                    'is_pinned' => $scenario['is_pinned'],
                    'is_archived' => false,
                    'ai_enabled' => true,
                    'ai_mode' => 'auto',
                    'funnel_tracking_enabled' => true,
                    'funnel_id' => $funnel?->id,
                    'funnel_stage_id' => $stageId,
                ],
            );

            $chat->messages()->delete();
            $chatCount++;

            $baseTime = Carbon::now()->subDays(2)->addMinutes($sIndex * 90);
            $cursor = $baseTime->copy();
            $lastText = '';
            $lastAt = $cursor->copy();

            foreach ($scenario['messages'] as $i => [$direction, $body]) {
                $cursor = $cursor->copy()->addMinutes(random_int(2, 25));
                $isInbound = $direction === 'in';

                Message::query()->create([
                    'chat_id' => $chat->id,
                    'whatsapp_session_id' => $session->id,
                    'whatsapp_message_id' => 'demo_'.$company->id.'_'.$chat->id.'_'.$i,
                    'direction' => $isInbound ? 'inbound' : 'outbound',
                    'type' => 'chat',
                    'body' => $body,
                    'sender_phone' => $isInbound ? $contact->phone_number : ($session->phone_number ?? '+77000000000'),
                    'sender_name' => $isInbound ? $contact->name : ($session->display_name ?? 'Менеджер'),
                    'sent_by_user_id' => null,
                    'is_forwarded' => false,
                    'ack' => $isInbound ? 'delivered' : 'read',
                    'message_timestamp' => $cursor,
                    'created_at' => $cursor,
                    'updated_at' => $cursor,
                ]);

                $messageCount++;
                $lastText = $body;
                $lastAt = $cursor->copy();
            }

            $chat->update([
                'last_message_text' => $lastText,
                'last_message_at' => $lastAt,
                'last_message_direction' => str_ends_with((string) ($scenario['messages'][array_key_last($scenario['messages'])][0] ?? ''), 'in')
                    ? 'inbound'
                    : 'outbound',
            ]);
        }

        return ['chats' => $chatCount, 'messages' => $messageCount];
    }

    /**
     * @return array<int, Contact>
     */
    private function createContacts(Company $company): array
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
            $contacts[] = Contact::query()->withoutGlobalScope('tenant')->updateOrCreate(
                [
                    'company_id' => $company->id,
                    'whatsapp_id' => $digits.'@c.us',
                ],
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
     * @return list<array{
     *     contact_idx: int,
     *     session_idx: int,
     *     unread: int,
     *     is_pinned: bool,
     *     funnel_stage?: string,
     *     messages: list<array{0: string, 1: string}>
     * }>
     */
    private function scenarios(): array
    {
        return [
            [
                'contact_idx' => 0, 'session_idx' => 0, 'unread' => 2, 'is_pinned' => true,
                'funnel_stage' => 'Коммерческое предложение отправлено',
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
                'funnel_stage' => 'Согласование условий',
                'messages' => [
                    ['out', 'Данияр, добрый день! Готовы обсудить условия договора?'],
                    ['in', 'Да, конечно. Когда удобно созвониться?'],
                    ['out', 'Давайте в 16:00 по Астане.'],
                    ['in', '👍 Договорились.'],
                ],
            ],
            [
                'contact_idx' => 2, 'session_idx' => 0, 'unread' => 5, 'is_pinned' => false,
                'funnel_stage' => 'Новый интерес',
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
                'funnel_stage' => 'Квалификация',
                'messages' => [
                    ['in', 'Привет! Акция ещё действует?'],
                    ['out', 'Здравствуйте, Тимур! Да, до конца месяца скидка 15%.'],
                    ['in', 'Супер, оформляю заказ.'],
                    ['out', 'Отличный выбор 😊'],
                ],
            ],
            [
                'contact_idx' => 4, 'session_idx' => 0, 'unread' => 0, 'is_pinned' => false,
                'funnel_stage' => 'Расчёт/предложение',
                'messages' => [
                    ['out', 'Мадина, отправили вам каталог на почту.'],
                    ['in', 'Получила, спасибо. Изучу и отвечу.'],
                ],
            ],
            [
                'contact_idx' => 5, 'session_idx' => 2, 'unread' => 1, 'is_pinned' => false,
                'funnel_stage' => 'Ожидание предоплаты',
                'messages' => [
                    ['in', 'Не приходит код подтверждения.'],
                    ['out', 'Попробуйте запросить повторно через 2 минуты.'],
                    ['in', 'Всё равно не приходит.'],
                ],
            ],
            [
                'contact_idx' => 6, 'session_idx' => 1, 'unread' => 0, 'is_pinned' => false,
                'funnel_stage' => 'Предоплата получена',
                'messages' => [
                    ['in', 'Добрый вечер, интересует опт.'],
                    ['out', 'Камила, добрый вечер! Пришлю прайс утром.'],
                ],
            ],
            [
                'contact_idx' => 7, 'session_idx' => 0, 'unread' => 0, 'is_pinned' => false,
                'funnel_stage' => 'В работе',
                'messages' => [
                    ['out', 'Нурсултан, напоминаю про оплату счёта.'],
                    ['in', 'Оплатил вчера, чек выслал на почту.'],
                    ['out', 'Спасибо, подтверждаю получение.'],
                ],
            ],
            [
                'contact_idx' => 8, 'session_idx' => 2, 'unread' => 3, 'is_pinned' => false,
                'funnel_stage' => 'Нет ответа',
                'messages' => [
                    ['in', 'Здравствуйте, техподдержка?'],
                    ['in', 'Сайт не открывается уже час.'],
                    ['in', 'Это срочно'],
                ],
            ],
            [
                'contact_idx' => 9, 'session_idx' => 1, 'unread' => 0, 'is_pinned' => false,
                'funnel_stage' => 'Закрыто успешно',
                'messages' => [
                    ['in', 'Здравствуйте! Где можно забрать заказ?'],
                    ['out', 'Пункт выдачи — ул. Абая 52, с 10:00 до 20:00.'],
                    ['in', 'Спасибо, подъеду завтра.'],
                ],
            ],
        ];
    }
}
