const { Events } = require('whatsapp-web.js');
const { notifyLaravel } = require('./webhook');
const { handleIncomingMessage } = require('./messageInbound');

const recentMessages = new Map();
const DEDUP_TTL = 8000;

function isDuplicate(id) {
  if (recentMessages.has(id)) return true;
  recentMessages.set(id, Date.now());
  setTimeout(() => recentMessages.delete(id), DEDUP_TTL);
  return false;
}

function attachRuntimeEvents(service) {
  const client = service.client;

  client.on(Events.MESSAGE_RECEIVED, async (message) => {
    const serialized = message.id?._serialized;
    if (!serialized || isDuplicate(serialized)) return;
    if (message.fromMe || message.isStatus) return;

    try {
      await handleIncomingMessage(service, message);
    } catch (err) {
      console.error(`[${service.sessionName}] inbound error:`, err.message);
    }
  });

  client.on('message_ack', (message, ack) => {
    const ackMap = { 0: 'pending', 1: 'sent', 2: 'delivered', 3: 'read', 4: 'played' };
    notifyLaravel('message_status', {
      session: service.sessionName,
      messageId: message.id?._serialized,
      ack: ackMap[ack] || 'pending',
    });
  });

  client.on('message_reaction', (reaction) => {
    const senderId = reaction.senderId || '';
    const ownId = client.info?.wid?._serialized || (client.info?.wid?.user ? `${client.info.wid.user}@c.us` : '');

    notifyLaravel('message_reaction', {
      session: service.sessionName,
      messageId: reaction.msgId?._serialized,
      reaction: reaction.reaction,
      senderId,
      fromMe: Boolean(ownId && senderId === ownId),
    });
  });
}

function attachEventBindings(service) {
  const client = service.client;
  const tag = `[${service.sessionName}]`;

  client.on(Events.QR_RECEIVED, (qr) => {
    console.log(`${tag} QR received`);
    service.qrCode = qr;
    notifyLaravel('qr_generated', { session: service.sessionName, qr });
  });

  client.on(Events.READY, () => {
    console.log(`${tag} READY`);
    service.isReady = true;
    service.isInitializing = false;
    service.qrCode = null;
    service.lastError = null;

    const info = client.info || {};
    notifyLaravel('connected', {
      session: service.sessionName,
      phone: info.wid?.user || null,
      name: info.pushname || null,
      platform: info.platform || null,
    });

    attachRuntimeEvents(service);
  });

  client.on(Events.AUTHENTICATED, () => {
    console.log(`${tag} authenticated`);
  });

  client.on(Events.AUTHENTICATION_FAILURE, (message) => {
    console.error(`${tag} auth failure:`, message);
    service.isReady = false;
    service.isInitializing = false;
    service.lastError = String(message || 'Authentication failure');
    notifyLaravel('auth_failure', { session: service.sessionName, message });
  });

  client.on(Events.DISCONNECTED, (reason) => {
    console.log(`${tag} disconnected:`, reason);
    service.isReady = false;
    service.isInitializing = false;
    service.qrCode = null;
    notifyLaravel('disconnected', { session: service.sessionName, reason });
  });
}

module.exports = { attachEventBindings };
