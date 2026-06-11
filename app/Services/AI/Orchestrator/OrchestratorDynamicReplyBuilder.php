<?php

declare(strict_types=1);

namespace App\Services\AI\Orchestrator;

use App\Models\Chat;
use App\Models\Message;
use App\Models\Product;
use App\Models\Service;
use App\Services\AI\ChatSalesStateService;
use App\Services\AI\KnowledgeContextRepository;
use App\Services\AI\Locale\ChatInboundLocaleResolver;
use App\Services\AI\Locale\KazakhstanLocaleDetector;
use App\Services\AI\Locale\KazakhstanLocaleProfile;

final class OrchestratorDynamicReplyBuilder
{
    public function __construct(
        private readonly ClientMessageIntentDetector $intents,
        private readonly KazakhstanLocaleDetector $localeDetector,
        private readonly ChatInboundLocaleResolver $chatLocaleResolver,
        private readonly KnowledgeContextRepository $knowledge,
        private readonly ChatSalesStateService $salesStateService,
    ) {}

    /**
     * @return array{reply: string, reason: string, task: array{title: string, body: string}|null}|null
     */
    public function buildForMessage(string $body, int $companyId, ?Chat $chat = null, ?Message $trigger = null): ?array
    {
        $intent = $this->intents->detect($body);
        if ($intent === ClientMessageIntentDetector::INTENT_GENERAL
            || $intent === ClientMessageIntentDetector::INTENT_GREETING) {
            return null;
        }

        return match ($intent) {
            ClientMessageIntentDetector::INTENT_CATALOG => $this->catalogReply($body, $companyId, $chat, $trigger),
            ClientMessageIntentDetector::INTENT_TIME => $this->timeReply($body, $chat, $trigger),
            ClientMessageIntentDetector::INTENT_PRICE => $this->priceReply($body, $chat, $trigger),
            ClientMessageIntentDetector::INTENT_ADDRESS => $this->addressReply($body, $chat, $trigger),
            ClientMessageIntentDetector::INTENT_DELIVERY => $this->deliveryReply($body, $chat, $trigger),
            ClientMessageIntentDetector::INTENT_PAYMENT => $this->paymentReply($body, $chat, $trigger),
            ClientMessageIntentDetector::INTENT_PURCHASE => $this->purchaseReply($body, $chat, $trigger),
            ClientMessageIntentDetector::INTENT_ACKNOWLEDGEMENT => $this->acknowledgementReply($body, $chat, $trigger),
            ClientMessageIntentDetector::INTENT_DEFERRAL => $this->deferralReply($body, $chat, $trigger),
            ClientMessageIntentDetector::INTENT_ORDER_COMPLETION => $this->orderCompletionReply($body, $chat, $trigger),
            default => null,
        };
    }

    public function topicShiftAcknowledgement(string $body, ?Chat $chat = null, ?Message $trigger = null): string
    {
        return $this->pickLocalized(
            $body,
            'Понял ваш вопрос. Сейчас отвечу по нему — если нужно, после этого вернёмся к предыдущей теме.',
            'Сұрағыңызды түсіндім. Қазір сол бойынша жауап беремін — керек болса, алдыңғы тақырыпқа кейін ораламыз.',
            'Понял ваш вопрос, қазір сол бойынша жауап беремін.',
            $chat,
            $trigger,
        );
    }

    public function localize(string $body, string $ru, string $kk, ?string $mixed = null): string
    {
        $profile = $this->localeDetector->detect($body);

        return $this->localizeForProfile($profile, $ru, $kk, $mixed);
    }

    public function localizeForChat(Chat $chat, Message $trigger, string $ru, string $kk, ?string $mixed = null): string
    {
        $profile = $this->chatLocaleResolver->resolve($chat, $trigger);

        return $this->localizeForProfile($profile, $ru, $kk, $mixed);
    }

    private function pickLocalized(
        string $body,
        string $ru,
        string $kk,
        ?string $mixed,
        ?Chat $chat,
        ?Message $trigger,
    ): string {
        if ($chat !== null && $trigger !== null) {
            return $this->localizeForChat($chat, $trigger, $ru, $kk, $mixed);
        }

        return $this->localize($body, $ru, $kk, $mixed);
    }

