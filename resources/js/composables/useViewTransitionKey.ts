import { computed, type MaybeRefOrGetter, toValue } from 'vue';
import { usePage } from '@inertiajs/vue3';

export type ViewTransitionScope = 'app-shell' | 'chat-shell' | 'organization-shell' | 'page';

function resolveShellKey(url: string, scope: ViewTransitionScope): string | null {
    if (scope === 'app-shell' || scope === 'chat-shell') {
        if (url.includes('/chats/archived')) {
            return 'shell:chats:archived';
        }
        if (url.startsWith('/chats')) {
            return 'shell:chats:active';
        }
    }

    if (scope === 'app-shell' || scope === 'organization-shell') {
        if (url.startsWith('/organization/chat')) {
            return 'shell:organization:chat';
        }
        if (url.startsWith('/organization')) {
            return 'shell:organization:tasks';
        }
    }

    return null;
}

export function useViewTransitionKey(
    scope: MaybeRefOrGetter<ViewTransitionScope> = 'page',
    explicitKey: MaybeRefOrGetter<string | number | null | undefined> = null,
) {
    const page = usePage();

    return computed(() => {
        const custom = toValue(explicitKey);
        if (custom !== null && custom !== undefined && custom !== '') {
            return String(custom);
        }

        const url = typeof page.url === 'string' ? page.url : '';
        const scopeValue = toValue(scope);

        if (scopeValue === 'page') {
            return String(page.component ?? url);
        }

        const shell = resolveShellKey(url, scopeValue);
        if (shell !== null) {
            return shell;
        }

        return `shell:page:${String(page.component ?? url)}`;
    });
}
