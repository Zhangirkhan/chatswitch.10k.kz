<?php

declare(strict_types=1);

namespace App\Services\AI\Orchestrator;

use App\Models\Product;
use App\Models\Service;
use App\Services\AI\KnowledgeContextRepository;
use App\Services\AI\Locale\KazakhstanLocaleDetector;
use App\Services\AI\Locale\KazakhstanLocaleProfile;

final class OrchestratorDynamicReplyBuilder
{
    public function __construct(
        private readonly ClientMessageIntentDetector $intents,
        private readonly KazakhstanLocaleDetector $localeDetector,
        private readonly KnowledgeContextRepository $knowledge,
    ) {}

    /**
     * @return array{reply: string, reason: string, task: array{title: string, body: string}|null}|null
     */
    public function buildForMessage(string $body, int $companyId): ?array
    {
        $intent = $this->intents->detect($body);
        if ($intent === ClientMessageIntentDetector::INTENT_GENERAL
            || $intent === ClientMessageIntentDetector::INTENT_GREETING) {
            return null;
        }

        return match ($intent) {
            ClientMessageIntentDetector::INTENT_CATALOG => $this->catalogReply($body, $companyId),
            ClientMessageIntentDetector::INTENT_TIME => $this->timeReply($body),
            ClientMessageIntentDetector::INTENT_PRICE => $this->priceReply($body),
            ClientMessageIntentDetector::INTENT_ADDRESS => $this->addressReply($body),
            ClientMessageIntentDetector::INTENT_DELIVERY => $this->deliveryReply($body),
            ClientMessageIntentDetector::INTENT_PAYMENT => $this->paymentReply($body),
            ClientMessageIntentDetector::INTENT_PURCHASE => $this->purchaseReply($body),
            ClientMessageIntentDetector::INTENT_ACKNOWLEDGEMENT => $this->acknowledgementReply($body),
            default => null,
        };
    }

    public function topicShiftAcknowledgement(string $body): string
    {
        return $this->localize(
            $body,
            'Понял ваш вопрос. Сейчас отвечу по нему — если нужно, после этого вернёмся к предыдущей теме.',
            'Сұрағыңызды түсіндім. Қазір сол бойынша жауап беремін — керек болса, алдыңғы тақырыпқа кейін ораламыз.',
            'Понял ваш вопрос, қазір сол бойынша жауап беремін.',
        );
    }

    public function localize(string $body, string $ru, string $kk, ?string $mixed = null): string
    {
        $profile = $this->localeDetector->detect($body);

        return match ($profile->dominant) {
            KazakhstanLocaleProfile::DOMINANT_KK => $kk,
            KazakhstanLocaleProfile::DOMINANT_MIXED,
            KazakhstanLocaleProfile::DOMINANT_TRANSLIT_MIXED => $mixed ?? $kk,
            default => $ru,
        };
    }