    private function localizeForProfile(KazakhstanLocaleProfile $profile, string $ru, string $kk, ?string $mixed): string
    {
        return match ($profile->dominant) {
            KazakhstanLocaleProfile::DOMINANT_KK => $kk,
            KazakhstanLocaleProfile::DOMINANT_MIXED,
            KazakhstanLocaleProfile::DOMINANT_TRANSLIT_MIXED => $mixed ?? $kk,
            default => $profile->kkPct > $profile->ruPct ? ($mixed ?? $kk) : $ru,
        };
    }

    /**
     * @return array{reply: string, reason: string, task: array{title: string, body: string}|null}
     */
    private function catalogReply(string $body, int $companyId, ?Chat $chat, ?Message $trigger): array
    {
        $data = $this->knowledge->forPrompt($companyId);
        $products = collect($data['products']);
        $services = collect($data['services']);

        if ($products->isEmpty() && $services->isEmpty()) {
            return [
                'reply' => $this->pickLocalized(
                    $body,
                    'Сейчас в каталоге нет готового списка в системе — менеджер уточнит ассортимент и пришлёт варианты. Напишите, что вас интересует.',
                    'Қазір жүйеде дайын тізім жоқ — менеджер ассортиментті нақтылап, нұсқаларды жібереді. Не қызықтыратынын жазыңыз.',
                    null,
                    $chat,
                    $trigger,
                ),
                'reason' => 'Клиент спросил об ассортименте — AI ответил по текущему запросу.',
                'task' => null,
            ];
        }

        $parts = [];
        $catalogHeader = $this->pickLocalized($body, 'У нас в каталоге:', 'Бізде каталогта:', null, $chat, $trigger);
        $servicesHeader = $this->pickLocalized($body, 'Услуги:', 'Қызметтер:', null, $chat, $trigger);
        $closing = $this->pickLocalized(
            $body,
            'Что из этого вас интересует?',
            'Мынаның қайсысы сізді қызықтырады?',
            null,
            $chat,
            $trigger,
        );

        if ($products->isNotEmpty()) {
            $lines = $products
                ->take(8)
                ->map(function (Product $product): string {
                    $line = '• '.$product->name;
                    if ($product->price !== null) {
                        $line .= ' — '.number_format((float) $product->price, 0, '.', ' ').' ₸';
                    }

                    return $line;
                })
                ->implode("\n");
            $parts[] = "{$catalogHeader}\n{$lines}";
        }

        if ($services->isNotEmpty()) {
            $lines = $services
                ->take(5)
                ->map(function (Service $service): string {
                    $line = '• '.$service->name;
                    if ($service->price !== null) {
                        $line .= ' — '.number_format((float) $service->price, 0, '.', ' ').' ₸';
                    }

                    return $line;
                })
                ->implode("\n");
            $parts[] = "{$servicesHeader}\n{$lines}";
        }

        return [
            'reply' => implode("\n\n", $parts)."\n\n{$closing}",
            'reason' => 'Клиент спросил об ассортименте — AI перечислил позиции из базы знаний.',
            'task' => null,
        ];
    }

    /**
     * @return array{reply: string, reason: string, task: array{title: string, body: string}}
     */
    private function timeReply(string $body, ?Chat $chat, ?Message $trigger): array
    {
        return [
            'reply' => $this->pickLocalized(
                $body,
                'Уточню срок у менеджера и сообщу вам. Срок зависит от параметров заказа.',
                'Нақты мерзімді менеджерден нақтылап, сізге хабарлаймын. Жеке тапсырыс уақыты параметрлерге байланысты.',
                null,
                $chat,
                $trigger,
            ),
            'reason' => 'Клиент спросил о сроках — AI ответил по текущему вопросу.',
            'task' => [
                'title' => 'Уточнить срок готовности заказа',
                'body' => 'Клиент спрашивает о сроках. Проверьте статус и сообщите ориентир по времени.',
            ],
        ];
    }

    /**
     * @return array{reply: string, reason: string, task: array{title: string, body: string}}
     */
    private function priceReply(string $body, ?Chat $chat, ?Message $trigger): array
    {
        return [
            'reply' => $this->pickLocalized(
                $body,
                'Стоимость зависит от параметров заказа. Менеджер рассчитает цену и напишет вам ориентир.',
                'Бағасы тапсырыс параметрлеріне байланысты. Менеджер есептеп, баға туралы хабарлайды.',
                null,
                $chat,
                $trigger,
            ),
            'reason' => 'Клиент спросил о цене — AI ответил по текущему вопросу.',
            'task' => [
                'title' => 'Рассчитать стоимость для клиента',
                'body' => 'Клиент спрашивает цену. Подготовьте расчёт и отправьте ориентир.',
            ],
        ];
    }

