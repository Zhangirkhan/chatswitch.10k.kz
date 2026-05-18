import { describe, expect, it } from 'vitest';
import { stageInlineHints, stageRuleIssues } from './funnelStageHints';

describe('funnelStageHints', () => {
    it('flags missing rule fields', () => {
        const issues = stageRuleIssues(
            {
                goal: '',
                transition_conditions: '',
                required_questions: [],
                allowed_actions: ['reply_customer'],
            },
            0,
            3,
        );

        expect(issues).toContain('Нет цели этапа');
        expect(issues).toContain('Нет условий перехода');
        expect(issues).toContain('Нет уточняющих вопросов');
    });

    it('shows inline hints for missing questions and transition', () => {
        const hints = stageInlineHints(
            {
                goal: 'Понять запрос',
                transition_conditions: '',
                required_questions: [],
                allowed_actions: ['reply_customer', 'move_funnel_stage'],
            },
            'lead',
            0,
            3,
        );

        expect(hints.some((h) => h.id === 'transition')).toBe(true);
        expect(hints.some((h) => h.id === 'questions')).toBe(true);
    });

    it('shows success coach tip when stage is complete', () => {
        const hints = stageInlineHints(
            {
                goal: 'Согласовать оплату',
                transition_conditions: 'Клиент оплатил',
                required_questions: ['Когда удобно оплатить?', 'Нужны реквизиты?'],
                allowed_actions: ['reply_customer', 'move_funnel_stage', 'create_task'],
                follow_up_enabled: true,
            },
            'payment',
            1,
            3,
        );

        expect(hints).toHaveLength(1);
        expect(hints[0]?.tone).toBe('success');
    });
});
