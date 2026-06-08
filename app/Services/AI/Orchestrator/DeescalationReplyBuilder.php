<?php

declare(strict_types=1);

namespace App\Services\AI\Orchestrator;

use App\Models\KnowledgeRule;
use App\Services\AI\KnowledgeContextRepository;

final class DeescalationReplyBuilder
{
    public function __construct(
        private readonly OrchestratorDynamicReplyBuilder $dynamicReplies,
        private readonly KnowledgeContextRepository $knowledge,
    ) {}

    public function build(ClientSituation $situation, string $body, int $companyId, int $attemptNumber, bool $escalating): string
    {
        if ($escalating) {
            return $this->escalationReply($situation, $body);
        }

        $policy = $this->knowledgeSnippet($companyId, $situation);

        return match ($situation->situation) {
            ClientSituation::SITUATION_DELAY => $this->dynamicReplies->localize(
                $body,
                'Понимаю, что задержка неприятна. Подскажите, пожалуйста, номер заказа или телефон, с которого оформляли — проверю статус и вернусь с точной информацией.',
                'Кешіктіру ыңғайсыз екенін түсінемін. Тапсырыс нөмірін немесе тапсырыс берген телефонды жазыңыз — статусты тексеремін.',
            ),
            ClientSituation::SITUATION_QUALITY => $this->dynamicReplies->localize(
                $body,
                'Жаль, что так вышло — давайте разберёмся. Пришлите, пожалуйста, фото дефекта и дату монтажа/получения, если есть.'.($policy !== '' ? ' '.$policy : ''),
                'Мұндай болғаны өкінішті — түсінемін. Ақау фотосын және орнату/алу күнін жіберіңіз.'.($policy !== '' ? ' '.$policy : ''),
            ),
            ClientSituation::SITUATION_REFUND => $this->dynamicReplies->localize(
                $body,
                'Понимаю ваше беспокойство. Чтобы корректно рассмотреть возврат, подскажите номер заказа и дату оплаты.'.($policy !== '' ? ' '.$policy : ' Уточню условия у ответственного менеджера.'),
                'Алаңдаушылығыңызды түсінемін. Қайтаруды қарау үшін тапсырыс нөмірі мен төлем күнін жазыңыз.',
            ),
            ClientSituation::SITUATION_SCAM_ACCUSATION => $this->dynamicReplies->localize(
                $body,
                'Понимаю, что ситуация вызывает вопросы. Мы работаем официально — давайте спокойно разберём детали заказа. Передам старшему специалисту, если потребуется.',
                'Сұрақтар туындағанын түсінемін. Ресми жұмыс істейміз — тапсырыс мәліметтерін тыныш талқылайық.',
            ),
            ClientSituation::SITUATION_AGGRESSION => $this->dynamicReplies->localize(
                $body,
                'Вижу, что ситуация неприятная. Передам старшему специалисту — он свяжется с вами и поможет разобраться.',
                'Жағдай ыңғайсыз екенін көріп тұрмын. Аға маманға беремін — сізбен хабарласып, көмектеседі.',
            ),
            ClientSituation::SITUATION_LEGAL => 'Получили ваше сообщение. Передаю ответственному специалисту для официального рассмотрения.',
            ClientSituation::SITUATION_COMPLAINT => $this->dynamicReplies->localize(
                $body,
                'Спасибо, что написали — понимаю ваше недовольство. Расскажите, пожалуйста, что именно пошло не так, чтобы мы могли помочь.',
                'Жазғаныңызға рахмет — наразылығыңызды түсінемін. Не дұрыс болмағанын нақтылап жіберіңіз.',
            ),
            ClientSituation::SITUATION_PRICE_PRESSURE => $this->dynamicReplies->localize(
                $body,
                'Понимаю, что сравниваете предложения. Расскажу по нашим условиям и гарантии — без скрытых пунктов. Что для вас сейчас важнее: цена, срок или комплектация?',
                'Ұсыныстарды салыстыратыныңызды түсінемін. Біздің шарттар мен кепілдік бойынша айтып беремін.',
            ),
            ClientSituation::SITUATION_PASSIVE_AGGRESSIVE => $this->dynamicReplies->localize(
                $body,
                'Понимаю, что ситуация раздражает. Давайте по шагам: что сейчас самое срочное — статус заказа, качество или оплата?',
                'Жағдай ренжітетінін түсінемін. Қазір не ең маңызды — тапсырыс статусы, сапа ма, төлем бе?',
            ),
            ClientSituation::SITUATION_CONFUSION => $attemptNumber > 0
                ? $this->dynamicReplies->localize($body, 'Кратко продублирую главное из предыдущего сообщения. Если останутся вопросы — напишите, уточню точечно.', 'Алдыңғы хабарламаның маңыздысын қысқаша қайталаймын.')
                : $this->dynamicReplies->localize($body, 'Понял, переформулирую проще. Напишите, какой пункт остался непонятен — отвечу коротко.', 'Түсіндім, оңайлап түсіндіремін. Қай тармақ түсініксіз — жазыңыз.'),
            ClientSituation::SITUATION_OFF_TOPIC => $this->dynamicReplies->localize(
                $body,
                'Если есть вопрос по заказу или услуге — с радостью помогу. Напишите, что вас интересует.',
                'Тапсырыс немесе қызмет бойынша сұрақ болса — көмектесемін.',
            ),
            default => $this->dynamicReplies->localize(
                $body,
                'Понимаю. Уточню детали и вернусь с ответом.',
                'Түсінемін. Мәліметті нақтылап, жауап беремін.',
            ),
        };
    }

    public function escalationReply(ClientSituation $situation, string $body): string
    {
        return match ($situation->situation) {
            ClientSituation::SITUATION_LEGAL => 'Получили ваше сообщение. Передаю ответственному специалисту для официального рассмотрения.',
            default => $this->dynamicReplies->localize(
                $body,
                'Передаю старшему менеджеру — он свяжется с вами в ближайшее время и поможет решить вопрос. Спасибо за терпение.',
                'Аға менеджерге беремін — жақын арада хабарласып, мәселені шешеді. Рахмет.',
            ),
        };
    }

    private function knowledgeSnippet(int $companyId, ClientSituation $situation): string
    {
        $types = match ($situation->situation) {
            ClientSituation::SITUATION_REFUND => ['refund', 'complaint'],
            ClientSituation::SITUATION_QUALITY => ['warranty', 'complaint'],
            ClientSituation::SITUATION_DELAY, ClientSituation::SITUATION_COMPLAINT => ['complaint', 'fulfillment'],
            default => ['complaint'],
        };

        $rules = collect($this->knowledge->forPrompt($companyId)['rules'])
            ->filter(fn (KnowledgeRule $rule): bool => in_array($rule->type, $types, true))
            ->take(1);

        $content = trim((string) ($rules->first()?->content ?? ''));

        return $content !== '' ? mb_substr($content, 0, 280) : '';
    }
}