    /**
     * @return array{reply: string, reason: string, task: array{title: string, body: string}|null}
     */
    private function addressReply(string $body, ?Chat $chat, ?Message $trigger): array
    {
        return [
            'reply' => $this->pickLocalized(
                $body,
                'Подскажите, пожалуйста, ваш район или адрес — так сможем точнее сориентировать по выезду и доставке.',
                'Ауданыңызды немесе мекенжайыңызды жазыңыз — жеткізу/шығу бойынша нақтырақ айта аламыз.',
                null,
                $chat,
                $trigger,
            ),
            'reason' => 'Клиент спросил про адрес — AI уточнил локацию.',
            'task' => null,
        ];
    }

    /**
     * @return array{reply: string, reason: string, task: array{title: string, body: string}|null}
     */
    private function deliveryReply(string $body, ?Chat $chat, ?Message $trigger): array
    {
        if ($this->intents->isProvidingAddressOrDeliveryDetail($body)) {
            return [
                'reply' => $this->pickLocalized(
                    $body,
                    'Приняла адрес доставки. Передаю в логистику — заказ оформлен.',
                    'Жеткізу мекенжайын қабылдадым. Логистикаға беремін — тапсырыс рәсімделді.',
                    null,
                    $chat,
                    $trigger,
                ),
                'reason' => 'Клиент указал адрес доставки — AI зафиксировал без повторной записи.',
                'task' => null,
            ];
        }

        return [
            'reply' => $this->pickLocalized(
                $body,
                'По доставке и монтажу уточню детали у менеджера и вернусь с ответом. Если есть ограничения (лифт, парковка) — напишите.',
                'Жеткізу/орнату бойынша менеджерден нақтылап, жауабын жіберемін. Шектеулер болса (лифт, парковка) — жазыңыз.',
                null,
                $chat,
                $trigger,
            ),
            'reason' => 'Клиент спросил про доставку — AI ответил по текущему вопросу.',
            'task' => [
                'title' => 'Уточнить условия доставки/монтажа',
                'body' => 'Клиент спрашивает про доставку или монтаж. Проверьте условия и ответьте.',
            ],
        ];
    }

    /**
     * @return array{reply: string, reason: string, task: array{title: string, body: string}|null}
     */
    private function paymentReply(string $body, ?Chat $chat, ?Message $trigger): array
    {
        return [
            'reply' => $this->pickLocalized(
                $body,
                'По оплате менеджер пришлёт реквизиты и подскажет удобный способ. Если уже оплатили — напишите, зафиксируем.',
                'Төлем бойынша менеджер реквизиттерді жібереді. Төлеп қойған болсаңыз — жазыңыз, белгілеп аламыз.',
                null,
                $chat,
                $trigger,
            ),
            'reason' => 'Клиент спросил про оплату — AI ответил по текущему вопросу.',
            'task' => null,
        ];
    }

    /**
     * @return array{reply: string, reason: string, task: array{title: string, body: string}|null}
     */
    private function purchaseReply(string $body, ?Chat $chat, ?Message $trigger): array
    {
        return [
            'reply' => $this->pickLocalized(
                $body,
                'Понял, поможем с заказом. Подскажите, пожалуйста, что именно нужно и примерные параметры — подберём вариант.',
                'Тапсырысты түсіндім. Не керек екенін және шамамен параметрлерін жазыңыз — нұсқа ұсынамыз.',
                null,
                $chat,
                $trigger,
            ),
            'reason' => 'Клиент выразил интерес к покупке — AI уточнил параметры.',
            'task' => null,
        ];
    }

    /**
     * @return array{reply: string, reason: string, task: array{title: string, body: string}|null}
     */
    private function acknowledgementReply(string $body, ?Chat $chat, ?Message $trigger): array
    {
        // If we have a sales state with a meaningful next step, append a proactive CTA
        // instead of ending the conversation with a dead-end polite close.
        $cta = $chat !== null ? $this->buildSalesCta($body, $chat, $trigger) : '';

        $baseReply = $this->pickLocalized(
            $body,
            'Пожалуйста!',
            'Оқылды!',
            null,
            $chat,
            $trigger,
        );

        $reply = $cta !== ''
            ? $baseReply.' '.$cta
            : $this->pickLocalized(
                $body,
                'Пожалуйста! Если появятся ещё вопросы — пишите.',
                'Оқылды! Тағы сұрақ болса — жазыңыз.',
                null,
                $chat,
                $trigger,
            );

        return [
            'reply' => $reply,
            'reason' => $cta !== ''
                ? 'Клиент поблагодарил — AI ответил и предложил следующий шаг по статусу продажи.'
                : 'Клиент поблагодарил — AI ответил коротко и по делу.',
            'task' => null,
        ];
    }

