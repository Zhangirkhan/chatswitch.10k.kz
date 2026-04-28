/**
 * Конвертер WhatsApp-разметки в безопасный HTML.
 *
 * Поддерживается подмножество синтаксиса WhatsApp:
 *   *bold*      → <strong>bold</strong>
 *   _italic_    → <em>italic</em>
 *   ~strike~    → <s>strike</s>
 *   ```code```  → <code>code</code>
 *   `code`      → <code>code</code>
 *
 * Это нужно, в частности, для подписи оператора в исходящих сообщениях
 * (серверно она приходит как `*Имя (Должность)*\nтекст`), чтобы в интерфейсе
 * подпись рендерилась жирным, а не звёздочками.
 *
 * ВАЖНО: сначала экранируем HTML, потом применяем разметку — поэтому использование
 * результата через `v-html` безопасно от XSS.
 */

const ESCAPE_MAP: Record<string, string> = {
    '&': '&amp;',
    '<': '&lt;',
    '>': '&gt;',
    '"': '&quot;',
    "'": '&#39;',
};

function escapeHtml(input: string): string {
    return input.replace(/[&<>"']/g, (c) => ESCAPE_MAP[c] ?? c);
}

/**
 * Регулярки специально требуют «слова» внутри маркера (не пробел в начале/конце) —
 * чтобы случайные одиночные звёздочки или подчёркивания в тексте не ломали рендер.
 */
const PATTERNS: Array<{ re: RegExp; tag: string }> = [
    { re: /```([\s\S]+?)```/g, tag: 'code' },
    { re: /(^|[\s(])\*(\S(?:[^*\n]*\S)?)\*(?=[\s.,!?)]|$)/g, tag: 'strong' },
    { re: /(^|[\s(])_(\S(?:[^_\n]*\S)?)_(?=[\s.,!?)]|$)/g, tag: 'em' },
    { re: /(^|[\s(])~(\S(?:[^~\n]*\S)?)~(?=[\s.,!?)]|$)/g, tag: 's' },
    { re: /`([^`\n]+?)`/g, tag: 'code' },
];

export function renderWaMarkup(input: string | null | undefined): string {
    if (input == null) return '';
    const escaped = escapeHtml(String(input));

    return PATTERNS.reduce((acc, { re, tag }) => {
        return acc.replace(re, (_m, p1: string | undefined, p2: string | undefined) => {
            if (p2 === undefined) {
                return `<${tag}>${p1}</${tag}>`;
            }
            return `${p1 ?? ''}<${tag}>${p2}</${tag}>`;
        });
    }, escaped);
}

/**
 * Плоский вариант для коротких превью (например, последнее сообщение в списке чатов):
 * удаляет маркеры, оставляя «голый» текст.
 */
export function stripWaMarkup(input: string | null | undefined): string {
    if (input == null) return '';
    return String(input)
        .replace(/```([\s\S]+?)```/g, '$1')
        .replace(/(^|[\s(])\*(\S(?:[^*\n]*\S)?)\*(?=[\s.,!?)]|$)/g, '$1$2')
        .replace(/(^|[\s(])_(\S(?:[^_\n]*\S)?)_(?=[\s.,!?)]|$)/g, '$1$2')
        .replace(/(^|[\s(])~(\S(?:[^~\n]*\S)?)~(?=[\s.,!?)]|$)/g, '$1$2')
        .replace(/`([^`\n]+?)`/g, '$1');
}
