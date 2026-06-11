<?php

declare(strict_types=1);

namespace App\Services\AI\Orchestrator;

use App\Support\KazakhstanCityHeuristics;

final class ClientMessageIntentDetector
{
    public const INTENT_CATALOG = 'catalog';

    public const INTENT_TIME = 'time';

    public const INTENT_PRICE = 'price';

    public const INTENT_ADDRESS = 'address';

    public const INTENT_DELIVERY = 'delivery';

    public const INTENT_PAYMENT = 'payment';

    public const INTENT_GREETING = 'greeting';

    public const INTENT_PURCHASE = 'purchase';

    public const INTENT_ACKNOWLEDGEMENT = 'acknowledgement';

    /** Client is deferring: «подумаем», «позже», «не сейчас» etc. */
    public const INTENT_DEFERRAL = 'deferral';

    /** Client confirms payment, delivery received, or positive post-order feedback. */
    public const INTENT_ORDER_COMPLETION = 'order_completion';

    public const INTENT_GENERAL = 'general';

    /**
     * @return self::INTENT_*
     */
    public function detect(string $body): string
    {
        $body = mb_strtolower(trim($body));
        if ($body === '') {
            return self::INTENT_GENERAL;
        }

        if ($this->isOrderCompletionFeedback($body)) {
            return self::INTENT_ORDER_COMPLETION;
        }

        if ($this->isProvidingAddressOrDeliveryDetail($body)) {
            return self::INTENT_DELIVERY;
        }

        if ($this->isCatalogInquiry($body)) {
            return self::INTENT_CATALOG;
        }

        if ($this->isTimeQuestion($body)) {
            return self::INTENT_TIME;
        }

        if ($this->isPriceQuestion($body)) {
            return self::INTENT_PRICE;
        }

        if ($this->isAddressQuestion($body)) {
            return self::INTENT_ADDRESS;
        }

        if ($this->isDeliveryQuestion($body)) {
            return self::INTENT_DELIVERY;
        }

        if ($this->isPaymentQuestion($body)) {
            return self::INTENT_PAYMENT;
        }

        if ($this->isPurchaseIntent($body)) {
            return self::INTENT_PURCHASE;
        }

        if ($this->isDeferral($body)) {
            return self::INTENT_DEFERRAL;
        }

        if ($this->isAcknowledgement($body)) {
            return self::INTENT_ACKNOWLEDGEMENT;
        }

        if ($this->isGreeting($body)) {
            return self::INTENT_GREETING;
        }

        return self::INTENT_GENERAL;
    }

    public function isSpecific(string $body): bool
    {
        $intent = $this->detect($body);

        return in_array($intent, [
            self::INTENT_CATALOG,
            self::INTENT_TIME,
            self::INTENT_PRICE,
            self::INTENT_ADDRESS,
            self::INTENT_DELIVERY,
            self::INTENT_PAYMENT,
            self::INTENT_PURCHASE,
        ], true);
    }

    public function isTopicShift(string $previousBody, string $currentBody): bool
    {
        $previousIntent = $this->detect($previousBody);
        $currentIntent = $this->detect($currentBody);

        if ($previousIntent === $currentIntent) {
            return false;
        }

        if (! $this->isSpecific($currentBody)) {
            return false;
        }

        if ($currentIntent === self::INTENT_CATALOG) {
            return false;
        }

        return in_array($previousIntent, [self::INTENT_CATALOG, self::INTENT_PURCHASE, self::INTENT_GREETING], true)
            || $previousIntent !== $currentIntent;
    }

    public function isVagueFollowUp(string $body): bool
    {
        if ($this->isAcknowledgement($body)) {
            return true;
        }

        $body = mb_strtolower(trim($body));
        if ($body === '') {
            return true;
        }

        if ($this->isSpecific($body)) {
            return false;
        }

        if (mb_strlen($body) <= 16) {
            return preg_match('/^(?:да|нет|ок|ok|хорошо|понятно|ясно|спасибо|thanks|иә|иа|жарайды|рахмет|oke|okay)$/u', $body) === 1;
        }

        return false;
    }

    /**
     * Post-order feedback: payment confirmed, courier arrived, satisfaction thanks.
     * Must not be treated as a new delivery address.
     */
    public function isOrderCompletionFeedback(string $body): bool
    {
        $body = mb_strtolower(trim($body));
        if ($body === '') {
            return false;
        }

        if (preg_match('/(?:оплатил|оплатила|оплачено|наличн|перев[её]л|т[өо]лед|толед)/u', $body) === 1) {
            return true;
        }

        if (preg_match('/(?:курьер|курьера).{0,40}(?:приехал|прив[её]з|доставил|быстро|уже)/u', $body) === 1) {
            return true;
        }

        if (preg_match('/(?:приехал|прив[её]з|доставил).{0,40}(?:курьер|курьера)/u', $body) === 1) {
            return true;
        }

        if (preg_match('/(?:спасибо|благодар|рахмет|рақмет|спустбо).{0,50}(?:вкусн|понрав|отличн|класс|супер)/u', $body) === 1) {
            return true;
        }

        if (preg_match('/(?:вкусн|понрав|отличн|класс|супер).{0,50}(?:спасибо|благодар|рахмет|рақмет|спустбо)/u', $body) === 1) {
            return true;
        }

        if (preg_match('/(?:получил|получила|забрал|забрала).{0,30}(?:заказ|товар|доставк)/u', $body) === 1) {
            return true;
        }

        return false;
    }

