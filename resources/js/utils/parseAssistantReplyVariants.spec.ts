import { describe, expect, it } from 'vitest';
import { parseAssistantReplyVariants, parsedReplyFromApi } from './parseAssistantReplyVariants';

describe('parseAssistantReplyVariants', () => {
    it('parses Russian variants with guillemets', () => {
        const content = [
            'Клиент поздоровался — ответьте нейтрально.',
            'Вариант 1: «Здравствуйте! На связи.»',
            'Вариант 2: «Здравствуйте! Чем могу помочь?»',
        ].join('\n');

        const parsed = parseAssistantReplyVariants(content);

        expect(parsed).not.toBeNull();
        expect(parsed?.intro).toContain('Клиент поздоровался');
        expect(parsed?.variants).toHaveLength(2);
        expect(parsed?.variants[0].text).toBe('Здравствуйте! На связи.');
        expect(parsed?.variants[1].text).toBe('Здравствуйте! Чем могу помочь?');
    });

    it('parses English variants without quotes', () => {
        const content = 'Option 1: Hello! We are online.\nOption 2: Hello! How can I help?';

        const parsed = parseAssistantReplyVariants(content);

        expect(parsed?.variants).toHaveLength(2);
        expect(parsed?.variants[0].text).toBe('Hello! We are online.');
    });

    it('returns null when no variants are found', () => {
        expect(parseAssistantReplyVariants('Просто текст без вариантов.')).toBeNull();
    });

    it('parses list-prefixed variants', () => {
        const content = '- **Вариант 1:** «Hello»\n- **Вариант 2:** «Hi»';
        const parsed = parseAssistantReplyVariants(content);

        expect(parsed?.variants).toHaveLength(2);
        expect(parsed?.variants[0].text).toBe('Hello');
    });

    it('uses API payload when provided', () => {
        const parsed = parsedReplyFromApi({
            reply_intro: 'Intro',
            reply_variants: [
                { label: '1', text: 'First' },
                { label: '2', text: 'Second' },
            ],
        });

        expect(parsed?.intro).toBe('Intro');
        expect(parsed?.variants).toHaveLength(2);
        expect(parsed?.variants[0].text).toBe('First');
    });
});
