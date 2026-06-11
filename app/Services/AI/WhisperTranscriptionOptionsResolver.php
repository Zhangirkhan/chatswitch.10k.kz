<?php

declare(strict_types=1);

namespace App\Services\AI;

use App\Models\Message;
use App\Services\AI\Locale\KazakhstanLocaleDetector;
use App\Services\AI\Locale\KazakhstanLocaleProfile;
use App\Support\MessageInboundText;
use App\Support\VoiceInboundHelper;
use Illuminate\Support\Str;

/**
 * Подбирает language и prompt для Whisper с учётом последнего сообщения клиента (KZ: ru/kk/mixed).
 * Если язык не очевиден, language не отправляется — Whisper сам определяет речь.
 */
final class WhisperTranscriptionOptionsResolver
{
    private const PROMPT_CHAR_LIMIT = 800;

    public function __construct(
        private readonly KazakhstanLocaleDetector $localeDetector,
    ) {}

    /**
     * @return array{language: string|null, prompt: string}
     */
    public function resolve(Message $message): array
    {
        $explicit = trim((string) config('services.openai.whisper_language', ''));
        $language = $explicit !== '' ? $explicit : $this->inferLanguage($message);
        $prompt = $this->buildPrompt($message, $language);

        return [
            'language' => $language,
            'prompt' => $prompt,
        ];
    }

    /**
     * @return array{language: string|null, prompt: string}
     */
    public function resolveForDictation(?int $chatId, ?string $userLocale): array
    {
        $language = $this->normalizeDictationLocale($userLocale);

        if ($chatId !== null) {
            $inferred = $this->inferLanguageFromChat($chatId);
            if ($inferred !== null) {
                $language = $inferred;
            }
        }

        if ($language === null) {
            $default = strtolower(trim((string) config('accel.whisper_default_language', 'auto')));
            $language = in_array($default, ['ru', 'kk'], true) ? $default : null;
        }

        return [
            'language' => $language,
            'prompt' => $this->promptForDictation($language),
        ];
    }

    private function normalizeDictationLocale(?string $locale): ?string
    {
        $locale = strtolower(trim((string) $locale));
        if ($locale === '' || $locale === 'auto' || $locale === 'en') {
            return null;
        }

        if (str_starts_with($locale, 'kk')) {
            return 'kk';
        }

        if (str_starts_with($locale, 'ru')) {
            return 'ru';
        }

        return null;
    }

    private function inferLanguageFromChat(int $chatId): ?string
    {
        if (! filter_var(config('accel.whisper_auto_detect_language', true), FILTER_VALIDATE_BOOLEAN)) {
            return null;
        }

        $lines = Message::query()
            ->where('chat_id', $chatId)
            ->where('direction', 'inbound')
            ->with('transcript:message_id,kind,status,text')
            ->orderByDesc('message_timestamp')
            ->orderByDesc('id')
            ->limit(3)
            ->get()
            ->reverse()
            ->map(static fn (Message $item): string => trim(MessageInboundText::forMessage($item)))
            ->filter(fn (string $line): bool => $line !== '' && ! $this->isUnusableContextLine($line))
            ->values();

        foreach ($lines as $line) {
            $inferred = $this->inferFromText($line);
            if ($inferred !== null) {
                return $inferred;
            }
        }

        return null;
    }

    private function promptForDictation(?string $language): string
    {
        $base = trim((string) config('accel.whisper_prompt_dictation', ''));
        $suffix = match ($this->normalizeLanguage($language)) {
            'kk' => trim((string) config('accel.whisper_prompt_kk')),
            'ru' => trim((string) config('accel.whisper_prompt_ru')),
            default => trim((string) config('accel.whisper_prompt_auto')),
        };

        return \Illuminate\Support\Str::limit(trim($base.' '.$suffix), self::PROMPT_CHAR_LIMIT, '');
    }

    /**
     * @return array{language: string|null, prompt: string}
     */
    public function optionsForLanguage(?string $language): array
    {
        $language = $this->normalizeLanguage($language);

        return [
            'language' => $language,
            'prompt' => $this->promptForLanguage($language),
        ];
    }

    private function inferLanguage(Message $message): ?string
    {
        $default = strtolower(trim((string) config('accel.whisper_default_language', 'auto')));
        if ($default === '') {
            $default = 'auto';
        }

        if (in_array($default, ['ru', 'kk'], true)
            && ! filter_var(config('accel.whisper_auto_detect_language', true), FILTER_VALIDATE_BOOLEAN)) {
            return $default;
        }

        $latest = VoiceInboundHelper::isVoiceType((string) $message->type)
            ? $this->latestInboundTextLineBefore($message)
            : $this->latestInboundLineBefore($message);
        if ($latest !== '') {
            $inferred = $this->inferFromText($latest);
            if ($inferred !== null) {
                return $inferred;
            }
        }

        if (VoiceInboundHelper::isVoiceType((string) $message->type)) {
            return $this->voiceFallbackLanguage($default);
        }

        return $default === 'auto' ? null : $default;
    }

