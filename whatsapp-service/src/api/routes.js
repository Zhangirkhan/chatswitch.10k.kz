const express = require('express');
const multer = require('multer');
const QRCode = require('qrcode');
const { MessageMedia } = require('whatsapp-web.js');
const {
  getOrCreateClient,
  getAllClients,
  resolveSessionName,
  removeClient,
} = require('../whatsapp/clientManager');

const router = express.Router();
const upload = multer({
  storage: multer.memoryStorage(),
  limits: { fileSize: 80 * 1024 * 1024 },
});

let debugSendMentionCount = 0;
let debugSendMentionReqCount = 0;

function authMiddleware(req, res, next) {
  const token = (req.headers.authorization || '').replace(/^Bearer\s+/i, '');
  const expected = process.env.LARAVEL_API_TOKEN || process.env.WHATSAPP_SERVICE_TOKEN;
  if (!expected || token !== expected) {
    return res.status(401).json({ success: false, error: 'Unauthorized' });
  }

  return next();
}

function currentSession(req) {
  return String(resolveSessionName(req) || '').trim();
}

function chatIdFromSerializedMessageId(messageId) {
  const parts = String(messageId || '').split('_');
  if (parts.length < 3) return null;
  const chatId = parts[1] || null;
  return chatId ? String(chatId) : null;
}

function getReadyClient(req, res) {
  const sessionName = currentSession(req);
  const service = getOrCreateClient(sessionName);
  if (!service.isReady || !service.client) {
    res.status(400).json({ success: false, error: 'Client not ready', sessionName });
    return null;
  }

  return service;
}

function mediaFromPayload(payload) {
  const { mediaData, mimetype, filename } = payload;
  if (!mediaData || !mimetype) {
    throw new Error('mediaData and mimetype are required');
  }

  return new MessageMedia(mimetype, mediaData, filename || undefined);
}

router.use(authMiddleware);

router.get('/sessions', (_req, res) => {
  res.json({ success: true, sessions: getAllClients() });
});

router.post('/sessions/:name/initialize', async (req, res) => {
  const companyId = req.body?.companyId ?? req.body?.company_id ?? null;
  const service = getOrCreateClient(req.params.name, companyId != null ? Number(companyId) : null);

  // `initialize()` contains the real stale-browser check. Do not short-circuit
  // on `isReady`: a detached Puppeteer browser can leave the session marked ready.
  service.initialize().catch((err) =>
    console.error(`[${req.params.name}] init error:`, err.message)
  );

  return res.json({ success: true, message: 'Initializing' });
});

router.get('/sessions/:name/qr', async (req, res) => {
  const service = getOrCreateClient(req.params.name);

  if (service.isReady) {
    return res.json({ success: true, qr: null, message: 'Already connected' });
  }

  const qr = service.getQRCode();
  if (!qr) {
    return res.json({ success: true, qr: null, message: 'QR not available yet' });
  }

  try {
    const qrImage = await QRCode.toDataURL(qr, { width: 300 });
    return res.json({ success: true, qr: qrImage });
  } catch (err) {
    return res.status(500).json({ success: false, error: err.message });
  }
});

router.get('/sessions/:name/status', (req, res) => {
  const service = getOrCreateClient(req.params.name);

  let profile = null;
  if (service.isReady && service.client?.info) {
    const info = service.client.info;
    profile = {
      phone: info.wid?.user || null,
      name: info.pushname || null,
      platform: info.platform || null,
    };
  }

  res.json({
    success: true,
    sessionName: req.params.name,
    isReady: service.isReady,
    isInitializing: service.isInitializing,
    hasQR: Boolean(service.qrCode),
    lastError: service.lastError,
    profile,
  });
});

router.get('/sessions/:name/verify', async (req, res) => {
  const service = getOrCreateClient(req.params.name);
  const result = await service.verify();

  res.json(result);
});

router.post('/sessions/:name/logout', async (req, res) => {
  const service = getOrCreateClient(req.params.name);
  await service.logout();
  removeClient(req.params.name);

  res.json({ success: true, message: 'Logged out' });
});

router.post('/sessions/:name/destroy', async (req, res) => {
  const service = getOrCreateClient(req.params.name);
  await service.destroy();
  removeClient(req.params.name);

  res.json({ success: true, message: 'Destroyed' });
});

