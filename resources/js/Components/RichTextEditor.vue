<script setup lang="ts">
import { useEditor, EditorContent } from '@tiptap/vue-3';
import StarterKit from '@tiptap/starter-kit';
import Placeholder from '@tiptap/extension-placeholder';
import Link from '@tiptap/extension-link';
import { useI18n } from '@/composables/useI18n';
import { watch, onBeforeUnmount } from 'vue';

const { t } = useI18n();

const props = defineProps<{
    modelValue: string;
    placeholder?: string;
    minHeight?: string;
}>();

const emit = defineEmits<{
    (e: 'update:modelValue', value: string): void;
}>();

const editor = useEditor({
    content: props.modelValue,
    extensions: [
        StarterKit,
        Placeholder.configure({
            placeholder: props.placeholder ?? t('misc.components.richText.placeholder'),
        }),
        Link.configure({
            openOnClick: false,
            autolink: true,
        }),
    ],
    editorProps: {
        attributes: {
            class: 'tiptap-editor',
        },
    },
    onUpdate({ editor: e }) {
        const html = e.isEmpty ? '' : e.getHTML();
        emit('update:modelValue', html);
    },
});

watch(
    () => props.modelValue,
    (val) => {
        if (!editor.value) return;
        const current = editor.value.isEmpty ? '' : editor.value.getHTML();
        if (val !== current) {
            editor.value.commands.setContent(val || '');
        }
    },
);

onBeforeUnmount(() => editor.value?.destroy());

function setLink() {
    const url = window.prompt(t('misc.components.richText.linkPrompt'), editor.value?.getAttributes('link').href ?? '');
    if (url === null) return;
    if (url === '') {
        editor.value?.chain().focus().extendMarkRange('link').unsetLink().run();
    } else {
        editor.value?.chain().focus().extendMarkRange('link').setLink({ href: url }).run();
    }
}
</script>