    /**
     * @return array{reply: string, reason: string, task: array{title: string, body: string}|null}
     */
    private function catalogReply(string $body, int $companyId): array
    {
        $data = $this->knowledge->forPrompt($companyId);
        $products = collect($data['products']);
        $services = collect($data['services']);

        if ($products->isEmpty() && $services->isEmpty()) {
            return [
                'reply' => $this->localize(
                    $body,
                    'Сейчас в каталоге нет готового списка в системе — менеджер уточнит ассортимент и пришлёт варианты. Напишите, что вас интересует.',
                    'Қазір жүйеде дайын тізім жоқ — менеджер ассортиментті нақтылап, нұсқаларды жібереді. Не қызықтыратынын жазыңыз.',
                ),
                'reason' => 'Клиент спросил об ассортименте — AI ответил по текущему запросу.',
                'task' => null,
            ];
        }

        $parts = [];
        $catalogHeader = $this->localize($body, 'У нас в каталоге:', 'Бізде каталогта:');
        $servicesHeader = $this->localize($body, 'Услуги:', 'Қызметтер:');
        $closing = $this->localize(
            $body,
            'Что из этого вас интересует?',
            'Мынаның қайсысы сізді қызықтырады?',
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
    private function timeReply(string $body): array
    {
        return [
            'reply' => $this->localize(
                $body,
                'Уточню срок у менеджера и сообщу вам. Срок зависит от параметров заказа.',
                'Нақты мерзімді менеджерден нақтылап, сізге хабарлаймын. Жеке тапсырыс уақыты параметрлерге байланысты.',
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
    private function priceReply(string $body): array
    {
        return [
            'reply' => $this->localize(
                $body,
                'Стоимость зависит от параметров заказа. Менеджер рассчитает цену и напишет вам ориентир.',
                'Бағасы тапсырыс параметрлеріне байланысты. Менеджер есептеп, баға туралы хабарлайды.',
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
    private function addressReply(string $body): array
    {
        return [
            'reply' => $this->localize(
                $body,
                'Подскажите, пожалуйста, ваш район или адрес — так сможем точнее сориентировать по выезду и доставке.',
                'Ауданыңызды немесе мекенжайыңызды жазыңыз — жеткізу/шығу бойынша нақтырақ айта аламыз.',
            ),
            'reason' => 'Клиент спросил про адрес — AI уточнил локацию.',
            'task' => null,
        ];
    }

    /**
     * @return array{reply: string, reason: string, task: array{title: string, body: string}|null}
     */
    private function deliveryReply(string $body): array
    {
        if ($this->intents->isProvidingAddressOrDeliveryDetail($body)) {
            return [
                'reply' => $this->localize(
                    $body,
                    'Приняла адрес доставки. Передаю в логистику — заказ оформлен.',
                    'Жеткізу мекенжайын қабылдадым. Логистикаға беремін — тапсырыс рәсімделді.',
                ),
                'reason' => 'Клиент указал адрес доставки — AI зафиксировал без повторной записи.',
                'task' => null,
            ];
        }

        return [
            'reply' => $this->localize(
                $body,
                'По доставке и монтажу уточню детали у менеджера и вернусь с ответом. Если есть ограничения (лифт, парковка) — напишите.',
                'Жеткізу/орнату бойынша менеджерден нақтылап, жауабын жіберемін. Шектеулер болса (лифт, парковка) — жазыңыз.',
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
    private function paymentReply(string $body): array
    {
        return [
            'reply' => $this->localize(
                $body,
                'По оплате менеджер пришлёт реквизиты и подскажет удобный способ. Если уже оплатили — напишите, зафиксируем.',
                'Төлем бойынша менеджер реквизиттерді жібереді. Төлеп қойған болсаңыз — жазыңыз, белгілеп аламыз.',
            ),
            'reason' => 'Клиент спросил про оплату — AI ответил по текущему вопросу.',
            'task' => null,
        ];
    }

    /**
     * @return array{reply: string, reason: string, task: array{title: string, body: string}|null}
     */
    private function purchaseReply(string $body): array
    {
        return [
            'reply' => $this->localize(
                $body,
                'Понял, поможем с заказом. Подскажите, пожалуйста, что именно нужно и примерные параметры — подберём вариант.',
                'Тапсырысты түсіндім. Не керек екенін және шамамен параметрлерін жазыңыз — нұсқа ұсынамыз.',
            ),
            'reason' => 'Клиент выразил интерес к покупке — AI уточнил параметры.',
            'task' => null,
        ];
    }

    /**
     * @return array{reply: string, reason: string, task: array{title: string, body: string}|null}
     */
    private function acknowledgementReply(string $body): array
    {
        return [
            'reply' => $this->localize(
                $body,
                'Пожалуйста! Если появятся ещё вопросы — пишите.',
                'Оқылды! Тағы сұрақ болса — жазыңыз.',
            ),
            'reason' => 'Клиент поблагодарил — AI ответил коротко и по делу.',
            'task' => null,
        ];
    }
}