    /**
     * Клиент дополняет заказ адресом/доставкой, а не задаёт новый вопрос о записи.
     */
    public function isProvidingAddressOrDeliveryDetail(string $body): bool
    {
        $body = mb_strtolower(trim($body));
        if ($body === '' || str_contains($body, '?')) {
            return false;
        }

        if ($this->isOrderCompletionFeedback($body)) {
            return false;
        }

        if ($this->isTimeQuestion($body)) {
            return false;
        }

        if (KazakhstanCityHeuristics::isDeliveryDestinationStatement($body)) {
            return true;
        }

        // Imperative delivery instructions only — not past-tense «курьер приехал» feedback.
        if (preg_match('/(?:привез(?:ите|и)?|достав(?:ьте|ить|ка)|оставьте|остав(?:ь)?|вызов(?:ите)?\s+курьер|жеткіз|жеткиз|орнат)/u', $body) === 1) {
            return true;
        }

        if (preg_match('/(?:ул\.?|улиц|просп|пр\.|микрорайон|мкр\.?|район|кв\.?|кварти|подъезд|этаж|дом\s*\d)/u', $body) === 1) {
            return true;
        }

        return preg_match('/(?:на\s+)?\p{L}{2,}(?:\s+\p{L}+)?\s+\d{1,4}\b/u', $body) === 1;
    }

    public function isCatalogInquiry(string $body): bool
    {
        $body = mb_strtolower(trim($body));
        if ($body === '') {
            return false;
        }

        foreach ([
            'что есть',
            'а что есть',
            'что у вас',
            'что прода',
            'ассортимент',
            'какие товар',
            'какие издел',
            'какие услуг',
            'что можете предлож',
            'что можете сделать',
            'перечислите',
            'покажите каталог',
            'ваш каталог',
            'что в наличии',
            'товар в наличии',
            'товары в наличии',
            'какие товары',
            'в наличии есть',
            'что делаете',
            'қандай тауар',
            'кандай тауар',
            'қандай қызмет',
            'кандай кызмет',
            'не бар',
            'каталог',
        ] as $needle) {
            if (str_contains($body, $needle)) {
                return true;
            }
        }

        return false;
    }

    public function isPurchaseIntent(string $body): bool
    {
        $body = mb_strtolower(trim($body));

        return str_contains($body, 'купить')
            || str_contains($body, 'приобрест')
            || str_contains($body, 'заказать')
            || str_contains($body, 'хочу что')
            || str_contains($body, 'что то купить')
            || str_contains($body, 'интересует')
            || str_contains($body, 'алғым')
            || str_contains($body, 'алгым')
            || str_contains($body, 'тапсырыс');
    }

    /**
     * Client starts a new order cycle after a previous deal was closed or delivered.
     */
    public function isRepeatOrderIntent(string $body): bool
    {
        $body = mb_strtolower(trim($body));
        if ($body === '' || $this->isOrderCompletionFeedback($body)) {
            return false;
        }

        $hasReorderSignal = preg_match(
            '/(?:ещ[её]|снова|опять|повтор|еще\s+раз).{0,24}(?:заказ|куп|оформ)/u',
            $body,
        ) === 1
            || preg_match(
                '/(?:заказ|куп|оформ).{0,24}(?:ещ[её]|снова|опять|повтор)/u',
                $body,
            ) === 1
            || str_contains($body, 'новый заказ')
            || str_contains($body, 'тағы заказ');

        if (! $hasReorderSignal) {
            return false;
        }

        return $this->isPurchaseIntent($body)
            || $this->isCatalogInquiry($body)
            || preg_match('/(?:помидор|товар|кг|штук|упаков)/u', $body) === 1;
    }

    private function isTimeQuestion(string $body): bool
    {
        if (preg_match('/(?:қанша|канша|неше)\s+(?:уақыт|уакыт|күн|сағат|сагат|минут|час)/u', $body) === 1) {
            return true;
        }

        if (preg_match('/\b(?:уақыт|уакыт|мерзім|мерзим|қашан|кашан)\b/u', $body) === 1 && mb_strlen($body) <= 48) {
            return true;
        }

        return (str_contains($body, 'когда') && (str_contains($body, 'готов') || str_contains($body, 'будет')))
            || str_contains($body, 'срок готов')
            || str_contains($body, 'срок производ')
            || str_contains($body, 'сколько времени')
            || str_contains($body, 'как долго')
            || (str_contains($body, 'сколько') && str_contains($body, 'врем'));
    }

