<?php

declare(strict_types=1);

namespace App\Services\AI\Orchestrator;

use App\Models\Chat;
use App\Models\Message;
use App\Support\MessageInboundText;

final class ClientSituationDetector
{
    public function __construct(
        private readonly ClientMessageIntentDetector $intents,
    ) {}

    public function detect(string $body, ?Chat $chat = null, ?Message $trigger = null): ClientSituation
    {
        $normalized = mb_strtolower(trim($body));
        if ($normalized === '') {
            return ClientSituation::none();
        }

        if ($this->matchesPositiveFeedback($normalized)) {
            return ClientSituation::none();
        }

        $signals = [];

        if ($this->matchesLegalThreat($normalized)) {
            $signals[] = 'legal_keywords';

            return new ClientSituation(ClientSituation::SITUATION_LEGAL, 3, 0.95, $signals);
        }

        if ($this->matchesDelay($normalized)) {
            $signals[] = 'delay';

            return new ClientSituation(ClientSituation::SITUATION_DELAY, 1, 0.8, $signals);
        }

        if ($this->matchesComplaint($normalized)) {
            $signals[] = 'complaint';

            return new ClientSituation(ClientSituation::SITUATION_COMPLAINT, 1, 0.78, $signals);
        }

        if ($this->matchesQualityIssue($normalized)) {
            $signals[] = 'quality';

            return new ClientSituation(ClientSituation::SITUATION_QUALITY, 1, 0.82, $signals);
        }

        if ($this->matchesAggression($normalized)) {
            $signals[] = 'aggression';

            return new ClientSituation(ClientSituation::SITUATION_AGGRESSION, 2, 0.9, $signals);
        }

        if ($this->matchesRefund($normalized)) {
            $signals[] = 'refund';

            return new ClientSituation(ClientSituation::SITUATION_REFUND, 2, 0.88, $signals);
        }

        if ($this->matchesScamAccusation($normalized)) {
            $signals[] = 'scam_accusation';

            return new ClientSituation(ClientSituation::SITUATION_SCAM_ACCUSATION, 2, 0.88, $signals);
        }

        if ($this->matchesPricePressure($normalized)) {
            $signals[] = 'price_pressure';

            return new ClientSituation(ClientSituation::SITUATION_PRICE_PRESSURE, 1, 0.75, $signals);
        }

        if ($this->matchesPassiveAggressive($normalized)) {
            $signals[] = 'passive_aggressive';

            return new ClientSituation(ClientSituation::SITUATION_PASSIVE_AGGRESSIVE, 1, 0.72, $signals);
        }

        if ($this->matchesConfusionMarker($normalized)) {
            $signals[] = 'confusion_marker';

            return new ClientSituation(ClientSituation::SITUATION_CONFUSION, 0, 0.7, $signals);
        }

        if ($chat !== null && $this->matchesConfusionRepeat($normalized, $chat, $trigger)) {
            $signals[] = 'confusion_repeat';

            return new ClientSituation(ClientSituation::SITUATION_CONFUSION, 0, 0.7, $signals);
        }

        if ($this->matchesOffTopic($normalized)) {
            $signals[] = 'off_topic';

            return new ClientSituation(ClientSituation::SITUATION_OFF_TOPIC, 0, 0.65, $signals);
        }

        return ClientSituation::none();
    }

    private function matchesLegalThreat(string $body): bool
    {
        foreach ([
            'подам в суд', 'в суд', 'прокурат', 'полици', 'закон о', 'юрист', 'адвокат',
            'consumer rights', 'защит прав', 'штраф', 'ответственност',
        ] as $needle) {
            if (str_contains($body, $needle)) {
                return true;
            }
        }

        return false;
    }

    private function matchesAggression(string $body): bool
    {
        if ($this->hasProfanity($body)) {
            return true;
        }

        return $this->hasShoutingCaps($body)
            || str_contains($body, 'идиот')
            || str_contains($body, 'дебил')
            || str_contains($body, 'туп')
            || str_contains($body, 'урод');
    }

    private function hasShoutingCaps(string $body): bool
    {
        $letters = preg_replace('/[^a-zа-яёәіңғүұқөһ]/ui', '', $body) ?? '';
        if ($letters === '' || mb_strlen($letters) < 6) {
            return false;
        }

        $upper = mb_strlen(preg_replace('/[^A-ZА-ЯЁ]/u', '', $body) ?? '');

        return $upper / mb_strlen($letters) >= 0.6;
    }

    private function matchesPositiveFeedback(string $body): bool
    {
        $positiveNeedles = [
            'спасибо', 'благодар', 'отлично', 'супер', 'класс', 'вкусно', 'рекоменд',
            'молодцы', 'доволен', 'довольна', 'замечательно', 'прекрасно', 'обожаю',
            'лучшие', 'круто', 'шикарно', 'восхит', 'рад ', 'рада ', 'рады ',
        ];
        $negativeNeedles = [
            'жалоб', 'недовол', 'возмущ', 'верните', 'вернуть', 'мошенник', 'обман',
            'ужас', 'кошмар', 'плох', 'брак', 'дефект', 'идиот', 'дебил', 'туп', 'урод',
            'развод', 'кидал', 'претенз', 'рекламац',
        ];

        $hasPositive = false;
        foreach ($positiveNeedles as $needle) {
            if (str_contains($body, $needle)) {
                $hasPositive = true;
                break;
            }
        }

        if (! $hasPositive) {
            return false;
        }

        foreach ($negativeNeedles as $needle) {
            if (str_contains($body, $needle)) {
                return false;
            }
        }

        return true;
    }

