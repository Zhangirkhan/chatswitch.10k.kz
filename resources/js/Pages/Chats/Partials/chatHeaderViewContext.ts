import type { UnwrapNestedRefs } from 'vue';
import type { useChatHeader } from './useChatHeader';

export type ChatHeaderViewContext = UnwrapNestedRefs<ReturnType<typeof useChatHeader>>;
