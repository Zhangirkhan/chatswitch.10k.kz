import kzLetters from '../../locale/lexicons/kz_letters.json';

const KAZAKH_LETTERS = /[әғқңөұүһіӘҒҚҢӨҰҮҺІ]/u;

/** Keep in sync with App\\Support\\KazakhInformalTextDetector::PLAIN_MARKERS */
const PLAIN_MARKERS: readonly string[] = [
    "канша",
    "турады",
    "қанша",
    "тұрады",
    "неше",
    "қандай",
    "кайда",
    "қайда",
    "неге",
    "керек",
    "барма",
    "жоқ",
    "жок",
    "рахмет",
    "кешір",
    "кешир",
    "кешіріңіз",
    "саламат",
    "сәлемет",
    "салемет",
    "салеметсизбе",
    "салем",
    "ассалаума",
    "assalaum",
    "assalamu",
    "assalauma",
    "магалайкум",
    "magalaykum",
    "мағалайкум",
    "жақсы",
    "жаксы",
    "қазір",
    "кайта",
    "келес",
    "жібер",
    "jiber",
    "zhiber",
    "qalay",
    "qalaysyn",
    "калай",
    "калайсын",
    "turady",
    "qansha",
    "bagasy",
    "бағасы",
    "nege",
    "salem",
    "salam",
    "rahmet",
    "ия",
    "иа",
    "емес",
];

/** Keep in sync with App\\Support\\KazakhInformalTextDetector::HIGH_CONFIDENCE_FUNCTION_WORDS */
const HIGH_CONFIDENCE_FUNCTION_WORDS: readonly string[] = [
    "жоқ",
    "жок",
    "керек",
    "емес",
    "барма",
    "ия",
    "иа",
    "рахмет",
    "сәлем",
    "салам",
    "салемет",
    "сәлемет",
    "ассалаума",
    "мағалайкум",
    "магалайкум",
    "қанша",
    "канша",
    "тұрады",
    "турады",
    "қалай",
    "калай",
    "неше",
];

const TRANSLIT_WORDS: readonly string[] = (kzLetters.translit_kk_words ?? []).map((word) => word.toLowerCase());

function containsWord(haystack: string, word: string): boolean {
    if (word === '') {
        return false;
    }

    if (haystack.includes(word)) {
        return true;
    }

    const pattern = new RegExp(`(?<!\\p{L})${word.replace(/[.*+?^${}()|[\\]\\]/g, '\\$&')}(?!\\p{L})`, 'u');

    return pattern.test(haystack);
}

export function matchesKazakhInformalText(text: string): boolean {
    const trimmed = text.trim();
    if (trimmed === '') {
        return false;
    }

    if (KAZAKH_LETTERS.test(trimmed)) {
        return true;
    }

    const lower = trimmed.toLowerCase();

    for (const marker of PLAIN_MARKERS) {
        if (marker !== '' && lower.includes(marker)) {
            return true;
        }
    }

    for (const word of HIGH_CONFIDENCE_FUNCTION_WORDS) {
        if (word !== '' && containsWord(lower, word)) {
            return true;
        }
    }

    for (const word of TRANSLIT_WORDS) {
        if (word !== '' && containsWord(lower, word)) {
            return true;
        }
    }

    return false;
}