    private function hasProfanity(string $body): bool
    {
        $needles = (array) config('accel.conflict_handling.profanity_keywords', [
            'бля', 'хуй', 'пизд', 'ебан', 'ебат', 'сука', 'мудак', 'гандон',
        ]);

        foreach ($needles as $needle) {
            if (is_string($needle) && $needle !== '' && str_contains($body, mb_strtolower($needle))) {
                return true;
            }
        }

        return false;
    }

    private function matchesRefund(string $body): bool
    {
        return str_contains($body, 'верните деньги')
            || str_contains($body, 'вернуть деньги')
            || str_contains($body, 'возврат')
            || str_contains($body, 'chargeback')
            || str_contains($body, 'оспор')
            || (str_contains($body, 'kaspi') && (str_contains($body, 'оспор') || str_contains($body, 'верн')));
    }

    private function matchesScamAccusation(string $body): bool
    {
        return str_contains($body, 'мошенник')
            || str_contains($body, 'развод')
            || str_contains($body, 'обман')
            || str_contains($body, 'кидал')
            || str_contains($body, 'афер');
    }

    private function matchesQualityIssue(string $body): bool
    {
        return $this->containsTerm($body, 'брак')
            || $this->containsTerm($body, 'дефект')
            || str_contains($body, 'царап')
            || ($this->containsTerm($body, 'скол') && ! str_contains($body, 'сколько'))
            || str_contains($body, 'сломал')
            || str_contains($body, 'криво')
            || str_contains($body, 'плохого кач')
            || str_contains($body, 'плохое кач')
            || str_contains($body, 'не работ');
    }

    private function containsTerm(string $body, string $term): bool
    {
        return preg_match('/(?<![\p{L}])'.preg_quote($term, '/').'(?![\p{L}])/u', $body) === 1;
    }

    private function matchesDelay(string $body): bool
    {
        return (str_contains($body, 'где') && (str_contains($body, 'заказ') || str_contains($body, 'достав')))
            || str_contains($body, 'опозда')
            || str_contains($body, 'задерж')
            || str_contains($body, 'сколько ждать')
            || str_contains($body, 'когда будет')
            || (str_contains($body, 'обещал') && str_contains($body, 'где'));
    }

    private function matchesComplaint(string $body): bool
    {
        return str_contains($body, 'жалоб')
            || str_contains($body, 'недовол')
            || str_contains($body, 'возмущ')
            || str_contains($body, 'рекламац')
            || str_contains($body, 'претенз')
            || str_contains($body, 'кошмар')
            || str_contains($body, 'ужас');
    }

    private function matchesPricePressure(string $body): bool
    {
        return (str_contains($body, 'конкур') && str_contains($body, 'дешев'))
            || str_contains($body, 'скидк') && str_contains($body, 'иначе')
            || str_contains($body, 'match') && str_contains($body, 'price');
    }

    private function matchesPassiveAggressive(string $body): bool
    {
        return str_contains($body, 'как всегда')
            || str_contains($body, 'ну конечно')
            || str_contains($body, 'ожидаемо')
            || str_contains($body, 'ничего нового')
            || str_contains($body, 'сами виноват');
    }

    private function matchesConfusionMarker(string $body): bool
    {
        return in_array($body, ['???', '??', '?', 'не понял', 'не поняла', 'не понятно', 'что?', 'х?', 'а?'], true);
    }

    private function matchesOffTopic(string $body): bool
    {
        if ($this->matchesConfusionMarker($body)) {
            return false;
        }

        return str_contains($body, 'продайте мне слон')
            || str_contains($body, 'анекдот');
    }

    private function matchesConfusionRepeat(string $body, Chat $chat, ?Message $trigger): bool
    {
        if ($this->matchesConfusionMarker($body)) {
            return true;
        }

        if ($this->intents->isSpecific($body)) {
            $recentInbound = Message::query()
                ->where('chat_id', $chat->id)
                ->where('direction', 'inbound')
                ->when($trigger !== null, fn ($q) => $q->where('id', '<', $trigger->id))
                ->orderByDesc('message_timestamp')
                ->orderByDesc('id')
                ->limit(5)
                ->pluck('body');

            $sameIntentCount = 0;
            $intent = $this->intents->detect($body);
            foreach ($recentInbound as $previous) {
                if ($this->intents->detect((string) $previous) === $intent) {
                    $sameIntentCount++;
                }
            }

            return $sameIntentCount >= 2;
        }

        return false;
    }
}
