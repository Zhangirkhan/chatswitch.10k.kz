export const FUNNEL_STAGE_TYPES = [
    { value: 'lead', label: 'Лид' },
    { value: 'qualification', label: 'Квалификация' },
    { value: 'offer', label: 'Предложение' },
    { value: 'payment', label: 'Оплата' },
    { value: 'production', label: 'В работе' },
    { value: 'delivery', label: 'Доставка' },
    { value: 'done', label: 'Закрыто' },
    { value: 'other', label: 'Другое' },
] as const;

export type FunnelStageTypeValue = (typeof FUNNEL_STAGE_TYPES)[number]['value'];

export function funnelStageTypeLabel(type: string | null | undefined): string {
    return FUNNEL_STAGE_TYPES.find((row) => row.value === type)?.label ?? 'Другое';
}

export function normalizeStageType(type: string | null | undefined): FunnelStageTypeValue {
    if (FUNNEL_STAGE_TYPES.some((row) => row.value === type)) {
        return type as FunnelStageTypeValue;
    }

    return 'other';
}

export function guessStageTypeFromName(name: string): FunnelStageTypeValue {
    const n = name.trim().toLowerCase();

    if (!n) {
        return 'other';
    }

    if (['закрыт', 'успеш', 'выполнен', 'заверш', 'done', 'won'].some((k) => n.includes(k))) {
        return 'done';
    }
    if (['доставк', 'монтаж', 'выдач', 'установк', 'отгруз'].some((k) => n.includes(k))) {
        return 'delivery';
    }
    if (['производ', 'изготов', 'сборк', 'ремонт', 'в работе'].some((k) => n.includes(k))) {
        return 'production';
    }
    if (['оплат', 'предоплат', 'договор', 'счёт', 'счет', 'invoice', 'payment'].some((k) => n.includes(k))) {
        return 'payment';
    }
    if (['кп', 'предложен', 'проект', 'расчёт', 'расчет', 'смет', 'подбор', 'offer'].some((k) => n.includes(k))) {
        return 'offer';
    }
    if (['квалиф', 'консульт', 'диагност', 'замер', 'бриф', 'созвон', 'запись', 'приём', 'прием'].some((k) => n.includes(k))) {
        return 'qualification';
    }
    if (['лид', 'заявк', 'первичн', 'новый', 'интерес', 'обращен', 'входящ', 'lead'].some((k) => n.includes(k))) {
        return 'lead';
    }

    return 'other';
}
