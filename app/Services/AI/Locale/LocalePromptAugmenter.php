<?php

declare(strict_types=1);

namespace App\Services\AI\Locale;

use App\Models\Chat;
use App\Models\Message;
use Illuminate\Support\Facades\Cache;

final class LocalePromptAugmenter
{
    public function __construct(
        private readonly KazakhstanLocaleDetector $detector,
        private readonly ResponseStyleMatcher $styleMatcher,
        private readonly LocaleFewShotRetriever $fewShotRetriever,
        private readonly LocaleSlangRagRetriever $slangRagRetriever,
        private readonly ChatInboundLocaleResolver $chatLocaleResolver,
    ) {}

    /**
     * @return array{
     *     profile: KazakhstanLocaleProfile,
     *     blocks: list<string>
     * }
     */
    public function augment(string $userText, ?Chat $chat = null, ?int $companyId = null, ?Message $trigger = null): array
    {
        if (! config('locale_assistant.enabled', true)) {
            return [
                'profile' => KazakhstanLocaleProfile::neutralRussian(),
                'blocks' => [],
            ];
        }

        $profile = $chat !== null
            ? $this->chatLocaleResolver->resolve($chat, $trigger)
            : $this->detector->detect($userText);
        $blocks = [];

        $basePrompt = $this->loadSystemPrompt();
        if ($basePrompt !== '') {
            $blocks[] = $basePrompt;
        }

        $blocks[] = $this->styleMatcher->buildInstructions($profile);

        $fewShots = $this->fewShotRetriever->retrieve($userText, $profile, $companyId);
        $fewShotBlock = $this->fewShotRetriever->formatExamplesBlock($fewShots);
        if ($fewShotBlock !== '') {
            $blocks[] = $fewShotBlock;
        }

        if ($this->slangRagRetriever->shouldRetrieve($profile)) {
            $ragLines = $this->slangRagRetriever->retrieveLines($userText, $profile, $companyId);
            $ragBlock = $this->slangRagRetriever->formatBlock($ragLines);
            if ($ragBlock !== '') {
                $blocks[] = $ragBlock;
            }
        }

        return [
            'profile' => $profile,
            'blocks' => $blocks,
        ];
    }

    /**
     * @return list<array{role: 'system', content: string}>
     */
    public function augmentAsMessages(string $userText, ?Chat $chat = null, ?int $companyId = null, ?Message $trigger = null): array
    {
        $result = $this->augment($userText, $chat, $companyId, $trigger);
        $messages = [];
        foreach ($result['blocks'] as $block) {
            if (trim($block) === '') {
                continue;
            }
            $messages[] = ['role' => 'system', 'content' => $block];
        }

        return $messages;
    }

    public function workspaceLanguageInstruction(KazakhstanLocaleProfile $profile): string
    {
        $label = $profile->dominantLabel();

        return match ($profile->dominant) {
            KazakhstanLocaleProfile::DOMINANT_KK => "Отвечай на казахском языке (кириллица, ә ө ү ұ қ ң ғ һ і), {$profile->formality}.",
            KazakhstanLocaleProfile::DOMINANT_MIXED => 'Отвечай в смешанном русско-казахском стиле, как пользователь, '.$profile->formality.'.',
            KazakhstanLocaleProfile::DOMINANT_TRANSLIT_MIXED => 'Пользователь пишет транслитом — отвечай естественной казахской кириллицей или понятным русским по контексту, '.$profile->formality.'.',
            KazakhstanLocaleProfile::DOMINANT_RU => "Отвечай на русском языке, {$profile->formality}.",
            default => 'Отвечай вежливо и умеренно формально. Язык — русский, если пользователь не пишет на казахском.',
        }." Доминантный язык запроса: {$label}.";
    }

    private function loadSystemPrompt(): string
    {
        $path = (string) (config('locale_assistant.system_prompt_path') ?: resource_path('locale/prompts/kz_assistant_system.md'));

        return Cache::remember('locale_system_prompt:'.md5($path), 3600, function () use ($path): string {
            if (! is_readable($path)) {
                return '';
            }

            return trim((string) file_get_contents($path));
        });
    }

}
