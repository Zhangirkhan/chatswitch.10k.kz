require('dotenv').config({ path: require('path').join(__dirname, '..', '.env') });

const express = require('express');
const cors = require('cors');
const bodyParser = require('body-parser');
const morgan = require('morgan');
const apiRoutes = require('./api/routes');
const { getOrCreateClient, destroyAll, getDefaultSessionName } = require('./whatsapp/clientManager');

const app = express();
const PORT = parseInt(process.env.PORT || '3050', 10);

app.use(cors());
app.use(bodyParser.json({ limit: '50mb' }));
app.use(bodyParser.urlencoded({ extended: true, limit: '50mb' }));
app.use(morgan('short'));

app.get('/health', (_req, res) => {
  const defaultClient = getOrCreateClient(getDefaultSessionName());
  res.json({
    status: 'ok',
    whatsapp: defaultClient?.isReady ? 'connected' : 'disconnected',
    uptime: process.uptime(),
  });
});

app.use('/api', apiRoutes);

app.use((err, _req, res, _next) => {
  console.error('[ERROR]', err.message);
  res.status(500).json({ success: false, error: err.message });
});

app.listen(PORT, '127.0.0.1', () => {
  console.log(`[ChatSwitch WA Service] running on port ${PORT}`);
  const defaultSession = getDefaultSessionName();
  const client = getOrCreateClient(defaultSession);
  client.checkExistingSession();
});

process.on('SIGINT', async () => {
  console.log('[SHUTDOWN] destroying all clients...');
  await destroyAll();
  process.exit(0);
});

process.on('SIGTERM', async () => {
  console.log('[SHUTDOWN] destroying all clients...');
  await destroyAll();
  process.exit(0);
});
