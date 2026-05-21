export function initialsFromName(name?: string | null, fallback = '?'): string {
    const source = String(name ?? '').trim();
    if (!source) {
        return fallback;
    }

    const words = source
        .split(/[\s._@-]+/u)
        .map((word) => word.trim())
        .filter(Boolean);

    if (words.length === 0) {
        return fallback;
    }

    const letters = words.length === 1
        ? Array.from(words[0]).slice(0, 2)
        : words.slice(0, 2).map((word) => Array.from(word)[0]).filter(Boolean);

    return (letters.join('') || fallback).toLocaleUpperCase('ru-RU');
}
