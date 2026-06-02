import { beforeEach, describe, expect, it } from 'vitest';
import { nextTick } from 'vue';
import { useLocalSetting } from './useLocalSetting';

beforeEach(() => {
    localStorage.clear();
});

describe('useLocalSetting', () => {
    it('starts with initial value when storage is empty', () => {
        const value = useLocalSetting('sidebar.collapsed', false);

        expect(value.value).toBe(false);
    });

    it('reads persisted JSON from localStorage', () => {
        localStorage.setItem('accel.settings.sidebar.collapsed', 'true');

        const value = useLocalSetting('sidebar.collapsed', false);

        expect(value.value).toBe(true);
    });

    it('persists changes to localStorage', async () => {
        const value = useLocalSetting('theme.mode', 'light');

        value.value = 'dark';
        await nextTick();

        expect(localStorage.getItem('accel.settings.theme.mode')).toBe('"dark"');
    });

    it('falls back to initial on invalid JSON', () => {
        localStorage.setItem('accel.settings.theme.mode', '{bad');

        const value = useLocalSetting('theme.mode', 'light');

        expect(value.value).toBe('light');
    });
});
