import type { ClientSummary } from '@/Components/AiChat/aiWorkspaceTypes';
import type { ClientProfile, ClientProfileField, ClientProfileSection } from '@/Components/Clients/clientProfileTypes';

function isEmptyAiBody(body: string): boolean {
    const normalized = body.trim().toLowerCase();

    return normalized === ''
        || normalized === '—'
        || normalized.includes('нет данных');
}

function summarySectionKey(title: string): string | null {
    const titleLower = title.toLowerCase();

    if (/кто|профил|клиент|identity|who/.test(titleLower)) {
        return 'basic';
    }
    if (/предпочт|preferen|вкус|стиль/.test(titleLower)) {
        return 'tasks_notes';
    }
    if (/контекст|локац|context|location|адрес|ситуац/.test(titleLower)) {
        return 'contacts';
    }
    if (/договор|согласован|agreement|обещан|обязатель|нюанс/.test(titleLower)) {
        return 'tasks_notes';
    }
    if (/сделк|этап|следующ|шаг|deal|stage|next|воронк/.test(titleLower)) {
        return 'basic';
    }

    return 'basic';
}

function fieldExists(fields: ClientProfileField[], label: string, value: string): boolean {
    return fields.some((field) => field.label === label || field.value.trim() === value.trim());
}

export function mergeSummaryIntoProfile(
    profile: ClientProfile | null,
    summary: ClientSummary | null,
): ClientProfile | null {
    if (!profile) {
        return null;
    }

    if (!summary?.ai?.sections?.length) {
        return profile;
    }

    const sections: ClientProfileSection[] = profile.sections.map((section) => ({
        ...section,
        fields: [...(section.fields ?? [])],
    }));

    for (const aiSection of summary.ai.sections) {
        const body = aiSection.body?.trim() ?? '';
        if (isEmptyAiBody(body)) {
            continue;
        }

        const key = summarySectionKey(aiSection.title);
        if (!key) {
            continue;
        }

        const index = sections.findIndex((section) => section.key === key);
        if (index === -1) {
            continue;
        }

        const fields = sections[index].fields ?? [];
        const label = aiSection.title.trim() || 'Из переписки';
        if (fieldExists(fields, label, body)) {
            continue;
        }

        fields.push({
            label,
            value: body,
            source: 'ai',
        });
        sections[index] = { ...sections[index], fields };
    }

    return {
        ...profile,
        sections,
    };
}
