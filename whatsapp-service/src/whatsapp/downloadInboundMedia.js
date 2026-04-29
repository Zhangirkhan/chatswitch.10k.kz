/**
 * Скачивание медиа для входящего сообщения с ретраями.
 * Голосовые (ptt) иногда приходят в событии до того, как в Store появится directPath —
 * тогда hasMedia=false и один мгновенный downloadMedia ничего не даёт.
 *
 * @param {{ client: import('whatsapp-web.js').Client, sessionName: string }} service
 * @param {import('whatsapp-web.js').Message} message
 */
async function downloadInboundMedia(service, message) {
  const tryDownload = async (msg) => {
    if (!msg.hasMedia) {
      return null;
    }
    try {
      return await msg.downloadMedia();
    } catch (err) {
      console.error(`[${service.sessionName}] media download error:`, err.message);
      return null;
    }
  };

  let media = await tryDownload(message);

  const type = message.type || '';
  const voiceLike = type === 'ptt' || type === 'voice' || type === 'audio';

  if (!media && (voiceLike || message.hasMedia)) {
    const delaysMs = [400, 1200, 2800, 6000, 12000, 25000, 45000];
    for (const ms of delaysMs) {
      await new Promise((r) => setTimeout(r, ms));
      const refreshed = await service.client.getMessageById(message.id._serialized).catch(() => null);
      if (!refreshed) {
        continue;
      }
      media = await tryDownload(refreshed);
      if (media) {
        break;
      }
    }
  }

  if (!media && (voiceLike || message.hasMedia)) {
    console.warn(
      `[${service.sessionName}] media unavailable after retries: id=${message.id?._serialized || 'unknown'} type=${type || 'unknown'} hasMedia=${Boolean(message.hasMedia)}`
    );
  }

  return media;
}

module.exports = { downloadInboundMedia };
