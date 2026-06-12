import { stripWaMarkup } from '@/utils/waMarkup';
import { matchesKazakhInformalText } from '@/utils/kazakhInformalText';
import type { AppLocale } from '@/i18n/types';

export type MessageLanguageTarget = 'ru' | 'kk' | 'en' | 'zh' | 'tr' | 'ar';

export const MESSAGE_LANGUAGE_LABELS: Record<MessageLanguageTarget, string> = {
    ru: 'русский',
    kk: 'қазақша',
    en: 'English',
    zh: '中文',
    tr: 'Türkçe',
    ar: 'العربية',
};

const TURKISH_LETTERS = /[ğşıöüçĞŞİÖÜÇ]/u;

const MIN_SAMPLE_LEN = 3;

export function sampleMessageText(raw: string | null | undefined): string {
    return stripWaMarkup(raw ?? '').replace(/\s+/g, ' ').trim();
}

type ScriptCounts = {
    cyrillic: number;
    latin: number;
    arabic: number;
    cjk: number;
    other: number;
};

function countScripts(text: string): ScriptCounts {
    const counts: ScriptCounts = {
        cyrillic: 0,
        latin: 0,
        arabic: 0,
        cjk: 0,
        other: 0,
    };

    for (const char of text) {
        if (/\s/u.test(char) || /\d/u.test(char) || /[^\p{L}]/u.test(char)) {
            continue;
        }

        if (/\p{Script=Cyrillic}/u.test(char)) {
            counts.cyrillic += 1;
        } else if (/\p{Script=Latin}/u.test(char)) {
            counts.latin += 1;
        } else if (/\p{Script=Arabic}/u.test(char)) {
            counts.arabic += 1;
        } else if (/\p{Script=Han}/u.test(char) || /\p{Script=Hiragana}/u.test(char) || /\p{Script=Katakana}/u.test(char)) {
            counts.cjk += 1;
        } else {
            counts.other += 1;
        }
    }

    return counts;
}

function letterCount(text: string): number {
    const counts = countScripts(text);

    return counts.cyrillic + counts.latin + counts.arabic + counts.cjk + counts.other;
}

function dominantScript(text: string): keyof ScriptCounts | null {
    const counts = countScripts(text);
    const entries = Object.entries(counts) as [keyof ScriptCounts, number][];
    const [best] = entries.sort((a, b) => b[1] - a[1]);

    if (!best || best[1] < MIN_SAMPLE_LEN) {
        return null;
    }

    return best[0];
}

function isLikelyRussian(text: string): boolean {
    if (matchesKazakhInformalText(text)) {
        return false;
    }

    const counts = countScripts(text);
    if (counts.cyrillic < MIN_SAMPLE_LEN) {
        return false;
    }

    return counts.cyrillic >= counts.latin;
}

function isLikelyKazakh(text: string): boolean {
    return matchesKazakhInformalText(text);
}

function isLikelyEnglish(text: string): boolean {
    const counts = countScripts(text);
    if (counts.latin < MIN_SAMPLE_LEN) {
        return false;
    }

    return counts.latin > counts.cyrillic && !TURKISH_LETTERS.test(text);
}

function isLikelyTurkish(text: string): boolean {
    return TURKISH_LETTERS.test(text) || (dominantScript(text) === 'latin' && /[ıİ]/u.test(text));
}

function isLikelyArabic(text: string): boolean {
    const counts = countScripts(text);

    return counts.arabic >= MIN_SAMPLE_LEN && counts.arabic >= counts.latin;
}

function isLikelyChinese(text: string): boolean {
    const counts = countScripts(text);

    return counts.cjk >= MIN_SAMPLE_LEN;
}

/**
 * Сообщение уже на целевом языке (перевод не нужен).
 */
export function messageMatchesTargetLanguage(
    raw: string | null | undefined,
    target: MessageLanguageTarget,
): boolean {
    const text = sampleMessageText(raw);
    if (letterCount(text) < MIN_SAMPLE_LEN) {
        return true;
    }

    switch (target) {
        case 'ru':
            return isLikelyRussian(text);
        case 'kk':
            return isLikelyKazakh(text);
        case 'en':
            return isLikelyEnglish(text);
        case 'tr':
            return isLikelyTurkish(text);
        case 'ar':
            return isLikelyArabic(text);
        case 'zh':
            return isLikelyChinese(text);
        default:
            return false;
    }
}

/** Показывать кнопку «Перевод» для входящего текста. */
export function messageNeedsTranslation(
    raw: string | null | undefined,
    target: MessageLanguageTarget,
): boolean {
    const text = sampleMessageText(raw);
    if (letterCount(text) < MIN_SAMPLE_LEN) {
        return false;
    }

    return !messageMatchesTargetLanguage(text, target);
}

/** Язык клиента по последним входящим сообщениям. */
export function detectClientLanguage(samples: (string | null | undefined)[]): MessageLanguageTarget | null {
    const scores: Record<MessageLanguageTarget, number> = {
        ru: 0,
        kk: 0,
        en: 0,
        zh: 0,
        tr: 0,
        ar: 0,
    };

    for (const raw of samples) {
        const text = sampleMessageText(raw);
        if (letterCount(text) < MIN_SAMPLE_LEN) {
            continue;
        }

        if (isLikelyKazakh(text)) {
            scores.kk += 3;
        } else if (isLikelyRussian(text)) {
            scores.ru += 2;
        } else if (isLikelyEnglish(text)) {
            scores.en += 2;
        } else if (isLikelyArabic(text)) {
            scores.ar += 2;
        } else if (isLikelyChinese(text)) {
            scores.zh += 2;
        } else if (isLikelyTurkish(text)) {
            scores.tr += 2;
        }
    }

    const [best] = (Object.entries(scores) as [MessageLanguageTarget, number][]).sort((a, b) => b[1] - a[1]);
    if (!best || best[1] === 0) {
        return null;
    }

    return best[0];
}

/** Целевой язык для перевода исходящего черновика оператора. */
export function resolveOutgoingTargetLanguage(
    draft: string | null | undefined,
    clientLanguage: MessageLanguageTarget | null,
): MessageLanguageTarget | null {
    const text = sampleMessageText(draft);
    if (letterCount(text) < MIN_SAMPLE_LEN) {
        return null;
    }

    if (clientLanguage !== null) {
        return messageMatchesTargetLanguage(text, clientLanguage) ? null : clientLanguage;
    }

    if (isLikelyRussian(text)) {
        return 'kk';
    }

    if (isLikelyKazakh(text)) {
        return 'ru';
    }

    if (isLikelyEnglish(text)) {
        return 'ru';
    }

    return null;
}

function fallbackDraftTargetFromUiLocale(uiLocale: AppLocale): MessageLanguageTarget {
    if (uiLocale === 'ru') {
        return 'kk';
    }

    if (uiLocale === 'kk') {
        return 'ru';
    }

    return 'ru';
}

/** Всегда возвращает целевой язык для ручного перевода черновика. */
export function resolveDraftTranslationTarget(
    draft: string | null | undefined,
    clientLanguage: MessageLanguageTarget | null,
    uiLocale: AppLocale,
): MessageLanguageTarget | null {
    const text = sampleMessageText(draft);
    if (letterCount(text) < MIN_SAMPLE_LEN) {
        return null;
    }

    const resolved = resolveOutgoingTargetLanguage(text, clientLanguage);
    if (resolved !== null) {
        return resolved;
    }

    return fallbackDraftTargetFromUiLocale(uiLocale);
}
