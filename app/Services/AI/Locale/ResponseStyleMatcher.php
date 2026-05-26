<?php

declare(strict_types=1);

namespace App\Services\AI\Locale;

final class ResponseStyleMatcher
{
    public function buildInstructions(KazakhstanLocaleProfile $profile): string
    {
        $ruPct = (int) round($profile->ruPct * 100);
        $kkPct = (int) round($profile->kkPct * 100);

        $lines = [
            'Языковой профиль последнего сообщения клиента:',
            "- доминантный язык: {$profile->dominantLabel()} (ru {$ruPct}%, kk {$kkPct}%)",
            "- письмо: {$profile->script}",
            "- тон: {$profile->formality}, slang_score {$profile->slangScore}",
            "- уверенность определения: {$profile->confidence}",
            '',
            'Правила ответа:',
            '- По умолчанию — вежливо, ясно и умеренно формально, если профиль неясен.',
            '- Подстраивай язык и тон под клиента; не навязывай сленг и разговорность.',
        ];

        if ($profile->formality === KazakhstanLocaleProfile::FORMALITY_FORMAL) {
            $lines[] = '- Клиент пишет официально: отвечай вежливо на русском или казахском (по контексту), без сленга и без принудительного смешения языков.';
        }

        if ($profile->dominant === KazakhstanLocaleProfile::DOMINANT_KK || $profile->preferKkCyrillic) {
            $lines[] = '- Отвечай естественным казахским на кириллице (ә, ө, ү, ұ, қ, ң, ғ, һ, і).';
        }

        if ($profile->dominant === KazakhstanLocaleProfile::DOMINANT_RU) {
            $lines[] = '- Отвечай на русском, если клиент не переключился на казахский.';
        }

        if ($profile->allowMixedReply) {
            $lines[] = '- Клиент смешивает русский и казахский — можно отвечать в том же смешанном стиле, кратко и естественно.';
        } else {
            $lines[] = '- Не смешивай русский и казахский в одном ответе, если клиент пишет на одном языке.';
        }

        if ($profile->preferKkCyrillic) {
            $lines[] = '- Клиент пишет транслитом: ответь грамотной казахской кириллицей, сохраняя разговорный тон.';
        }

        if ($profile->formality === KazakhstanLocaleProfile::FORMALITY_CASUAL) {
            $lines[] = '- Допустим лёгкий разговорный тон, но без карикатурного «уличного» стиля.';
        }

        $lines[] = '- Не исправляй грамматику клиента и не поучай.';
        $lines[] = '- Избегай шаблонов «Здравствуйте! Чем могу помочь?», если клиент пишет коротко и по-простому.';
        $lines[] = '- Отвечай кратко, если клиент пишет коротко.';

        return implode("\n", $lines);
    }
}
