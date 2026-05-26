const FLOWCHART_HEADER = /^(flowchart|graph)\s+/i;
const EDGE_SPLIT =
    /(\s*(?:-->|---|-\.->|<-->|<--)\s*(?:\|[^|\n]*\|\s*)?)/;

export function stripMermaidFences(code: string): string {
    let normalized = code.trim();

    if (!normalized.startsWith('```')) {
        return normalized;
    }

    normalized = normalized.replace(/^```(?:mermaid)?\s*\n?/i, '');
    normalized = normalized.replace(/\n?```\s*$/, '');

    return normalized.trim();
}

function repairNodeToken(token: string, cache: Map<string, string>): string {
    const trimmed = token.trim();
    if (trimmed === '') {
        return token;
    }

    if (/^subgraph\s+/i.test(trimmed)) {
        const title = trimmed.replace(/^subgraph\s+/i, '').trim();
        if (title === '' || /^["']/.test(title) || /^[a-zA-Z_][\w]*$/.test(title)) {
            return trimmed;
        }

        return `subgraph "${title.replace(/\\/g, '\\\\').replace(/"/g, '\\"')}"`;
    }

    if (
        trimmed === 'end' ||
        trimmed.startsWith('classDef ') ||
        trimmed.startsWith('class ') ||
        trimmed.startsWith('linkStyle ') ||
        trimmed.startsWith('style ')
    ) {
        return trimmed;
    }

    if (/^[\w\u0400-\u04FF_][\w\u0400-\u04FF0-9_]*(\[\[|\[\(|\[\{|[([]|[({])/u.test(trimmed)) {
        return trimmed;
    }

    if (/^["']/.test(trimmed)) {
        return trimmed;
    }

    if (/^[a-zA-Z_][\w]*$/u.test(trimmed)) {
        return trimmed;
    }

    if (!cache.has(trimmed)) {
        cache.set(trimmed, `n${cache.size + 1}`);
    }

    const id = cache.get(trimmed)!;
    const label = trimmed.replace(/\\/g, '\\\\').replace(/"/g, '\\"');

    return `${id}["${label}"]`;
}

function repairFlowchartLine(line: string, cache: Map<string, string>): string {
    if (!EDGE_SPLIT.test(line)) {
        const trimmed = line.trim();
        if (trimmed.startsWith('subgraph ')) {
            const indent = line.match(/^\s*/)?.[0] ?? '';
            return indent + repairNodeToken(trimmed, cache);
        }

        return line;
    }

    const indent = line.match(/^\s*/)?.[0] ?? '';
    const body = line.slice(indent.length);
    const parts = body.split(EDGE_SPLIT);

    const repaired = parts
        .map((part, index) => (index % 2 === 1 ? part : repairNodeToken(part, cache)))
        .join('');

    return indent + repaired;
}

export function repairMermaidCode(code: string): string {
    const stripped = stripMermaidFences(code);
    const lines = stripped.split(/\r?\n/);
    if (lines.length === 0 || !FLOWCHART_HEADER.test(lines[0].trim())) {
        return stripped;
    }

    const cache = new Map<string, string>();

    return lines
        .map((line, index) => {
            if (index === 0 || line.trim() === '' || line.trim().startsWith('%%')) {
                return line;
            }

            return repairFlowchartLine(line, cache);
        })
        .join('\n');
}
