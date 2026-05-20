export type StageHintTone = 'tip' | 'warn' | 'success';

export type StageHint = {
    id: string;
    text: string;
    tone: StageHintTone;
};

export type StageAiRuleLike = {
    goal?: string | null;
    required_questions?: string[] | null;
    transition_conditions?: string | null;
    allowed_actions?: string[] | null;
    assignee_department_id?: number | null;
    assignee_user_ids?: number[] | null;
    follow_up_enabled?: boolean;
} | null | undefined;

export function stageRuleIssues(
    rule: StageAiRuleLike,
    index = 0,
    total = 0,
    stageType?: string | null,
): string[] {
    if (!rule) {
        return ['Нет AI-правил'];
    }

    const issues: string[] = [];
    const goal = (rule.goal || '').trim();
    const conditions = (rule.transition_conditions || '').trim();
    const actions = rule.allowed_actions ?? [];
    const questions = rule.required_questions ?? [];
    const isFinalStage = total > 0 && index >= total - 1;

    if (!goal) {
        issues.push('Нет цели этапа');
    }
    if (!conditions) {
        issues.push('Нет условий перехода');
    }
    if (actions.length === 0) {
        issues.push('Нет разрешённых действий');
    }
    const skipQuestionsCheck = stageType === 'production' || stageType === 'done';
    if (!isFinalStage && questions.length === 0 && !skipQuestionsCheck) {
        issues.push('Нет уточняющих вопросов');
    }
    if (
        (actions.includes('create_appointment') || actions.includes('assign_employee'))
        && !rule.assignee_department_id
        && (rule.assignee_user_ids ?? []).length === 0
    ) {
        issues.push('Не указан отдел/исполнитель');
    }

    return issues;
}

export function stageTypeCoachTip(stageType: string | null | undefined): string {
    const tips: Record<string, string> = {
        lead: 'На этапе лида AI должен быстро понять запрос и не задавать лишних вопросов.',
        qualification: 'Зафиксируйте дату, формат и контакт — так меньше ручных передач менеджеру.',
        offer: 'AI не должен обещать цену или срок без данных из базы знаний и каталога.',
        payment: 'Для оплаты полезны действия «задача менеджеру» и уведомление ответственного.',
        production: 'На этапе работ информируйте о статусе без выдуманных дат готовности.',
        delivery: 'Согласуйте время, адрес и контакт на месте — это снижает срывы доставки.',
        done: 'Финальный этап: поблагодарить клиента и не инициировать лишние касания.',
    };

    return tips[stageType ?? ''] ?? 'Правила этапа заполнены — AI может вести диалог по этому шагу.';
}

export function stageInlineHints(
    rule: StageAiRuleLike,
    stageType: string | null | undefined,
    index = 0,
    total = 0,
): StageHint[] {
    if (!rule) {
        return [
            {
                id: 'no-rule',
                text: 'Настройте AI-правила этапа — без цели и условий перехода оркестратор не понимает, что делать на этом шаге.',
                tone: 'warn',
            },
        ];
    }

    const issues = new Set(stageRuleIssues(rule, index, total, stageType));
    const hints: StageHint[] = [];
    const questions = rule.required_questions ?? [];
    const isFinal = total > 0 && index >= total - 1;

    if (issues.has('Нет цели этапа')) {
        hints.push({
            id: 'goal',
            text: 'Цель этапа: что AI должен добиться здесь (одно короткое предложение).',
            tone: 'warn',
        });
    }

    if (issues.has('Нет условий перехода')) {
        hints.push({
            id: 'transition',
            text: 'Условие перехода: когда переводить сделку дальше (например: «клиент подтвердил дату замера»).',
            tone: 'warn',
        });
    }

    if (issues.has('Нет уточняющих вопросов')) {
        hints.push({
            id: 'questions',
            text: 'Добавьте 2–3 коротких вопроса — AI задаст их, если в переписке ещё нет ответов.',
            tone: 'warn',
        });
    } else if (!isFinal && questions.length > 0 && questions.length < 2) {
        hints.push({
            id: 'questions-more',
            text: 'Лучше 2–3 вопроса на этап: AI сможет закрыть типовые пробелы без догадок.',
            tone: 'tip',
        });
    }

    if (issues.has('Не указан отдел/исполнитель')) {
        hints.push({
            id: 'assignee',
            text: 'Укажите отдел или исполнителя для записи/назначения — иначе AI не сможет создать задачу.',
            tone: 'warn',
        });
    }

    if (issues.has('Нет разрешённых действий')) {
        hints.push({
            id: 'actions',
            text: 'Разрешите хотя бы ответ клиенту и переход этапа — иначе AI не сможет действовать.',
            tone: 'warn',
        });
    }

    if (!rule.follow_up_enabled && (stageType === 'qualification' || stageType === 'offer' || stageType === 'payment')) {
        hints.push({
            id: 'follow-up',
            text: 'Рассмотрите авто follow-up, если клиент часто «думает» на этом этапе.',
            tone: 'tip',
        });
    }

    if (hints.length === 0) {
        hints.push({
            id: 'ok',
            text: stageTypeCoachTip(stageType),
            tone: 'success',
        });
    }

    return hints.slice(0, 3);
}

export function stageHintToneStyle(tone: StageHintTone): { color: string; background: string; border: string } {
    if (tone === 'success') {
        return {
            color: '#15803d',
            background: 'rgba(22, 163, 74, 0.1)',
            border: 'rgba(22, 163, 74, 0.28)',
        };
    }
    if (tone === 'warn') {
        return {
            color: '#b45309',
            background: 'rgba(217, 119, 6, 0.1)',
            border: 'rgba(217, 119, 6, 0.28)',
        };
    }

    return {
        color: 'var(--ui-accent)',
        background: 'var(--ui-accent-soft)',
        border: 'var(--ui-accent-border)',
    };
}
