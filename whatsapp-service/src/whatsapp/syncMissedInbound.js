const { handleIncomingMessage } = require('./messageInbound');

const SYNC_COOLDOWN_MS = 30_000;
const LOOKBACK_SECONDS = 72 * 3600;
const MAX_CHATS = 50;
const MESSAGES_PER_CHAT = 12;

/**
 * После reconnect whatsapp-web.js иногда перестаёт эмитить MESSAGE_*,
 * хотя fetchMessages() продолжает работать. Догоняем недавние входящие.
 */
async function syncMissedInboundMessages(service, { reason = 'manual', force = false } = {}) {
  if (!service?.isReady || !service.client) {
    return { synced: 0, skipped: 'not_ready' };
  }

  const nowMs = Date.now();
  if (
    !force
    && service._lastInboundSyncAt
    && nowMs - service._lastInboundSyncAt < SYNC_COOLDOWN_MS
  ) {
    return { synced: 0, skipped: 'cooldown' };
  }

  service._lastInboundSyncAt = nowMs;
  const cutoffTs = Math.floor(nowMs / 1000) - LOOKBACK_SECONDS;
  let synced = 0;

  try {
    const chats = await service.client.getChats();
    const candidates = (chats || [])
      .filter((chat) => chat && !chat.isGroup)
      .sort((a, b) => Number(b.timestamp || 0) - Number(a.timestamp || 0))
      .slice(0, MAX_CHATS);

    for (const chat of candidates) {
      let messages = [];

      try {
        messages = await chat.fetchMessages({ limit: MESSAGES_PER_CHAT });
      } catch (err) {
        console.error(
          `[${service.sessionName}] sync fetchMessages error (${chat.id?._serialized}):`,
          err.message
        );
        continue;
      }

      for (const message of messages || []) {
        if (!message || message.fromMe || message.isStatus) {
          continue;
        }

        const ts = Number(message.timestamp || 0);
        if (ts > 0 && ts < cutoffTs) {
          continue;
        }

        try {
          await handleIncomingMessage(service, message);
          synced += 1;
        } catch (err) {
          console.error(`[${service.sessionName}] sync inbound webhook error:`, err.message);
        }
      }
    }

    if (synced > 0) {
      console.log(`[${service.sessionName}] synced ${synced} inbound message(s) (${reason})`);
    }
  } catch (err) {
    console.error(`[${service.sessionName}] syncMissedInbound error:`, err.message);
  }

  return { synced };
}

function startInboundSyncPoller(service) {
  if (service._inboundSyncInterval) {
    return;
  }

  service._inboundSyncInterval = setInterval(() => {
    syncMissedInboundMessages(service, { reason: 'interval' }).catch((err) => {
      console.error(`[${service.sessionName}] interval sync failed:`, err.message);
    });
  }, 60_000);
}

function stopInboundSyncPoller(service) {
  if (!service._inboundSyncInterval) {
    return;
  }

  clearInterval(service._inboundSyncInterval);
  service._inboundSyncInterval = null;
}

module.exports = { syncMissedInboundMessages, startInboundSyncPoller, stopInboundSyncPoller };
