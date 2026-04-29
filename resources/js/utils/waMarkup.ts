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

function escapeAttr(input: string): string {
    // Input is already HTML-escaped in renderWaMarkup(), but href/title attributes
    // need quotes escaping too (defense in depth).
    return input.replace(/"/g, '&quot;');
}

function normalizeUrl(raw: string): string {
    const v = raw.trim();
    if (/^https?:\/\//i.test(v)) return v;
    if (/^www\./i.test(v)) return `https://${v}`;
    return v;
}

function linkifyHtmlOutsideCode(html: string): string {
    // Keep links out of <code>...</code> blocks (inline/backticks and triple).
    const parts = html.split(/(<code>[\s\S]*?<\/code>)/g);
    return parts
        .map((part) => {
            if (part.startsWith('<code>') && part.endsWith('</code>')) {
                return part;
            }

            // URLs (http(s):// or www.) – exclude quotes and tag delimiters.
            const urlRe = /((?:https?:\/\/|www\.)[^\s<>"']+)/gi;
            return part.replace(urlRe, (m: string) => {
                // Trim common trailing punctuation that shouldn't be part of URL.
                let url = m;
                let trailing = '';
                while (/[)\],.!?:;]$/.test(url)) {
                    trailing = url.slice(-1) + trailing;
                    url = url.slice(0, -1);
                }
                const href = normalizeUrl(url);
                const safeHref = escapeAttr(href);
                const label = url; // already escaped text
                return `<a class="wa-link" href="${safeHref}" target="_blank" rel="noopener noreferrer nofollow">${label}</a>${trailing}`;
            });
        })
        .join('');
}

export function renderWaMarkup(input: string | null | undefined): string {
    if (input == null) return '';
    const escaped = escapeHtml(String(input));

    const withMarkup = PATTERNS.reduce((acc, { re, tag }) => {
        return acc.replace(re, (_m, p1: string | undefined, p2: string | undefined) => {
            if (p2 === undefined) {
                return `<${tag}>${p1}</${tag}>`;
            }
            return `${p1 ?? ''}<${tag}>${p2}</${tag}>`;
        });
    }, escaped);

    return linkifyHtmlOutsideCode(withMarkup);
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
