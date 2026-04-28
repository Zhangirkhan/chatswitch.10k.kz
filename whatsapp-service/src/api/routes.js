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
  const service = getOrCreateClient(req.params.name);

  if (service.isReady) {
    return res.json({ success: true, message: 'Already connected' });
  }

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

  res.json({
    success: true,
    sessionName: req.params.name,
    isReady: service.isReady,
    isInitializing: service.isInitializing,
    hasQR: Boolean(service.qrCode),
    lastError: service.lastError,
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

  const { to, message, quotedMessageId } = req.body;
  if (!to || !message) {
    return res.status(422).json({ success: false, error: 'to and message are required' });
  }

  try {
    const options = {};
    if (quotedMessageId) options.quotedMessageId = quotedMessageId;
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

router.post('/react-message', async (req, res) => {
  const service = getReadyClient(req, res);
  if (!service) return;

  const { messageId, reaction } = req.body;
  if (!messageId || typeof reaction !== 'string') {
    return res.status(422).json({ success: false, error: 'messageId and reaction are required' });
  }

  try {
    const message = await service.client.getMessageById(messageId);
    if (!message) {
      return res.status(404).json({ success: false, error: 'Message not found' });
    }

    await message.react(reaction);

    return res.json({ success: true });
  } catch (err) {
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
    const media = new MessageMedia(
      mimetype || req.file.mimetype,
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
    if (sendVoice) {
      options.sendAudioAsVoice = true;
    }
    const sent = await service.client.sendMessage(to, media, options);

    return res.json({ success: true, messageId: sent.id?._serialized, timestamp: sent.timestamp });
  } catch (err) {
    return res.status(500).json({ success: false, error: err.message });
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