<template>
    <div class="rich-editor-wrap" :style="{ minHeight: minHeight ?? '140px' }">
        <!-- Toolbar -->
        <div v-if="editor" class="rich-toolbar">
            <button
                type="button"
                class="tb-btn"
                :class="{ 'tb-active': editor.isActive('bold') }"
                :title="t('misc.components.richText.bold')"
                @click="editor.chain().focus().toggleBold().run()"
            >
                <svg class="w-4 h-4" viewBox="0 0 24 24" fill="currentColor"><path d="M15.6 11.79A4.001 4.001 0 0013 5H7v14h7a4.5 4.5 0 001.6-8.21zM9 7h4a2 2 0 010 4H9V7zm4.5 10H9v-4h4.5a2.5 2.5 0 010 5z"/></svg>
            </button>
            <button
                type="button"
                class="tb-btn"
                :class="{ 'tb-active': editor.isActive('italic') }"
                :title="t('misc.components.richText.italic')"
                @click="editor.chain().focus().toggleItalic().run()"
            >
                <svg class="w-4 h-4" viewBox="0 0 24 24" fill="currentColor"><path d="M10 5v3h2.21l-3.42 8H6v3h8v-3h-2.21l3.42-8H18V5z"/></svg>
            </button>
            <button
                type="button"
                class="tb-btn"
                :class="{ 'tb-active': editor.isActive('strike') }"
                :title="t('misc.components.richText.strike')"
                @click="editor.chain().focus().toggleStrike().run()"
            >
                <svg class="w-4 h-4" viewBox="0 0 24 24" fill="currentColor"><path d="M10 19h4v-3h-4v3zM5 4v3h5v3h4V7h5V4H5zM3 14h18v-2H3v2z"/></svg>
            </button>
            <div class="tb-sep"></div>
            <button
                type="button"
                class="tb-btn"
                :class="{ 'tb-active': editor.isActive('heading', { level: 2 }) }"
                :title="t('misc.components.richText.h2')"
                @click="editor.chain().focus().toggleHeading({ level: 2 }).run()"
            >
                <span class="text-xs font-bold">H2</span>
            </button>
            <button
                type="button"
                class="tb-btn"
                :class="{ 'tb-active': editor.isActive('heading', { level: 3 }) }"
                :title="t('misc.components.richText.h3')"
                @click="editor.chain().focus().toggleHeading({ level: 3 }).run()"
            >
                <span class="text-xs font-bold">H3</span>
            </button>
            <div class="tb-sep"></div>
            <button
                type="button"
                class="tb-btn"
                :class="{ 'tb-active': editor.isActive('bulletList') }"
                :title="t('misc.components.richText.bulletList')"
                @click="editor.chain().focus().toggleBulletList().run()"
            >
                <svg class="w-4 h-4" viewBox="0 0 24 24" fill="currentColor"><path d="M4 10.5c-.83 0-1.5.67-1.5 1.5s.67 1.5 1.5 1.5 1.5-.67 1.5-1.5-.67-1.5-1.5-1.5zm0-6c-.83 0-1.5.67-1.5 1.5S3.17 7.5 4 7.5 5.5 6.83 5.5 6 4.83 4.5 4 4.5zm0 12c-.83 0-1.5.68-1.5 1.5s.68 1.5 1.5 1.5 1.5-.68 1.5-1.5-.67-1.5-1.5-1.5zM7 19h14v-2H7v2zm0-6h14v-2H7v2zm0-8v2h14V5H7z"/></svg>
            </button>
            <button
                type="button"
                class="tb-btn"
                :class="{ 'tb-active': editor.isActive('orderedList') }"
                :title="t('misc.components.richText.orderedList')"
                @click="editor.chain().focus().toggleOrderedList().run()"
            >
                <svg class="w-4 h-4" viewBox="0 0 24 24" fill="currentColor"><path d="M2 17h2v.5H3v1h1v.5H2v1h3v-4H2v1zm1-9h1V4H2v1h1v3zm-1 3h1.8L2 13.1v.9h3v-1H3.2L5 10.9V10H2v1zm5-7v2h14V4H7zm0 14h14v-2H7v2zm0-6h14v-2H7v2z"/></svg>
            </button>
            <button
                type="button"
                class="tb-btn"
                :class="{ 'tb-active': editor.isActive('blockquote') }"
                :title="t('misc.components.richText.quote')"
                @click="editor.chain().focus().toggleBlockquote().run()"
            >
                <svg class="w-4 h-4" viewBox="0 0 24 24" fill="currentColor"><path d="M6 17h3l2-4V7H5v6h3zm8 0h3l2-4V7h-6v6h3z"/></svg>
            </button>
            <button
                type="button"
                class="tb-btn"
                :class="{ 'tb-active': editor.isActive('code') }"
                :title="t('misc.components.richText.code')"
                @click="editor.chain().focus().toggleCode().run()"
            >
                <svg class="w-4 h-4" viewBox="0 0 24 24" fill="currentColor"><path d="M9.4 16.6 4.8 12l4.6-4.6L8 6l-6 6 6 6 1.4-1.4zm5.2 0 4.6-4.6-4.6-4.6L16 6l6 6-6 6-1.4-1.4z"/></svg>
            </button>
            <div class="tb-sep"></div>
            <button
                type="button"
                class="tb-btn"
                :class="{ 'tb-active': editor.isActive('link') }"
                :title="t('misc.components.richText.link')"
                @click="setLink"
            >
                <svg class="w-4 h-4" viewBox="0 0 24 24" fill="currentColor"><path d="M3.9 12c0-1.71 1.39-3.1 3.1-3.1h4V7H7c-2.76 0-5 2.24-5 5s2.24 5 5 5h4v-1.9H7c-1.71 0-3.1-1.39-3.1-3.1zM8 13h8v-2H8v2zm9-6h-4v1.9h4c1.71 0 3.1 1.39 3.1 3.1s-1.39 3.1-3.1 3.1h-4V17h4c2.76 0 5-2.24 5-5s-2.24-5-5-5z"/></svg>
            </button>
            <div class="tb-sep"></div>
            <button
                type="button"
                class="tb-btn"
                :title="t('misc.components.richText.hr')"
                @click="editor.chain().focus().setHorizontalRule().run()"
            >
                <svg class="w-4 h-4" viewBox="0 0 24 24" fill="currentColor"><path d="M19 13H5v-2h14v2z"/></svg>
            </button>
            <button
                type="button"
                class="tb-btn"
                :title="t('misc.components.richText.undo')"
                :disabled="!editor.can().undo()"
                @click="editor.chain().focus().undo().run()"
            >
                <svg class="w-4 h-4" viewBox="0 0 24 24" fill="currentColor"><path d="M12.5 8c-2.65 0-5.05.99-6.9 2.6L2 7v9h9l-3.62-3.62c1.39-1.16 3.16-1.88 5.12-1.88 3.54 0 6.55 2.31 7.6 5.5l2.37-.78C21.08 11.03 17.15 8 12.5 8z"/></svg>
            </button>
            <button
                type="button"
                class="tb-btn"
                :title="t('misc.components.richText.redo')"
                :disabled="!editor.can().redo()"
                @click="editor.chain().focus().redo().run()"
            >
                <svg class="w-4 h-4" viewBox="0 0 24 24" fill="currentColor"><path d="M18.4 10.6C16.55 8.99 14.15 8 11.5 8c-4.65 0-8.58 3.03-9.96 7.22L3.9 16c1.05-3.19 4.05-5.5 7.6-5.5 1.95 0 3.73.72 5.12 1.88L13 16h9V7l-3.6 3.6z"/></svg>
            </button>
        </div>

        <!-- Editor content -->
        <EditorContent :editor="editor" />
    </div>
</template>

