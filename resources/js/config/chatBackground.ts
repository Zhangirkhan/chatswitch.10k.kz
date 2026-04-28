import type { Theme } from '@/composables/useTheme';

/**
 * Chat background configuration.
 *
 * - lightBackground: image used when light theme is active
 * - darkBackground:  image used when dark theme is active
 * - lightOverlay:    CSS color painted on top of the light image to keep text readable
 * - darkOverlay:     CSS color painted on top of the dark image
 * - tileSize:        CSS length used as the tile size of the pattern (e.g. "280px").
 *                    Smaller values = sharper, denser pattern.
 *
 * Set a path to `null` to fall back to the built-in doodle background for that theme.
 */
export interface ChatBackgroundConfig {
    lightBackground: string | null;
    darkBackground: string | null;
    lightOverlay: string;
    darkOverlay: string;
    tileSize: string;
}

export const chatBackground: ChatBackgroundConfig = {
    lightBackground: '/images/chat-backgrounds/light.png',
    darkBackground: '/images/chat-backgrounds/dark.png',
    lightOverlay: 'rgba(0, 0, 0, 0.2)',
    darkOverlay: 'rgba(255, 255, 255, 0.05)',
    tileSize: '280px',
};

export function getBackgroundForTheme(
    theme: Theme,
    config: ChatBackgroundConfig = chatBackground,
): { image: string | null; overlay: string } {
    if (theme === 'light') {
        return { image: config.lightBackground, overlay: config.lightOverlay };
    }
    return { image: config.darkBackground, overlay: config.darkOverlay };
}
