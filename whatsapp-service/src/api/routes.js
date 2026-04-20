const express = require('express');
const QRCode = require('qrcode');
const {
  getOrCreateClient,
  getAllClients,
  resolveSessionName,
  removeClient,
} = require('../whatsapp/clientManager');

const router = express.Router();

function authMiddleware(req, res, next) {
  const token = (req.headers.authorization || '').replace('Bearer ', '');
  const expected = process.env.LARAVEL_API_TOKEN;
  if (!expected || token !== expected) {
    return res.status(401).json({ success: false, error: 'Unauthorized' });
  }
  next();
}

router.use(authMiddleware);

router.get('/sessions', (_req, res) => {
  res.json({ success: true, sessions: getAllClients() });
});

router.post('/sessions/:name/initialize', async (req, res) => {
  const client = getOrCreateClient(req.params.name);
  if (client.isReady) {
    return res.json({ success: true, message: 'Already connected' });
  }
  client.initialize().catch((err) =>
    console.error(`[${req.params.name}] init error:`, err.message)
  );
  res.json({ success: true, message: 'Initializing' });
});

router.get('/sessions/:name/qr', async (req, res) => {
  const client = getOrCreateClient(req.params.name);
  if (client.isReady) {
    return res.json({ success: true, qr: null, message: 'Already connected' });
  }
  const qr = client.getQRCode();
  if (!qr) {
    return res.json({ success: true, qr: null, message: 'QR not available yet' });
  }
  try {
    const qrImage = await QRCode.toDataURL(qr, { width: 300 });
    res.json({ success: true, qr: qrImage });
  } catch (err) {
    res.status(500).json({ success: false, error: err.message });
  }
});

router.get('/sessions/:name/status', (req, res) => {
  const client = getOrCreateClient(req.params.name);
  res.json({
    success: true,
    sessionName: req.params.name,
    isReady: client.isReady,
    isInitializing: client.isInitializing,
    hasQR: !!client.qrCode,
  });
});

router.post('/sessions/:name/logout', async (req, res) => {
  const client = getOrCreateClient(req.params.name);
  await client.logout();
  removeClient(req.params.name);
  res.json({ success: true, message: 'Logged out' });
});

router.post('/sessions/:name/destroy', async (req, res) => {
  const client = getOrCreateClient(req.params.name);
  await client.destroy();
  removeClient(req.params.name);
  res.json({ success: true, message: 'Destroyed' });
});

router.post('/send-message', async (req, res) => {
  const sessionName = resolveSessionName(req);
  const client = getOrCreateClient(sessionName);
  if (!client.isReady) {
    return res.status(400).json({ success: false, error: 'Client not ready' });
  }
  const { to, message, quotedMessageId } = req.body;
  try {
    const options = {};
    if (quotedMessageId) options.quotedMessageId = quotedMessageId;
    const sent = await client.client.sendMessage(to, message, options);
    res.json({
      success: true,
      messageId: sent.id?._serialized,
      timestamp: sent.timestamp,
    });
  } catch (err) {
    res.status(500).json({ success: false, error: err.message });
  }
});

router.post('/send-media', async (req, res) => {
  const sessionName = resolveSessionName(req);
  const client = getOrCreateClient(sessionName);
  if (!client.isReady) {
    return res.status(400).json({ success: false, error: 'Client not ready' });
  }
  const { to, mediaData, mimetype, filename, caption } = req.body;
  try {
    const { MessageMedia } = require('whatsapp-web.js');
    const media = new MessageMedia(mimetype, mediaData, filename);
    const options = {};
    if (caption) options.caption = caption;
    const sent = await client.client.sendMessage(to, media, options);
    res.json({ success: true, messageId: sent.id?._serialized });
  } catch (err) {
    res.status(500).json({ success: false, error: err.message });
  }
});

router.post('/send-seen', async (req, res) => {
  const sessionName = resolveSessionName(req);
  const client = getOrCreateClient(sessionName);
  if (!client.isReady) {
    return res.status(400).json({ success: false, error: 'Client not ready' });
  }
  try {
    const chat = await client.client.getChatById(req.body.chatId);
    await chat.sendSeen();
    res.json({ success: true });
  } catch (err) {
    res.status(500).json({ success: false, error: err.message });
  }
});

router.post('/set-typing', async (req, res) => {
  const sessionName = resolveSessionName(req);
  const client = getOrCreateClient(sessionName);
  if (!client.isReady) {
    return res.status(400).json({ success: false, error: 'Client not ready' });
  }
  try {
    const chat = await client.client.getChatById(req.body.chatId);
    if (req.body.isTyping) {
      await chat.sendStateTyping();
    } else {
      await chat.clearState();
    }
    res.json({ success: true });
  } catch (err) {
    res.status(500).json({ success: false, error: err.message });
  }
});

router.get('/messages/:chatId', async (req, res) => {
  const sessionName = resolveSessionName(req);
  const client = getOrCreateClient(sessionName);
  if (!client.isReady) {
    return res.status(400).json({ success: false, error: 'Client not ready' });
  }
  try {
    const chat = await client.client.getChatById(req.params.chatId);
    const limit = parseInt(req.query.limit || '50', 10);
    const messages = await chat.fetchMessages({ limit });
    res.json({
      success: true,
      messages: messages.map((m) => ({
        id: m.id?._serialized,
        from: m.from,
        to: m.to,
        body: m.body,
        type: m.type,
        timestamp: m.timestamp,
        fromMe: m.fromMe,
        hasMedia: m.hasMedia,
        ack: m.ack,
      })),
    });
  } catch (err) {
    res.status(500).json({ success: false, error: err.message });
  }
});

router.get('/contacts', async (req, res) => {
  const sessionName = resolveSessionName(req);
  const client = getOrCreateClient(sessionName);
  if (!client.isReady) {
    return res.status(400).json({ success: false, error: 'Client not ready' });
  }
  try {
    const contacts = await client.client.getContacts();
    res.json({
      success: true,
      contacts: contacts.map((c) => ({
        id: c.id?._serialized,
        number: c.number,
        name: c.name,
        pushname: c.pushname,
        isMyContact: c.isMyContact,
        isBusiness: c.isBusiness,
      })),
    });
  } catch (err) {
    res.status(500).json({ success: false, error: err.message });
  }
});

router.get('/profile-pic/:contactId', async (req, res) => {
  const sessionName = resolveSessionName(req);
  const client = getOrCreateClient(sessionName);
  if (!client.isReady) {
    return res.status(400).json({ success: false, error: 'Client not ready' });
  }
  try {
    const url = await client.client.getProfilePicUrl(req.params.contactId);
    res.json({ success: true, url: url || null });
  } catch (err) {
    res.json({ success: true, url: null });
  }
});

module.exports = router;
