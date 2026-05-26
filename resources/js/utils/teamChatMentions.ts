export type TeamMentionCandidate = { id: number; name: string };

/** Извлекает id упомянутых участников из текста (@Имя). */
export function extractTeamMentionIdsFromBody(
    body: string,
    candidates: TeamMentionCandidate[],
): number[] {
    const users = candidates
        .map((c) => ({ id: c.id, name: c.name.trim() }))
        .filter((u) => u.name !== '');
    if (!body || users.length === 0) {
        return [];
    }

    const sorted = [...users].sort((a, b) => b.name.length - a.name.length);
    const ids: number[] = [];
    const seen = new Set<number>();
    let i = 0;

    while (i < body.length) {
        if (body[i] === '@') {
            let matched: { id: number; name: string } | null = null;
            for (const u of sorted) {
                if (body.startsWith(u.name, i + 1)) {
                    const after = i + 1 + u.name.length;
                    const ch = body[after];
                    if (ch !== undefined && /[\p{L}\p{N}_]/u.test(ch)) {
                        continue;
                    }
                    matched = u;
                    break;
                }
            }
            if (matched && !seen.has(matched.id)) {
                seen.add(matched.id);
                ids.push(matched.id);
                i += 1 + matched.name.length;
                continue;
            }
        }
        i += 1;
    }

    return ids.slice(0, 20);
}
