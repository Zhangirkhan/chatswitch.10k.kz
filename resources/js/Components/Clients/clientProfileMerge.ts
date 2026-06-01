import type { ClientSummary } from '@/Components/AiChat/aiWorkspaceTypes';
import type { ClientProfile, ClientProfileField, ClientProfileSection } from '@/Components/Clients/clientProfileTypes';

/** Narrative AI blocks stay in the right panel only — not duplicated as CRM rows. */
const SUMMARY_TO_SECTION: Record<string, string> = {
    'предпочтения': 'tasks_notes',
    'контекст и локация': 'contacts',
    'договорённости': 'tasks_notes',
};

function normalizeValue(value: string): string {
    return value.trim().toLowerCase().replace(/\s+/g, ' ');
}

function labelsSameConcept(left: string, right: string): boolean {
    const groups = [
        [/имя|name|как обращ|клиент|кто это/i, /имя|name|как обращ|клиент|кто это/i],
        [/адрес|address|локац|location/i, /адрес|address|локац|location/i],
        [/этап|воронк|сделк|stage|funnel|следующ/i, /этап|воронк|сделк|stage|funnel|следующ/i],
    ];

    return groups.some(([a, b]) => a.test(left) && b.test(right));
}

function valuesOverlap(left: string, right: string): boolean {
    if (left === right) {
        return true;
    }

    if (left.length >= 4 && right.length >= 4) {
        return left.includes(right) || right.includes(left);
    }

    return false;
}

function isEmptyAiBody(body: string): boolean {
    const normalized = body.trim().toLowerCase();

    return normalized === ''
        || normalized === '—'
        || normalized.includes('нет данных');
}

function fieldIsDuplicate(fields: ClientProfileField[], label: string, value: string): boolean {
    const normalizedValue = normalizeValue(value);

    return fields.some((field) => {
        const existingLabel = field.label.trim();
        const existingValue = normalizeValue(field.value);

        if (existingLabel === label || existingValue === normalizedValue) {
            return true;
        }

        if (valuesOverlap(existingValue, normalizedValue)) {
            return true;
        }

        return labelsSameConcept(existingLabel, label);
    });
}

function summarySectionKey(title: string): string | null {
    const normalized = title.trim().toLowerCase();

    return SUMMARY_TO_SECTION[normalized] ?? null;
}

export function mergeSummaryIntoProfile(
    profile: ClientProfile | null,
    summary: ClientSummary | null,
): ClientProfile | null {
    if (!profile || !summary?.ai?.sections?.length) {
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
        const label = aiSection.title.trim();

        if (fieldIsDuplicate(fields, label, body)) {
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
