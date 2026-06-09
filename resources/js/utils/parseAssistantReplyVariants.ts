export type AssistantReplyVariant = {
    index: number;
    label: string;
    text: string;
};

export type ParsedAssistantReply = {
    intro: string;
    variants: AssistantReplyVariant[];
};

const VARIANT_HEADER_RE =
    /(?:^|[\n\r]+)\s*(?:[-•*]\s*)?(?:\*{1,2})?\s*(?:вариант|variant|option|нұсқа)\s*(\d+|[a-zа-я])\s*[:—.]?\s*(?:\*{1,2})?\s*/giu;

function stripOuterQuotes(text: string): string {
    let result = text.trim();

    const pairs: Array<[string, string]> = [
        ['«', '»'],
        ['"', '"'],
        ["'", "'"],
        ['„', '"'],
    ];

    for (const [open, close] of pairs) {
        if (result.startsWith(open) && result.endsWith(close)) {
            result = result.slice(open.length, -close.length).trim();
            break;
        }
    }

    return result.replace(/^[«»"'""]+|[«»"'""]+$/gu, '').trim();
}

function extractVariantText(raw: string): string {
    const text = raw.trim();
    const guillemet = text.match(/^«([^»]+)»/u);

    if (guillemet) {
        return guillemet[1].trim();
    }

    const quoted = text.match(/^["']([^"']+)["']/u);

    if (quoted) {
        return quoted[1].trim();
    }

    return stripOuterQuotes(text);
}

export function parseAssistantReplyVariants(content: string): ParsedAssistantReply | null {
    const normalized = content.replace(/\r\n/g, '\n').replace(/\r/g, '\n').trim();
    if (normalized === '') {
        return null;
    }

    const matches = [...normalized.matchAll(VARIANT_HEADER_RE)];
    if (matches.length === 0) {
        return null;
    }

    const firstIndex = matches[0].index ?? 0;
    const intro = normalized.slice(0, firstIndex).trim();
    const variants: AssistantReplyVariant[] = [];

    for (let i = 0; i < matches.length; i++) {
        const match = matches[i];
        const start = (match.index ?? 0) + match[0].length;
        const end = i + 1 < matches.length ? (matches[i + 1].index ?? normalized.length) : normalized.length;
        const raw = normalized.slice(start, end).trim();
        const line = raw.split('\n')[0]?.trim() ?? '';
        const text = extractVariantText(line);

        if (text !== '') {
            variants.push({
                index: variants.length + 1,
                label: String(match[1]),
                text,
            });
        }
    }

    if (variants.length === 0) {
        return null;
    }

    return { intro, variants };
}

export function parsedReplyFromApi(payload: {
    reply_intro?: string | null;
    reply_variants?: Array<{ label?: string; text?: string }> | null;
} | null | undefined): ParsedAssistantReply | null {
    const variants = (payload?.reply_variants ?? [])
        .map((variant, index) => {
            const text = String(variant.text ?? '').trim();
            if (text === '') {
                return null;
            }

            return {
                index: index + 1,
                label: String(variant.label ?? index + 1),
                text,
            };
        })
        .filter((variant): variant is AssistantReplyVariant => variant !== null);

    if (variants.length === 0) {
        return null;
    }

    return {
        intro: String(payload?.reply_intro ?? '').trim(),
        variants,
    };
}
