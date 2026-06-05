export type AssistantReplyVariant = {
    index: number;
    label: string;
    text: string;
};

export type ParsedAssistantReply = {
    intro: string;
    variants: AssistantReplyVariant[];
};

const VARIANT_LINE_RE =
    /^\s*(?:\*{1,2})?\s*(?:вариант|variant|option|нұсқа)\s*(\d+|[a-zа-я])\s*:?\s*(?:\*{1,2})?\s*(.*)$/iu;

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
    if (!content.trim()) {
        return null;
    }

    const lines = content.split('\n');
    const variants: AssistantReplyVariant[] = [];
    const introLines: string[] = [];
    let seenVariant = false;

    for (const line of lines) {
        const match = line.match(VARIANT_LINE_RE);

        if (match) {
            seenVariant = true;
            const text = extractVariantText(match[2]);

            if (text !== '') {
                variants.push({
                    index: variants.length + 1,
                    label: String(match[1]),
                    text,
                });
            }
        } else if (!seenVariant) {
            introLines.push(line);
        }
    }

    if (variants.length === 0) {
        return null;
    }

    return {
        intro: introLines.join('\n').trim(),
        variants,
    };
}
