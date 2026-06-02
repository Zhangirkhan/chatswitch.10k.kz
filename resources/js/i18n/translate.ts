import type { MessageCatalog, MessageKey } from './types';

function resolvePath(catalog: MessageCatalog, key: string): string | undefined {
    const parts = key.split('.');
    let current: unknown = catalog;

    for (const part of parts) {
        if (!current || typeof current !== 'object') {
            return undefined;
        }
        current = (current as Record<string, unknown>)[part];
    }

    return typeof current === 'string' ? current : undefined;
}

export function translate(
    catalog: MessageCatalog,
    key: MessageKey | string,
    params?: Record<string, string | number>,
): string {
    let text = resolvePath(catalog, key) ?? key;

    if (params) {
        for (const [name, value] of Object.entries(params)) {
            text = text.replaceAll(`{${name}}`, String(value));
        }
    }

    return text;
}