router.post('/send-message', async (req, res) => {
  const service = getReadyClient(req, res);
  if (!service) return;

  const { to, quotedMessageId } = req.body;
  const mentions = Array.isArray(req.body?.mentions) ? req.body.mentions : [];
  const raw = req.body.message;
  const message =
    typeof raw === 'string' ? raw : raw === undefined || raw === null ? '' : String(raw);

  if (debugSendMentionReqCount < 30) {
    debugSendMentionReqCount += 1;
    console.log(
      `[send-message][dbg] req=${debugSendMentionReqCount} to=${to} mentionsN=${mentions.length} msgHasAt=${message.includes('@')} bodyPreview=${String(
        message || '',
      )
        .slice(0, 60)
        .replace(/\s+/g, ' ')}`,
    );
  }

  if (Array.isArray(mentions) && mentions.length > 0 && debugSendMentionCount < 30) {
    debugSendMentionCount += 1;
    console.log(
      `[send-message][mentions] n=${mentions.length} firstIds=${mentions
        .slice(0, 5)
        .join(',')}`,
    );
  }

  if (!to || typeof to !== 'string' || !String(to).trim()) {
    return res.status(422).json({ success: false, error: 'to is required' });
  }
  if (!message.trim()) {
    return res.status(422).json({ success: false, error: 'message must be non-empty' });
  }

  try {
    const options = {};
    if (quotedMessageId) options.quotedMessageId = quotedMessageId;
    if (Array.isArray(mentions) && mentions.length > 0) {
      options.mentions = mentions.filter((m) => typeof m === 'string' && m.trim() !== '');
    }
    const sent = await service.client.sendMessage(to, message, options);

    return res.json({
      success: true,
      messageId: sent.id?._serialized,
      timestamp: sent.timestamp,
    });
  } catch (err) {
    return res.status(500).json({ success: false, error: err.message });
  }
});

router.post('/forward-message', async (req, res) => {
  const service = getReadyClient(req, res)
  if (!service) return

  const { to, sourceMessageId } = req.body
  if (!to || typeof to !== 'string' || !String(to).trim()) {
    return res.status(422).json({ success: false, error: 'to is required' })
  }
  if (!sourceMessageId || typeof sourceMessageId !== 'string') {
    return res.status(422).json({ success: false, error: 'sourceMessageId is required' })
  }

  try {
    let source = await service.client.getMessageById(sourceMessageId)
    if (!source) {
      const chatId = chatIdFromSerializedMessageId(sourceMessageId)
      if (chatId) {
        const chat = await service.client.getChatById(chatId)
        if (chat) {
          const limits = [50, 120, 200]
          for (const limit of limits) {
            try {
              await chat.fetchMessages({ limit })
            } catch (_) {
              // ignore and continue with next attempt
            }
            source = await service.client.getMessageById(sourceMessageId)
            if (source) break
          }
        }
      }
    }

    if (!source) {
      return res.status(404).json({ success: false, error: 'Source message not found in WhatsApp cache' })
    }

    const sent = await source.forward(to)
    return res.json({
      success: true,
      messageId: sent && sent.id ? sent.id._serialized : null,
      timestamp: sent && sent.timestamp ? sent.timestamp : null,
    })
  } catch (err) {
    return res.status(500).json({ success: false, error: err.message })
  }
})