    private function isPriceQuestion(string $body): bool
    {
        if (KazakhstanCityHeuristics::isPriceNegotiation($body)) {
            return true;
        }

        if (preg_match('/(?:қанша|канша|неше)\s+(?:тг|теңге|тенге|баға|стоим)/u', $body) === 1) {
            return true;
        }

        return str_contains($body, 'сколько стоит')
            || str_contains($body, 'какая цена')
            || str_contains($body, 'какую цен')
            || str_contains($body, 'стоимость')
            || str_contains($body, 'прайс')
            || str_contains($body, 'цен')
            || str_contains($body, 'бюджет')
            || str_contains($body, 'тұрады')
            || str_contains($body, 'турады')
            || str_contains($body, 'turady');
    }

    private function isAddressQuestion(string $body): bool
    {
        return str_contains($body, 'адрес')
            || str_contains($body, 'район')
            || str_contains($body, 'улиц')
            || str_contains($body, 'где вы')
            || str_contains($body, 'где наход')
            || str_contains($body, 'мекен')
            || str_contains($body, 'адресің')
            || str_contains($body, 'адресин');
    }

    private function isDeliveryQuestion(string $body): bool
    {
        return str_contains($body, 'достав')
            || str_contains($body, 'монтаж')
            || str_contains($body, 'лифт')
            || str_contains($body, 'парков')
            || str_contains($body, 'жеткіз')
            || str_contains($body, 'жеткиз')
            || str_contains($body, 'орнат');
    }

    private function isPaymentQuestion(string $body): bool
    {
        return str_contains($body, 'оплат')
            || str_contains($body, 'предоплат')
            || str_contains($body, 'реквизит')
            || str_contains($body, 'каспи')
            || str_contains($body, 'kaspi')
            || str_contains($body, 'төлем')
            || str_contains($body, 'толем');
    }

    private function isGreeting(string $body): bool
    {
        return str_contains($body, 'здравств')
            || str_contains($body, 'добрый')
            || str_contains($body, 'доброе')
            || str_contains($body, 'привет')
            || str_contains($body, 'салем')
            || str_contains($body, 'сәлем')
            || str_contains($body, 'сalem');
    }

    public function isAcknowledgement(string $body): bool
    {
        $body = mb_strtolower(trim($body));
        if ($body === '') {
            return false;
        }

        // Exclude deferral phrases from being treated as simple acknowledgements.
        if ($this->isDeferral($body)) {
            return false;
        }

        return preg_match('/^(?:спасибо|благодарю|thanks|thank you|thank u|мерси|иә рахмет|рахмет|рақмет|ракмет|жарайды|ок|ok|okay|хорошо|понятно|ясно|понял|поняла|понял[аоеи]|иә|иа)(?:[!.…,\s]|$)/u', $body) === 1
            || (mb_strlen($body) <= 16 && preg_match('/^(?:да|нет)$/u', $body) === 1);
    }

    /**
     * Returns true when the client is deferring: «подумаем», «позже», «не сейчас», «нет бюджета» etc.
     * These require a nurture/follow-up response, not a polite dead-end close.
     */
    public function isDeferral(string $body): bool
    {
        $body = mb_strtolower(trim($body));
        if ($body === '') {
            return false;
        }

        // Short single-word deferral phrases.
        if (preg_match('/^(?:позже|потом|позжe|после|попозже|позе|постепенно)(?:[!.…,\s]|$)/u', $body) === 1) {
            return true;
        }

        // Multi-word deferral patterns.
        $patterns = [
            '/подума[еёюя]/u',
            '/\bне сейчас\b/u',
            '/\bне готов\b/u',
            '/\bне готова\b/u',
            '/не торопл/u',
            '/посмотр[иею]/u',
            '/\bпока нет\b/u',
            '/\bпока не\b/u',
            '/нет бюджет/u',
            '/бюджет.{0,20}нет/u',
            '/уточн[иею]/u',
            '/\bещё не решил\b/u',
            '/\bне решил\b/u',
            '/\bне решила\b/u',
            '/ой потом/u',
            '/позже напишу/u',
            '/напишу позже/u',
            '/свяжусь позже/u',
            '/позже свяжусь/u',
            '/\bне актуально\b/u',
            '/\bне нужно\b/u',
            '/\bне надо\b/u',
            // Kazakh
            '/кейін/u',
            '/ойланайын/u',
            '/\bәзірге жоқ\b/u',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $body) === 1) {
                return true;
            }
        }

        return false;
    }
}