    private function inferFromText(string $text): ?string
    {
        if ($this->looksClearlyKazakh($text)) {
            return 'kk';
        }

        if ($this->looksClearlyRussian($text)) {
            return 'ru';
        }

        $profile = $this->localeDetector->detect($text);

        if ($profile->dominant === KazakhstanLocaleProfile::DOMINANT_KK
            || ($profile->kkPct > $profile->ruPct && $profile->kkPct >= 0.55)) {
            return 'kk';
        }

        if ($profile->dominant === KazakhstanLocaleProfile::DOMINANT_RU
            || ($profile->ruPct > $profile->kkPct && $profile->ruPct >= 0.55)) {
            return 'ru';
        }

        return null;
    }

    private function voiceFallbackLanguage(string $default): ?string
    {
        $voiceFallback = strtolower(trim((string) config('accel.whisper_voice_fallback_language', 'auto')));
        if ($voiceFallback !== '' && $voiceFallback !== 'auto') {
            return $voiceFallback;
        }

        return $default === 'auto' ? null : $default;
    }

    public static function shouldRetryWithKazakh(string $text, ?string $language): bool
    {
        if ($language === 'kk') {
            return false;
        }

        if ($language === 'ru') {
            return false;
        }

        $text = trim($text);
        if ($text === '' || self::looksLikePromptEcho($text, '') || self::looksLikeKazakhPromptEcho($text)) {
            return true;
        }

        if (preg_match('/[әіңғүұқөһ]/u', mb_strtolower($text)) === 1) {
            return false;
        }

        if (self::looksLikeValidRussianPhrase($text)) {
            return false;
        }

        return mb_strlen($text) <= 48 && preg_match('/[а-яё]/ui', $text) === 1;
    }

    public static function shouldRetryWithRussian(string $text, ?string $language): bool
    {
        if ($language === 'ru') {
            return false;
        }

        $text = trim($text);
        if ($text === '') {
            return true;
        }

        if (self::looksLikeKazakhPromptEcho($text)) {
            return true;
        }

        if (preg_match('/[әіңғүұқөһ]/u', mb_strtolower($text)) === 1) {
            return false;
        }

        if ($language === 'kk' && mb_strlen($text) <= 80) {
            return true;
        }

        return false;
    }

    public static function looksLikeKazakhPromptEcho(string $text): bool
    {
        $lower = mb_strtolower(trim($text));
        if ($lower === '') {
            return false;
        }

        $kkPrompt = mb_strtolower(trim((string) config('accel.whisper_prompt_kk', '')));
        if ($kkPrompt !== '' && mb_strlen($lower) <= 120 && str_contains($kkPrompt, $lower)) {
            return true;
        }

        $fragments = [
            'қазақша',
            'сөйлеу',
            'сөздерді',
            'өзгертпе',
            'айтылған',
        ];

        foreach ($fragments as $fragment) {
            if (str_contains($lower, $fragment)) {
                return true;
            }
        }

        return false;
    }

    private function buildPrompt(Message $message, ?string $language): string
    {
        $parts = [$this->promptForLanguage($language)];

        $recent = $this->recentSnippetForPrompt($message);
        if ($recent !== '') {
            $parts[] = $recent;
        }

        return Str::limit(trim(implode(' ', array_filter($parts))), self::PROMPT_CHAR_LIMIT, '');
    }

    private function promptForLanguage(?string $language): string
    {
        return match ($this->normalizeLanguage($language)) {
            'kk' => trim((string) config('accel.whisper_prompt_kk')),
            'ru' => trim((string) config('accel.whisper_prompt_ru')),
            default => trim((string) config('accel.whisper_prompt_auto')),
        };
    }

    private function normalizeLanguage(?string $language): ?string
    {
        $language = strtolower(trim((string) $language));

        return in_array($language, ['ru', 'kk'], true) ? $language : null;
    }

    private function latestInboundTextLineBefore(Message $message): string
    {
        $message->loadMissing('chat');
        if ($message->chat_id === null) {
            return '';
        }

        /** @var Message|null $previous */
        $previous = Message::query()
            ->where('chat_id', $message->chat_id)
            ->where('id', '<', $message->id)
            ->where('direction', 'inbound')
            ->where('type', 'chat')
            ->whereNotNull('body')
            ->where('body', '!=', '')
            ->orderByDesc('message_timestamp')
            ->orderByDesc('id')
            ->first();

        if ($previous === null) {
            return '';
        }

        $line = trim((string) $previous->body);
        if ($line === '' || $this->isUnusableContextLine($line)) {
            return '';
        }

        return $line;
    }

