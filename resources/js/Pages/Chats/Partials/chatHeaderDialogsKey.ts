import type { InjectionKey } from 'vue';
import type { ChatHeaderDialogsContext } from './chatHeaderDialogsContext';

export const CHAT_HEADER_DIALOGS_KEY: InjectionKey<ChatHeaderDialogsContext> = Symbol('chatHeaderDialogs');
