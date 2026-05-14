/**
 * Сохраняет режим списка «Все / Мои» (query `ownership=mine`) при переходах между чатами.
 */
export function appendChatListOwnership(
    pathOrUrl: string,
    listOwnership: string | undefined,
): string {
    if (listOwnership !== 'mine') {
        return pathOrUrl;
    }
    const join = pathOrUrl.includes('?') ? '&' : '?';

    return `${pathOrUrl}${join}ownership=mine`;
}
