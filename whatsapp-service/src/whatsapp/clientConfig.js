const path = require('path');
const fs = require('fs');
const { LocalAuth } = require('whatsapp-web.js');

const AUTH_DIR = path.join(__dirname, '..', '..', '.wwebjs_auth');
const CACHE_DIR = path.join(__dirname, '..', '..', '.wwebjs_cache');

const USER_AGENT = 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36';

/**
 * Проверить, является ли путь snap-версией Chromium. Такие бинарники
 * принудительно подменяют --user-data-dir на /root/snap/...  и не годятся
 * для многосессионного whatsapp-web.js.
 */
function isSnapChromium(p) {
  if (!p) return false;
  try {
    const real = fs.realpathSync(p);
    return /\/snap\//.test(real) || /chromium-browser$/.test(real);
  } catch (_) {
    return /chromium-browser$/.test(p) || /\/snap\//.test(p);
  }
}

/**
 * Найти подходящий Chrome-бинарник. Нам нужен НЕ-snap Chrome, потому что
 * snap-версия игнорирует --user-data-dir и все сессии падают в один профиль
 * (отсюда «The browser is already running for …/session-XXX»).
 *
 * Приоритет:
 *   1. PUPPETEER_EXECUTABLE_PATH (если это не snap и файл существует)
 *   2. Последняя версия из ~/.cache/puppeteer/chrome/linux-*
 *   3. /usr/bin/google-chrome-stable / /usr/bin/google-chrome
 *   4. undefined — пусть Puppeteer сам разбирается
 *
 * @returns {string|undefined}
 */
function resolveChromeExecutable() {
  const envPath = process.env.PUPPETEER_EXECUTABLE_PATH;
  if (envPath && fs.existsSync(envPath) && !isSnapChromium(envPath)) {
    return envPath;
  }
  if (envPath && isSnapChromium(envPath)) {
    console.warn(
      `[clientConfig] PUPPETEER_EXECUTABLE_PATH=${envPath} is snap Chromium; ignoring (it breaks --user-data-dir).`
    );
  }

  const puppeteerCache = path.join(process.env.HOME || '/root', '.cache', 'puppeteer', 'chrome');
  if (fs.existsSync(puppeteerCache)) {
    try {
      const versions = fs
        .readdirSync(puppeteerCache)
        .filter((d) => d.startsWith('linux-'))
        .sort()
        .reverse();
      for (const v of versions) {
        const bin = path.join(puppeteerCache, v, 'chrome-linux64', 'chrome');
        if (fs.existsSync(bin)) {
          return bin;
        }
      }
    } catch (_) { /* ignore */ }
  }

  for (const p of ['/usr/bin/google-chrome-stable', '/usr/bin/google-chrome']) {
    if (fs.existsSync(p) && !isSnapChromium(p)) return p;
  }

  return undefined;
}

const RESOLVED_CHROME = resolveChromeExecutable();
if (RESOLVED_CHROME) {
  console.log(`[clientConfig] using Chrome binary: ${RESOLVED_CHROME}`);
} else {
  console.warn('[clientConfig] no Chrome binary resolved; puppeteer will use its default.');
}

function buildClientOptions(sessionName) {
  const sessionCacheDir = path.join(CACHE_DIR, String(sessionName).replace(/[^a-zA-Z0-9-_]/g, '_'));

  return {
    authStrategy: new LocalAuth({
      clientId: sessionName,
      dataPath: AUTH_DIR,
    }),
    userAgent: USER_AGENT,
    puppeteer: {
      headless: true,
      args: [
        '--no-sandbox',
        '--disable-setuid-sandbox',
        '--disable-dev-shm-usage',
        '--disable-accelerated-2d-canvas',
        '--no-first-run',
        '--disable-gpu',
        '--disable-extensions',
        '--disable-background-networking',
        '--disable-default-apps',
        '--disable-sync',
        '--disable-translate',
        '--metrics-recording-only',
        '--mute-audio',
        '--no-first-run',
        '--safebrowsing-disable-auto-update',
        '--disable-background-timer-throttling',
        '--disable-backgrounding-occluded-windows',
        '--disable-renderer-backgrounding',
        '--js-flags=--max-old-space-size=256',
      ],
      executablePath: RESOLVED_CHROME,
    },
    webVersionCache: {
      type: 'local',
      path: sessionCacheDir,
    },
  };
}

module.exports = { buildClientOptions, AUTH_DIR, resolveChromeExecutable };
