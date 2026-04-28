import defaultTheme from 'tailwindcss/defaultTheme';
import forms from '@tailwindcss/forms';

/**
 * WhatsApp Web color tokens — all resolved via CSS variables so the same
 * class works in both themes. Update the values in resources/css/app.css,
 * never here.
 */
const waColors = {
    bg: 'var(--wa-bg)',
    panel: 'var(--wa-panel)',
    'panel-header': 'var(--wa-panel-header)',
    'panel-input': 'var(--wa-panel-input)',
    'panel-hover': 'var(--wa-panel-hover)',
    selected: 'var(--wa-selected)',
    'rail-bg': 'var(--wa-rail-bg)',
    'rail-hover': 'var(--wa-rail-btn-hover)',
    empty: 'var(--wa-empty-bg)',

    border: 'var(--wa-border)',
    'border-strong': 'var(--wa-border-strong)',
    divider: 'var(--wa-divider)',

    text: 'var(--wa-text)',
    'text-secondary': 'var(--wa-text-secondary)',
    'text-muted': 'var(--wa-text-muted)',
    icon: 'var(--wa-icon)',

    accent: 'var(--wa-accent)',
    'accent-hover': 'var(--wa-accent-hover)',
    'accent-soft': 'var(--wa-accent-soft)',
    'accent-on': 'var(--wa-accent-on)',

    'bubble-in': 'var(--wa-bubble-in)',
    'bubble-out': 'var(--wa-bubble-out)',
    'bubble-text': 'var(--wa-bubble-text)',

    unread: 'var(--wa-unread)',
    'unread-text': 'var(--wa-unread-text)',
    'ack-read': 'var(--wa-ack-read)',

    'avatar-bg': 'var(--wa-avatar-bg)',
    'avatar-icon': 'var(--wa-avatar-icon)',

    danger: 'var(--wa-danger)',
};

/** @type {import('tailwindcss').Config} */
export default {
    content: [
        './vendor/laravel/framework/src/Illuminate/Pagination/resources/views/*.blade.php',
        './storage/framework/views/*.php',
        './resources/views/**/*.blade.php',
        './resources/js/**/*.vue',
    ],

    theme: {
        extend: {
            fontFamily: {
                sans: ['Figtree', ...defaultTheme.fontFamily.sans],
            },
            colors: {
                wa: waColors,
            },
        },
    },

    plugins: [forms],
};