<style scoped>
.rich-editor-wrap {
    border: 1px solid var(--wa-control-rim);
    box-shadow: var(--wa-control-rim-shadow);
    border-radius: 10px;
    overflow: hidden;
    background: var(--wa-panel-header);
    display: flex;
    flex-direction: column;
}

.rich-toolbar {
    display: flex;
    align-items: center;
    flex-wrap: wrap;
    gap: 1px;
    padding: 4px 6px;
    border-bottom: 1px solid var(--wa-border);
    background: var(--wa-panel);
}

.tb-btn {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 28px;
    height: 28px;
    border: none;
    background: transparent;
    border-radius: 6px;
    color: var(--wa-text-secondary);
    cursor: pointer;
    transition: background-color 0.1s, color 0.1s;
    flex-shrink: 0;
}
.tb-btn:hover:not(:disabled) {
    background: var(--wa-panel-hover);
    color: var(--wa-text);
}
.tb-btn:disabled {
    opacity: 0.35;
    cursor: default;
}
.tb-active {
    background: color-mix(in srgb, var(--wa-accent) 18%, var(--wa-panel));
    color: var(--wa-accent);
}
.tb-sep {
    width: 1px;
    height: 20px;
    background: var(--wa-border);
    margin: 0 3px;
}
</style>

<style>
/* Global styles for TipTap editor content */
.tiptap-editor {
    padding: 0.6rem 0.8rem;
    min-height: inherit;
    outline: none;
    font-size: 0.9rem;
    color: var(--wa-text);
    line-height: 1.55;
    flex: 1;
}
.tiptap-editor p { margin: 0 0 0.4em; }
.tiptap-editor p:last-child { margin-bottom: 0; }
.tiptap-editor h2 { font-size: 1.1rem; font-weight: 700; margin: 0.6em 0 0.3em; }
.tiptap-editor h3 { font-size: 1rem; font-weight: 700; margin: 0.5em 0 0.25em; }
.tiptap-editor ul, .tiptap-editor ol { padding-left: 1.4rem; margin: 0.3em 0; }
.tiptap-editor li { margin: 0.15em 0; }
.tiptap-editor blockquote {
    border-left: 3px solid var(--wa-border-strong);
    padding-left: 0.75rem;
    color: var(--wa-text-secondary);
    margin: 0.5em 0;
}
.tiptap-editor code {
    background: color-mix(in srgb, var(--wa-accent) 10%, var(--wa-panel-header));
    border-radius: 4px;
    padding: 0.1em 0.35em;
    font-size: 0.85em;
    font-family: monospace;
}
.tiptap-editor pre {
    background: var(--wa-panel);
    border: 1px solid var(--wa-border);
    border-radius: 8px;
    padding: 0.65rem 0.9rem;
    overflow-x: auto;
    margin: 0.5em 0;
}
.tiptap-editor pre code { background: none; padding: 0; }
.tiptap-editor hr { border: none; border-top: 1px solid var(--wa-border); margin: 0.75em 0; }
.tiptap-editor a { color: var(--wa-accent); text-decoration: underline; }
.tiptap-editor .is-editor-empty:first-child::before {
    content: attr(data-placeholder);
    color: var(--wa-text-secondary);
    opacity: 0.55;
    pointer-events: none;
    float: left;
    height: 0;
}

/* Rendered body (view mode) */
.post-body-html p { margin: 0 0 0.5em; }
.post-body-html p:last-child { margin-bottom: 0; }
.post-body-html h2 { font-size: 1.1rem; font-weight: 700; margin: 0.7em 0 0.3em; }
.post-body-html h3 { font-size: 1rem; font-weight: 700; margin: 0.55em 0 0.25em; }
.post-body-html ul, .post-body-html ol { padding-left: 1.4rem; margin: 0.35em 0; }
.post-body-html li { margin: 0.15em 0; }
.post-body-html blockquote {
    border-left: 3px solid var(--wa-border-strong);
    padding-left: 0.75rem;
    color: var(--wa-text-secondary);
    margin: 0.5em 0;
}
.post-body-html code {
    background: color-mix(in srgb, var(--wa-accent) 10%, var(--wa-panel-header));
    border-radius: 4px;
    padding: 0.1em 0.35em;
    font-size: 0.85em;
    font-family: monospace;
}
.post-body-html pre {
    background: var(--wa-panel);
    border: 1px solid var(--wa-border);
    border-radius: 8px;
    padding: 0.65rem 0.9rem;
    overflow-x: auto;
    margin: 0.5em 0;
}
.post-body-html pre code { background: none; padding: 0; }
.post-body-html hr { border: none; border-top: 1px solid var(--wa-border); margin: 0.75em 0; }
.post-body-html a { color: var(--wa-accent); text-decoration: underline; }
.post-body-html strong { font-weight: 700; }
.post-body-html em { font-style: italic; }
.post-body-html s { text-decoration: line-through; }
</style>