router.post('/react-message', async (req, res) => {
  const service = getReadyClient(req, res);
  if (!service) return;

  const { messageId, reaction } = req.body;
  if (!messageId || typeof reaction !== 'string') {
    return res.status(422).json({ success: false, error: 'messageId and reaction are required' });
  }

  try {
    // eslint-disable-next-line no-console
    console.log(`[react-message] session=${service.sessionName} messageId=${messageId} reaction=${JSON.stringify(reaction)}`);

    let message = await service.client.getMessageById(messageId);
    if (!message) {
      const chatId = chatIdFromSerializedMessageId(messageId);
      if (chatId) {
        const chat = await service.client.getChatById(chatId);
        if (chat) {
          // For older messages WhatsApp Web may not have the target in cache.
          // Try warming up the chat cache with progressively larger fetches.
          const limits = [50, 120, 200];
          for (const limit of limits) {
            try {
              await chat.fetchMessages({ limit });
            } catch (_) {
              // ignore and continue with next attempt
            }
            message = await service.client.getMessageById(messageId);
            if (message) break;
          }
        }
      }
    }

    if (!message) {
      return res.status(404).json({ success: false, error: 'Message not found in WhatsApp cache' });
    }

    // Use whatsapp-web.js public API. This avoids relying on window.Store being injected
    // (which can change between WhatsApp Web versions).
    await message.react(reaction);

    // Best-effort verification: reload and read reactions list.
    let verify = null;
    try {
      await new Promise((r) => setTimeout(r, 800));
      const refreshed = await service.client.getMessageById(message.id?._serialized || messageId);
      if (refreshed && typeof refreshed.getReactions === 'function') {
        const list = await refreshed.getReactions();
        verify = {
          hasReaction: Boolean(refreshed.hasReaction),
          listSize: Array.isArray(list) ? list.length : null,
          list: Array.isArray(list) ? list : null,
        };
      } else {
        verify = { skipped: true, reason: 'No refreshed message' };
      }
    } catch (e) {
      verify = { error: String(e && e.message ? e.message : e) };
    }

    return res.json({ success: true, verify });
  } catch (err) {
    // eslint-disable-next-line no-console
    console.error('[react-message] exception:', err && err.message ? err.message : err);
    return res.status(500).json({ success: false, error: err.message });
  }
});

router.post('/send-media', async (req, res) => {
  const service = getReadyClient(req, res);
  if (!service) return;

  const { to, caption } = req.body;
  if (!to) {
    return res.status(422).json({ success: false, error: 'to is required' });
  }

  try {
    const media = mediaFromPayload(req.body);
    const sent = await service.client.sendMessage(to, media, caption ? { caption } : {});

    return res.json({ success: true, messageId: sent.id?._serialized, timestamp: sent.timestamp });
  } catch (err) {
    return res.status(500).json({ success: false, error: err.message });
  }
});

router.post('/send-media-upload', upload.single('file'), async (req, res) => {
  const service = getReadyClient(req, res);
  if (!service) return;

  const { to, mimetype, filename, caption, sendAsVoice } = req.body;
  if (!to || !req.file) {
    return res.status(422).json({ success: false, error: 'to and file are required' });
  }

  try {
    const nameLower = String(filename || req.file.originalname || '').toLowerCase();
    const mimeBase = String(mimetype || req.file.mimetype || '')
      .split(';')[0]
      .trim()
      .toLowerCase();
    const isWebm =
      mimeBase === 'audio/webm' ||
      mimeBase === 'video/webm' ||
      nameLower.endsWith('.webm');
    let mediaMime = mimetype || req.file.mimetype || 'application/octet-stream';
    if (isWebm && !String(mediaMime).toLowerCase().includes('webm')) {
      mediaMime = 'audio/webm';
    }

    const media = new MessageMedia(
      mediaMime,
      req.file.buffer.toString('base64'),
      filename || req.file.originalname
    );
    const sendVoice =
      sendAsVoice === '1' ||
      sendAsVoice === 1 ||
      sendAsVoice === true ||
      String(sendAsVoice).toLowerCase() === 'true';

    const options = {};
    if (caption) {
      options.caption = caption;
    }
    // WebM из браузера с sendAudioAsVoice даёт 500 внутри WA Web; отправляем как обычное аудио.
    if (sendVoice && !isWebm) {
      options.sendAudioAsVoice = true;
    }

    let sent;
    try {
      sent = await service.client.sendMessage(to, media, options);
    } catch (firstErr) {
      if (isWebm) {
        sent = await service.client.sendMessage(to, media, {
          ...options,
          sendMediaAsDocument: true,
        });
      } else {
        throw firstErr;
      }
    }

    return res.json({ success: true, messageId: sent.id?._serialized, timestamp: sent.timestamp });
  } catch (err) {
    // eslint-disable-next-line no-console
    console.error('[send-media-upload]', err);
    const msg =
      err && typeof err === 'object' && 'message' in err && err.message
        ? String(err.message)
        : String(err);
    return res.status(500).json({ success: false, error: msg || 'send-media-upload failed' });
  }
});

