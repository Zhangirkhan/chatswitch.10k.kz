const { notifyLaravel } = require('./webhook');
const { downloadInboundMedia } = require('./downloadInboundMedia');
const { uploadInboundMediaBuffer } = require('./uploadInboundMedia');

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
    senderPhone: contact?.number || message.author || message.from,
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

  const media = await downloadInboundMedia(service, message);
  // Не кладём base64 в JSON вебхука — типичные лимиты post_max_size / nginx ломают голос/видео.
  // Файл уходит вторым запросом uploadInboundMediaBuffer после создания Message в очереди.

  // whatsapp-web.js exposes message.duration (seconds) для аудио/голосовых.
  // Прокидываем на бэкенд, чтобы показать в превью чата «Голосовое сообщение (0:12)».
  if (message.duration !== undefined && message.duration !== null && message.duration !== '') {
    const durationSeconds = Number(message.duration);
    if (Number.isFinite(durationSeconds) && durationSeconds >= 0) {
      messageData.mediaDuration = Math.round(durationSeconds);
    }
  }

  await notifyLaravel('message_received', messageData);

  if (media && message.id?._serialized) {
    await uploadInboundMediaBuffer(service, message.id._serialized, media);
  }
}

module.exports = { handleIncomingMessage };