    /**
     * @return array{reply: string, reason: string, task: array{title: string, body: string}|null}
     */
    private function orderCompletionReply(string $body, ?Chat $chat, ?Message $trigger): array
    {
        return [
            'reply' => $this->pickLocalized(
                $body,
                'Спасибо за обратную связь! Рады, что всё понравилось. Если понадобится что-то ещё — пишите.',
                'Пікіріңіз үшін рахмет! Бәрі ұнағанына қуаныштымыз. Тағы керек болса — жазыңыз.',
                null,
                $chat,
                $trigger,
            ),
            'reason' => 'Клиент подтвердил получение заказа и оплату — AI поблагодарил без повторного запроса адреса.',
            'task' => null,
        ];
    }

    /**
     * @return array{reply: string, reason: string, task: array{title: string, body: string}|null}
     */
    private function deferralReply(string $body, ?Chat $chat, ?Message $trigger): array
    {
        // Acknowledge the deferral, anchor value, and propose a concrete follow-up.
        $reply = $this->pickLocalized(
            $body,
            'Понял, не тороплю. Когда будете готовы — напишите, подберём лучший вариант.',
            'Жарайды, асықпайды. Дайын болғанда жазыңыз — ең жақсы нұсқаны ұсынамыз.',
            null,
            $chat,
            $trigger,
        );

        return [
            'reply' => $reply,
            'reason' => 'Клиент отложил решение — AI подтвердил и оставил «дверь открытой».',
            'task' => null,
        ];
    }

    /**
     * Build a proactive sales CTA based on the current sales_state.next_action.
     * Returns empty string when no meaningful CTA can be derived.
     */
    private function buildSalesCta(string $body, Chat $chat, ?Message $trigger): string
    {
        $state = $chat->sales_state;
        if (! is_array($state) || $state === []) {
            return '';
        }

        $nextAction = $state['next_action'] ?? null;
        $missingFields = $state['missing_fields'] ?? [];

        $isRu = $trigger === null || $this->chatLocaleResolver->resolve($chat, $trigger)->dominant !== KazakhstanLocaleProfile::DOMINANT_KK;

        return match ($nextAction) {
            ChatSalesStateService::NA_ASK_BUDGET => $isRu
                ? 'Кстати, какой бюджет вы рассматриваете?'
                : 'Айтпақшы, қандай бюджетті қарастырасыз?',
            ChatSalesStateService::NA_ASK_REQUIREMENTS => $isRu
                ? 'Расскажите подробнее, что именно вас интересует?'
                : 'Не қызықтыратынын толығырақ айтыңызшы?',
            ChatSalesStateService::NA_QUALIFY => $isRu
                ? (count($missingFields) > 0
                    ? 'Кстати, ещё не уточнили: '.implode(' и ', $missingFields).'. Подскажете?'
                    : 'Что-то ещё хотели уточнить?')
                : 'Тағы бір сұрақ: '.implode(' және ', $missingFields ?: ['мәліметтер']).' туралы айтыңызшы?',
            ChatSalesStateService::NA_PRESENT_OFFER => $isRu
                ? 'Хотите, подберу подходящий вариант и пришлю предложение?'
                : 'Қалауыңызша нұсқа ұсынайын — жіберейін бе?',
            ChatSalesStateService::NA_HANDLE_OBJECTION => '',  // Don't force in acknowledgement context
            ChatSalesStateService::NA_BOOK_APPOINTMENT => $isRu
                ? 'Когда вам удобно записаться?'
                : 'Қашан жазылу ыңғайлы болады?',
            ChatSalesStateService::NA_CONFIRM_DEAL => $isRu
                ? 'Готовы перейти к оформлению?'
                : 'Рәсімдеуге дайынсыз ба?',
            ChatSalesStateService::NA_FOLLOW_UP => '',  // Handled separately by follow-up jobs
            default => '',
        };
    }
}
