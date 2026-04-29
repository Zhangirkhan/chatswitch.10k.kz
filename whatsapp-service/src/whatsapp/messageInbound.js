const { notifyLaravel } = require('./webhook');
const { downloadInboundMedia } = require('./downloadInboundMedia');
const { uploadInboundMediaBuffer } = require('./uploadInboundMedia');

/**
 * Из JID участника (@c.us и т.д.) — только цифры телефона.
 * @lid и группы @g.us не используем как телефон.
 */
function digitsFromWhatsAppUserJid(raw) {
  if (!raw || typeof raw !== 'string') return null;
  const s = raw.trim();
  if (s.endsWith('@lid') || s.endsWith('@g.us')) return null;
  if (s.endsWith('@c.us')) {
    const d = s.slice(0, -5).replace(/\D/g, '');
    return d.length ? d : null;
  }
  if (s.endsWith('@s.whatsapp.net')) {
    const d = s.slice(0, -('@s.whatsapp.net'.length)).replace(/\D/g, '');
    return d.length ? d : null;
  }
  if (!s.includes('@')) {
    const only = s.replace(/\D/g, '');
    if (only.length >= 8 && only.length <= 15) return only;
  }
  return null;
}

function resolveInboundSenderPhone(message, chat, contact) {
  const authorStr = String(message.author || '');
  const authorIsLid = authorStr.endsWith('@lid');

  let digits = digitsFromWhatsAppUserJid(authorStr);
  if (!digits && !authorIsLid && contact?.number) {
    digits = digitsFromWhatsAppUserJid(String(contact.number));
  }
  if (!digits && !chat.isGroup) {
    digits = digitsFromWhatsAppUserJid(String(message.from || ''));
  }
  return digits;
}

function shouldTryInboundMediaDownload(message) {
  const type = String(message.type || '').toLowerCase();
  return Boolean(message.hasMedia) || type === 'ptt' || type === 'voice' || type === 'audio';
}

async function uploadInboundMediaWhenAvailable(service, message, waMessageId) {
  const media = await downloadInboundMedia(service, message);

  if (!media) {
    return;
  }

  await uploadInboundMediaBuffer(service, waMessageId, media);
}

async function handleIncomingMessage(service, message) {
  const chat = await message.getChat();
  const contact = await message.getContact();

  const messageData = {
    session: service.sessionName,
    messageId: message.id?._serialized,
    from: message.from,
    to: message.to,
    body: message.body || '',
    type: message.type || 'chat',
    timestamp: message.timestamp,
    isGroup: chat.isGroup,
    chatId: chat.id?._serialized,
    chatName: chat.name || contact?.pushname || contact?.name || message.from,
    senderPhone: resolveInboundSenderPhone(message, chat, contact),
    senderAuthorJid: message.author || null,
    senderName: contact?.pushname || contact?.name || null,
    isForwarded: message.isForwarded || false,
    hasQuotedMsg: message.hasQuotedMsg || false,
  };

  if (message.hasQuotedMsg) {
    try {
      const quoted = await message.getQuotedMessage();
      messageData.quotedMessageId = quoted.id?._serialized;
      messageData.quotedBody = quoted.body;
    } catch (_) {}
  }

  // whatsapp-web.js exposes message.duration (seconds) для аудио/голосовых.
  // Прокидываем на бэкенд, чтобы показать в превью чата «Голосовое сообщение (0:12)».
  if (message.duration !== undefined && message.duration !== null && message.duration !== '') {
    const durationSeconds = Number(message.duration);
    if (Number.isFinite(durationSeconds) && durationSeconds >= 0) {
      messageData.mediaDuration = Math.round(durationSeconds);
    }
  }

  await notifyLaravel('message_received', messageData);

  const waMessageId = message.id?._serialized;
  if (waMessageId && shouldTryInboundMediaDownload(message)) {
    // Не кладём base64 в JSON вебхука — типичные лимиты post_max_size / nginx ломают голос/видео.
    // Файл догружается вторым запросом уже после постановки Message в очередь Laravel.
    uploadInboundMediaWhenAvailable(service, message, waMessageId).catch((err) => {
      console.error(`[${service.sessionName}] inbound media background upload error:`, err.message);
    });
  }
}

module.exports = { handleIncomingMessage };