router.post('/send-poll', async (req, res) => {
  const service = getReadyClient(req, res);
  if (!service) return;

  const { to, question, options, allowMultipleAnswers } = req.body;
  if (!to || !question || !Array.isArray(options) || options.length < 2) {
    return res.status(422).json({ success: false, error: 'to, question and at least two options are required' });
  }

  try {
    const { Poll } = require('whatsapp-web.js');
    const poll = new Poll(question, options, { allowMultipleAnswers: Boolean(allowMultipleAnswers) });
    const sent = await service.client.sendMessage(to, poll);

    return res.json({ success: true, messageId: sent.id?._serialized, timestamp: sent.timestamp });
  } catch (err) {
    return res.status(500).json({ success: false, error: err.message });
  }
});

router.post('/create-group', async (req, res) => {
  const service = getReadyClient(req, res);
  if (!service) return;

  const { subject, participants } = req.body;
  if (!subject || !Array.isArray(participants) || participants.length === 0) {
    return res.status(422).json({ success: false, error: 'subject and participants are required' });
  }

  try {
    const group = await service.client.createGroup(subject, participants);

    return res.json({ success: true, group });
  } catch (err) {
    return res.status(500).json({ success: false, error: err.message });
  }
});

router.get('/chats', async (req, res) => {
  const service = getReadyClient(req, res);
  if (!service) return;

  try {
    const chats = await service.client.getChats();
    return res.json({
      success: true,
      chats: (chats || []).map((chat) => ({
        id: chat.id?._serialized,
        name: chat.name || null,
        isGroup: Boolean(chat.isGroup),
        unreadCount: typeof chat.unreadCount === 'number' ? chat.unreadCount : null,
        timestamp: typeof chat.timestamp === 'number' ? chat.timestamp : null,
      })),
    });
  } catch (err) {
    return res.status(500).json({ success: false, error: err.message });
  }
});

router.get('/chats/:chatId/participants', async (req, res) => {
  const service = getReadyClient(req, res);
  if (!service) return;

  try {
    const chat = await service.client.getChatById(req.params.chatId);
    if (!chat) {
      return res.status(404).json({ success: false, error: 'Chat not found' });
    }
    if (!chat.isGroup) {
      return res.status(422).json({ success: false, error: 'Not a group chat' });
    }

    const participants = Array.isArray(chat.participants) ? chat.participants : [];
    const uniqueIds = Array.from(
      new Set(
        participants
          .map((p) => p?.id?._serialized || p?.id || null)
          .filter(Boolean)
          .map(String)
      )
    );

    const contacts = await Promise.all(
      uniqueIds.map(async (id) => {
        try {
          const c = await service.client.getContactById(id);
          const p = participants.find((x) => (x?.id?._serialized || x?.id) === id);
          return {
            id,
            number: c?.number || null,
            name: c?.name || null,
            pushname: c?.pushname || null,
            isBusiness: Boolean(c?.isBusiness),
            isAdmin: Boolean(p?.isAdmin),
            isSuperAdmin: Boolean(p?.isSuperAdmin),
          };
        } catch (_) {
          const p = participants.find((x) => (x?.id?._serialized || x?.id) === id);
          return {
            id,
            number: null,
            name: null,
            pushname: null,
            isBusiness: false,
            isAdmin: Boolean(p?.isAdmin),
            isSuperAdmin: Boolean(p?.isSuperAdmin),
          };
        }
      })
    );

    // Sort: admins first, then by display name/number
    contacts.sort((a, b) => {
      const aRank = a.isSuperAdmin ? 0 : a.isAdmin ? 1 : 2;
      const bRank = b.isSuperAdmin ? 0 : b.isAdmin ? 1 : 2;
      if (aRank !== bRank) return aRank - bRank;
      const an = String(a.name || a.pushname || a.number || a.id);
      const bn = String(b.name || b.pushname || b.number || b.id);
      return an.localeCompare(bn);
    });

    return res.json({ success: true, participants: contacts });
  } catch (err) {
    return res.status(500).json({ success: false, error: err.message });
  }
});