    private function latestInboundLineBefore(Message $message): string
    {
        $message->loadMissing('chat');
        if ($message->chat_id === null) {
            return '';
        }

        /** @var Message|null $previous */
        $previous = Message::query()
            ->where('chat_id', $message->chat_id)
            ->where('id', '<', $message->id)
            ->where('direction', 'inbound')
            ->with('transcript:message_id,kind,status,text')
            ->orderByDesc('message_timestamp')
            ->orderByDesc('id')
            ->first();

        if ($previous === null) {
            return '';
        }

        $line = trim(MessageInboundText::forMessage($previous));
        if ($line === '' || $this->isUnusableContextLine($line)) {
            return '';
        }

        return $line;
    }

    private function recentInboundText(Message $message): string
    {
        $message->loadMissing('chat');
        if ($message->chat_id === null) {
            return '';
        }

        $lines = Message::query()
            ->where('chat_id', $message->chat_id)
            ->where('id', '<', $message->id)
            ->where('direction', 'inbound')
            ->with('transcript:message_id,kind,status,text')
            ->orderByDesc('message_timestamp')
            ->orderByDesc('id')
            ->limit(3)
            ->get()
            ->reverse()
            ->map(static fn (Message $item): string => trim(MessageInboundText::forMessage($item)))
            ->filter(fn (string $line): bool => $line !== '' && ! $this->isUnusableContextLine($line))
            ->values()
            ->all();

        return trim(implode("\n", $lines));
    }

    private function isUnusableContextLine(string $line): bool
    {
        if (self::looksLikePromptEcho($line, (string) config('accel.whisper_prompt_ru', ''))) {
            return true;
        }

        if (self::looksLikePromptEcho($line, (string) config('accel.whisper_prompt_auto', ''))) {
            return true;
        }

        if (self::looksLikeKazakhPromptEcho($line)) {
            return true;
        }

        $lower = mb_strtolower(trim($line));

        return str_contains($lower, 'казахстан, тенге, доставка, заказ, цена');
    }

    private function looksClearlyRussian(string $text): bool
    {
        $lower = mb_strtolower($text);

        return $this->countKazakhLetters($lower) === 0
            && preg_match('/[а-яё]/u', $lower) === 1
            && preg_match('/\b(здравствуйте|привет|сколько|стоит|цена|доставка|заказ|можно|нужно|хочу|есть|когда|где|как|скажите|подскажите|русск|говор)\b/u', $lower) === 1;
    }

    private function looksClearlyKazakh(string $text): bool
    {
        $lower = mb_strtolower($text);

        return $this->countKazakhLetters($lower) >= 2
            || preg_match('/\b(сәлем|сагат|сағат|неше|қанша|канша|уақыт|уакыт|рахмет|бағасы|жеткізу|тапсырыс|керек|бар ма|қалай|қашан)\b/ui', $lower) === 1;
    }

    private static function looksLikeValidRussianPhrase(string $text): bool
    {
        $lower = mb_strtolower(trim($text));

        return preg_match('/\b(здравствуйте|привет|сколько|стоит|цена|доставка|заказ|можно|нужно|хочу|есть|когда|где|как|скажите|подскажите|спасибо|да|нет|хорошо)\b/u', $lower) === 1;
    }

    private function countKazakhLetters(string $text): int
    {
        preg_match_all('/[әіңғүұқөһ]/u', $text, $matches);

        return count($matches[0] ?? []);
    }

    private function recentSnippetForPrompt(Message $message): string
    {
        $text = $this->recentInboundText($message);
        if ($text === '') {
            return '';
        }

        return Str::limit($text, 200, '…');
    }

    public static function looksLikePromptEcho(string $text, string $prompt): bool
    {
        $text = mb_strtolower(trim($text));
        $prompt = mb_strtolower(trim($prompt));

        if ($text === '') {
            return false;
        }

        if ($prompt !== '' && mb_strlen($text) <= 120 && str_contains($prompt, $text)) {
            return true;
        }

        $echoFragments = [
            'казахстан, тенге, доставка, заказ, цена, услуга',
            'русский язык',
            'қазақ тілі',
        ];

        foreach ($echoFragments as $fragment) {
            if (str_contains($text, $fragment)) {
                return true;
            }
        }

        return false;
    }
}
