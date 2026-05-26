require('dotenv').config({ path: require('path').join(__dirname, '..', '.env') });

const express = require('express');
const cors = require('cors');
const bodyParser = require('body-parser');
const morgan = require('morgan');
const fs = require('fs');
const path = require('path');
const axios = require('axios');
const apiRoutes = require('./api/routes');
const { getOrCreateClient, destroyAll } = require('./whatsapp/clientManager');
const { AUTH_DIR } = require('./whatsapp/clientConfig');
const { sweepStaleLocksOnStartup } = require('./whatsapp/sessionProfileCleanup');

const app = express();
const PORT = parseInt(process.env.PORT || '3050', 10);

app.use(cors());
app.use(bodyParser.json({ limit: '50mb' }));
app.use(bodyParser.urlencoded({ extended: true, limit: '50mb' }));
app.use(morgan('short'));

app.get('/health', (_req, res) => {
  // /health не должен побочно создавать WhatsAppClient — иначе
  // в списке сессий появляется бесполезный "default", который никто не просил.
  res.json({
    status: 'ok',
    uptime: process.uptime(),
  });
});

app.use('/api', apiRoutes);

app.use((err, _req, res, _next) => {
  console.error('[ERROR]', err.message);
  res.status(500).json({ success: false, error: err.message });
});

/**
 * Получить у Laravel список «легальных» session_name. Нужен, чтобы на старте
 * не поднимать phantom-сессии (папка в .wwebjs_auth есть, а в БД — нет; такие
 * бесконечно крутят QR, бессмысленно съедая CPU/RAM и пишут шум в логи).
 *
 * @returns {Promise<Set<string>|null>} null — если Laravel недоступен (тогда
 *   лучше не рисковать удалением и оставить всё как есть).
 */
async function fetchLegalSessions() {
  const baseUrl = (process.env.LARAVEL_URL || '').replace(/\/+$/, '');
  const token = process.env.LARAVEL_API_TOKEN || '';
  if (!baseUrl || !token) return null;

  try {
    const { data } = await axios.get(`${baseUrl}/api/whatsapp/legal-sessions`, {
      headers: { Authorization: `Bearer ${token}` },
      timeout: 5000,
    });
    const list = Array.isArray(data?.sessions) ? data.sessions : [];
    return new Set(list);
  } catch (err) {
    console.warn(`[STARTUP] legal-sessions fetch failed (${err.message}); will restore every dir`);
    return null;
  }
}

function removeSessionDir(sessionName) {
  const dir = path.join(AUTH_DIR, `session-${sessionName}`);
  try {
    fs.rmSync(dir, { recursive: true, force: true });
    console.log(`[STARTUP] phantom session purged: ${sessionName}`);
  } catch (e) {
    console.warn(`[STARTUP] failed to remove phantom ${sessionName}: ${e.message}`);
  }
}

/**
 * Scan AUTH_DIR for saved sessions and auto-initialize all of them.
 * Directories are named "session-{sessionName}".
 * Проверяем каждую против БД Laravel: phantom-директории удаляем.
 */
async function autoRestoreAllSessions() {
  if (!fs.existsSync(AUTH_DIR)) return;

  let dirs;
  try {
    dirs = fs.readdirSync(AUTH_DIR);
  } catch (e) {
    console.error('[STARTUP] Failed to read auth dir:', e.message);
    return;
  }

  const PREFIX = 'session-';
  let sessionNames = dirs
    .filter((d) => d.startsWith(PREFIX))
    .map((d) => d.slice(PREFIX.length))
    .filter(Boolean);

  if (sessionNames.length === 0) {
    console.log('[STARTUP] No saved sessions found.');
    return;
  }

  const legal = await fetchLegalSessions();
  if (legal) {
    const phantoms = sessionNames.filter((n) => !legal.has(n));
    if (phantoms.length) {
      console.log(`[STARTUP] removing ${phantoms.length} phantom session(s): ${phantoms.join(', ')}`);
      phantoms.forEach(removeSessionDir);
    }
    sessionNames = sessionNames.filter((n) => legal.has(n));
  }

  if (sessionNames.length === 0) {
    console.log('[STARTUP] No legal sessions to restore.');
    return;
  }

  console.log(`[STARTUP] Restoring ${sessionNames.length} session(s): ${sessionNames.join(', ')}`);

  // Запускаем по очереди с паузой: параллельный старт нескольких Chromium на одном хосте
  // даёт сбои вида "browser is already running" / lock на профиле.
  for (let i = 0; i < sessionNames.length; i += 1) {
    const name = sessionNames[i];
    const client = getOrCreateClient(name);
    client.initialize().catch((err) =>
      console.error(`[STARTUP] [${name}] auto-init failed:`, err.message)
    );
    if (i < sessionNames.length - 1) {
      await new Promise((r) => setTimeout(r, 2500));
    }
  }
}

app.listen(PORT, '127.0.0.1', () => {
  console.log(`[Accel WA Service] running on port ${PORT}`);
  // Снимаем «осиротевшие» SingletonLock от предыдущего запуска Node (pm2 restart / crash),
  // иначе новый Chromium не сможет открыть userDataDir.
  try {
    sweepStaleLocksOnStartup(AUTH_DIR);
  } catch (e) {
    console.error('[STARTUP] sweepStaleLocksOnStartup failed:', e.message);
  }
  // Small delay so the HTTP server is fully up before starting browser instances
  setTimeout(() => {
    autoRestoreAllSessions().catch((err) => {
      console.error('[STARTUP] autoRestoreAllSessions failed:', err.message);
    });
  }, 500);
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