router.get('/chats/:chatId/messages', async (req, res) => {
  const service = getReadyClient(req, res);
  if (!service) return;

  const limitRaw = req.query.limit;
  // whatsapp-web.js fetchMessages can crash on large limits in some WA builds.
  // Keep this endpoint safe; callers can iterate with small limits if needed.
  const limit = Math.max(1, Math.min(5, parseInt(String(limitRaw || '5'), 10) || 5));

  try {
    const chat = await service.client.getChatById(req.params.chatId);
    if (!chat) {
      return res.status(404).json({ success: false, error: 'Chat not found' });
    }

    const messages = await chat.fetchMessages({ limit });
    const mapped = await Promise.all(
      (messages || []).map(async (m) => {
        const id = m?.id?._serialized || null;
        const authorId = m?.author || null; // group messages
        const fromId = m?.from || null;
        const senderId = authorId || fromId || null;
        let contact = null;
        try {
          if (senderId) {
            contact = await service.client.getContactById(String(senderId));
          }
        } catch (_) {
          contact = null;
        }

        return {
          id,
          timestamp: typeof m?.timestamp === 'number' ? m.timestamp : null,
          fromMe: Boolean(m?.fromMe),
          authorId: authorId ? String(authorId) : null,
          fromId: fromId ? String(fromId) : null,
          senderId: senderId ? String(senderId) : null,
          senderNumber: contact?.number || null,
          senderName: contact?.name || null,
          senderPushname: contact?.pushname || null,
        };
      })
    );

    return res.json({ success: true, messages: mapped, limit });
  } catch (err) {
    return res.status(500).json({ success: false, error: err.message });
  }
});

router.post('/send-contact', async (req, res) => {
  const service = getReadyClient(req, res);
  if (!service) return;

  const { to, vcard, displayName } = req.body;
  if (!to || !vcard) {
    return res.status(422).json({ success: false, error: 'to and vcard are required' });
  }

  try {
    const sent = await service.client.sendMessage(to, vcard, {
      parseVCards: true,
      contactCard: true,
      caption: displayName || undefined,
    });

    return res.json({ success: true, messageId: sent.id?._serialized, timestamp: sent.timestamp });
  } catch (err) {
    return res.status(500).json({ success: false, error: err.message });
  }
});

router.post('/send-seen', async (req, res) => {
  const service = getReadyClient(req, res);
  if (!service) return;

  try {
    const chat = await service.client.getChatById(req.body.chatId);
    await chat.sendSeen();

    return res.json({ success: true });
  } catch (err) {
    return res.status(500).json({ success: false, error: err.message });
  }
});

router.post('/set-typing', async (req, res) => {
  const service = getReadyClient(req, res);
  if (!service) return;

  try {
    const chat = await service.client.getChatById(req.body.chatId);
    if (req.body.isTyping) {
      await chat.sendStateTyping();
    } else {
      await chat.clearState();
    }

    return res.json({ success: true });
  } catch (err) {
    return res.status(500).json({ success: false, error: err.message });
  }
});

router.get('/messages/:chatId', async (req, res) => {
  const service = getReadyClient(req, res);
  if (!service) return;

  try {
    const chat = await service.client.getChatById(req.params.chatId);
    const limit = parseInt(req.query.limit || '50', 10);
    const messages = await chat.fetchMessages({ limit });

    return res.json({
      success: true,
      messages: messages.map((message) => ({
        id: message.id?._serialized,
        from: message.from,
        to: message.to,
        body: message.body,
        type: message.type,
        timestamp: message.timestamp,
        fromMe: message.fromMe,
        hasMedia: message.hasMedia,
        ack: message.ack,
      })),
    });
  } catch (err) {
    return res.status(500).json({ success: false, error: err.message });
  }
});

router.get('/contacts', async (req, res) => {
  const service = getReadyClient(req, res);
  if (!service) return;

  try {
    const contacts = await service.client.getContacts();

    return res.json({
      success: true,
      contacts: contacts.map((contact) => ({
        id: contact.id?._serialized,
        number: contact.number,
        name: contact.name,
        pushname: contact.pushname,
        isMyContact: contact.isMyContact,
        isBusiness: contact.isBusiness,
      })),
    });
  } catch (err) {
    return res.status(500).json({ success: false, error: err.message });
  }
});

router.get('/profile-pic/:contactId', async (req, res) => {
  const service = getReadyClient(req, res);
  if (!service) return;

  try {
    const url = await service.client.getProfilePicUrl(req.params.contactId);

    return res.json({ success: true, url: url || null });
  } catch (_) {
    return res.json({ success: true, url: null });
  }
});

module.exports = router;
