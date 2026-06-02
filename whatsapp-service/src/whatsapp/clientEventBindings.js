const { Events } = require('whatsapp-web.js');
const { notifyLaravel } = require('./webhook');
const { handleIncomingMessage } = require('./messageInbound');
const { syncMissedInboundMessages, startInboundSyncPoller } = require('./syncMissedInbound');

function webhookData(service, data) {
  if (service?.companyId != null) {
    return { ...data, companyId: service.companyId };
  }

  return data;
}

const recentMessages = new Map();
const DEDUP_TTL = 8000;

function isDuplicate(id) {
  if (recentMessages.has(id)) return true;
  recentMessages.set(id, Date.now());
  setTimeout(() => recentMessages.delete(id), DEDUP_TTL);
  return false;
}

function scheduleInboundSync(service, reason) {
  setTimeout(() => {
    syncMissedInboundMessages(service, { reason }).catch((err) => {
      console.error(`[${service.sessionName}] inbound sync failed:`, err.message);
    });
  }, 1500);
}

function attachRuntimeEvents(service) {
  const client = service.client;
  if (!client) {
    return;
  }

  // После recreate Client флаг мог остаться true, а слушатели — на старом инстансе.
  if (service.runtimeEventsBound && service.runtimeEventsClient === client) {
    scheduleInboundSync(service, 'runtime_recheck');
    return;
  }

  service.runtimeEventsBound = true;
  service.runtimeEventsClient = client;

  client.on(Events.INCOMING_CALL, async (call) => {
    if (call?.fromMe) {
      return;
    }
    try {
      await call.reject();
    } catch (err) {
      console.error(`[${service.sessionName}] call reject error:`, err.message);
    }
    try {
      await notifyLaravel('call_incoming', webhookData(service, {
        session: service.sessionName,
        peerJid: call.from,
        callId: call.id,
        isVideo: Boolean(call.isVideo),
        isGroup: Boolean(call.isGroup),
        fromMe: Boolean(call.fromMe),
      }));
    } catch (err) {
      console.error(`[${service.sessionName}] call webhook notify error:`, err.message);
    }
  });

  const onIncomingMessage = async (message) => {
    const serialized = message.id?._serialized;
    // Техническая диагностика: чтобы понять, срабатывает ли обработчик вообще.
    // Логируем ограниченно, чтобы не забить логи.
    if (service._debugInboundMessageLogsCount === undefined) {
      service._debugInboundMessageLogsCount = 0;
    }
    if (service._debugInboundMessageLogsCount < 5) {
      service._debugInboundMessageLogsCount += 1;
      // eslint-disable-next-line no-console
      console.log(
        `[${service.sessionName}] inbound event: serialized=${serialized ? String(serialized).slice(0, 18) : 'none'} fromMe=${Boolean(
          message?.fromMe
        )} isStatus=${Boolean(message?.isStatus)} type=${String(message?.type || '')}`
      );
    }
    // Дедуп используем только если есть нормальный идентификатор.
    // В некоторых ивентах/версиях у сообщения может не быть `_serialized`,
    // тогда просто пропускаем дедуп, чтобы не потерять inbound.
    if (serialized && isDuplicate(serialized)) return;
    if (message.fromMe || message.isStatus) return;

    try {
      await handleIncomingMessage(service, message);
    } catch (err) {
      console.error(`[${service.sessionName}] inbound error:`, err.message);
    }
  };

  // whatsapp-web.js иногда эмитит разные ивенты для создания/получения сообщения.
  // Поддерживаем оба, чтобы inbound-форварды в Laravel не “умирали”.
  client.on(Events.MESSAGE_RECEIVED, onIncomingMessage);
  client.on(Events.MESSAGE_CREATE, onIncomingMessage);

  client.on('message_ack', (message, ack) => {
    const ackMap = { 0: 'pending', 1: 'sent', 2: 'delivered', 3: 'read', 4: 'played' };
    notifyLaravel('message_status', webhookData(service, {
      session: service.sessionName,
      messageId: message.id?._serialized,
      ack: ackMap[ack] || 'pending',
    }));
  });

  client.on('message_reaction', (reaction) => {
    const senderId = reaction.senderId || '';
    const ownId = client.info?.wid?._serialized || (client.info?.wid?.user ? `${client.info.wid.user}@c.us` : '');
    const fromMe =
      typeof reaction?.id?.fromMe === 'boolean'
        ? reaction.id.fromMe
        : Boolean(ownId && senderId === ownId);
    // eslint-disable-next-line no-console
    console.log(
      `[${service.sessionName}] message_reaction event: msg=${reaction.msgId?._serialized} sender=${senderId} fromMe=${Boolean(
        fromMe
      )} reaction=${JSON.stringify(reaction.reaction)}`
    );

    notifyLaravel('message_reaction', webhookData(service, {
      session: service.sessionName,
      messageId: reaction.msgId?._serialized,
      reaction: reaction.reaction,
      senderId,
      fromMe,
    }));
  });

  scheduleInboundSync(service, 'runtime_bound');
  startInboundSyncPoller(service);
}

function attachEventBindings(service) {
  const client = service.client;
  const tag = `[${service.sessionName}]`;

  client.on(Events.QR_RECEIVED, (qr) => {
    console.log(`${tag} QR received`);
    service.qrCode = qr;
    notifyLaravel('qr_generated', webhookData(service, { session: service.sessionName, qr }));
  });

  client.on(Events.READY, () => {
    console.log(`${tag} READY`);
    service.isReady = true;
    service.isInitializing = false;
    service.qrCode = null;
    service.lastError = null;

    const info = client.info || {};
    notifyLaravel('connected', webhookData(service, {
      session: service.sessionName,
      phone: info.wid?.user || null,
      name: info.pushname || null,
      platform: info.platform || null,
    }));

    attachRuntimeEvents(service);
  });

  client.on(Events.STATE_CHANGED, (state) => {
    // Fallback for rare cases when READY is missed but client is already connected.
    if (state === 'CONNECTED' && !service.isReady) {
      console.log(`${tag} STATE_CHANGED -> CONNECTED (fallback ready)`);
      service.isReady = true;
      service.isInitializing = false;
      service.qrCode = null;
      attachRuntimeEvents(service);
    }
  });

  client.on(Events.AUTHENTICATED, () => {
    console.log(`${tag} authenticated`);
  });

  client.on(Events.AUTHENTICATION_FAILURE, (message) => {
    console.error(`${tag} auth failure:`, message);
    service.isReady = false;
    service.isInitializing = false;
    service.lastError = String(message || 'Authentication failure');
    notifyLaravel('auth_failure', webhookData(service, { session: service.sessionName, message }));
  });

  client.on(Events.DISCONNECTED, (reason) => {
    console.log(`${tag} disconnected:`, reason);
    service.isReady = false;
    service.isInitializing = false;
    service.qrCode = null;
    notifyLaravel('disconnected', webhookData(service, { session: service.sessionName, reason }));
  });
}

module.exports = { attachEventBindings, attachRuntimeEvents };
