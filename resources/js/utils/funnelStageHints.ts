export type StageHintTone = 'tip' | 'warn' | 'success';

export type StageRuleIssueId =
    | 'noRules'
    | 'noGoal'
    | 'noTransition'
    | 'noActions'
    | 'noQuestions'
    | 'noAssignee';

export type StageHintId =
    | 'noRule'
    | 'goal'
    | 'transition'
    | 'questions'
    | 'questionsMore'
    | 'assignee'
    | 'actions'
    | 'followUp'
    | 'ok';

export type StageCoachTipId = 'lead' | 'qualification' | 'offer' | 'payment' | 'production' | 'delivery' | 'done' | 'default';

export type StageHint = {
    id: string;
    textKey: string;
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
): StageRuleIssueId[] {
    if (!rule) {
        return ['noRules'];
    }

    const issues: StageRuleIssueId[] = [];
    const goal = (rule.goal || '').trim();
    const conditions = (rule.transition_conditions || '').trim();
    const actions = rule.allowed_actions ?? [];
    const questions = rule.required_questions ?? [];
    const isFinalStage = total > 0 && index >= total - 1;

    if (!goal) {
        issues.push('noGoal');
    }
    if (!conditions) {
        issues.push('noTransition');
    }
    if (actions.length === 0) {
        issues.push('noActions');
    }
    const skipQuestionsCheck = stageType === 'production' || stageType === 'done';
    if (!isFinalStage && questions.length === 0 && !skipQuestionsCheck) {
        issues.push('noQuestions');
    }
    if (
        (actions.includes('create_appointment') || actions.includes('assign_employee'))
        && !rule.assignee_department_id
        && (rule.assignee_user_ids ?? []).length === 0
    ) {
        issues.push('noAssignee');
    }

    return issues;
}

export function stageCoachTipId(stageType: string | null | undefined): StageCoachTipId {
    const map: Record<string, StageCoachTipId> = {
        lead: 'lead',
        qualification: 'qualification',
        offer: 'offer',
        payment: 'payment',
        production: 'production',
        delivery: 'delivery',
        done: 'done',
    };

    return map[stageType ?? ''] ?? 'default';
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
                id: 'noRule',
                textKey: 'settings.funnels.hints.noRule',
                tone: 'warn',
            },
        ];
    }

    const issues = new Set(stageRuleIssues(rule, index, total, stageType));
    const hints: StageHint[] = [];
    const questions = rule.required_questions ?? [];
    const isFinal = total > 0 && index >= total - 1;

    if (issues.has('noGoal')) {
        hints.push({
            id: 'goal',
            textKey: 'settings.funnels.hints.goal',
            tone: 'warn',
        });
    }

    if (issues.has('noTransition')) {
        hints.push({
            id: 'transition',
            textKey: 'settings.funnels.hints.transition',
            tone: 'warn',
        });
    }

    if (issues.has('noQuestions')) {
        hints.push({
            id: 'questions',
            textKey: 'settings.funnels.hints.questions',
            tone: 'warn',
        });
    } else if (!isFinal && questions.length > 0 && questions.length < 2) {
        hints.push({
            id: 'questionsMore',
            textKey: 'settings.funnels.hints.questionsMore',
            tone: 'tip',
        });
    }

    if (issues.has('noAssignee')) {
        hints.push({
            id: 'assignee',
            textKey: 'settings.funnels.hints.assignee',
            tone: 'warn',
        });
    }

    if (issues.has('noActions')) {
        hints.push({
            id: 'actions',
            textKey: 'settings.funnels.hints.actions',
            tone: 'warn',
        });
    }

    if (!rule.follow_up_enabled && (stageType === 'qualification' || stageType === 'offer' || stageType === 'payment')) {
        hints.push({
            id: 'followUp',
            textKey: 'settings.funnels.hints.followUp',
            tone: 'tip',
        });
    }

    if (hints.length === 0) {
        hints.push({
            id: 'ok',
            textKey: `settings.funnels.coachTips.${stageCoachTipId(stageType)}`,
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
