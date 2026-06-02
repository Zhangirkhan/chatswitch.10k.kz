import type { InjectionKey } from 'vue';
import type { ChatHeaderViewContext } from './chatHeaderViewContext';

export const CHAT_HEADER_VIEW_KEY: InjectionKey<ChatHeaderViewContext> = Symbol('chatHeaderView');
