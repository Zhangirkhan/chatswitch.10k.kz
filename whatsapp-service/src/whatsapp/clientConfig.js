const path = require('path');
const { LocalAuth } = require('whatsapp-web.js');

const AUTH_DIR = path.join(__dirname, '..', '..', '.wwebjs_auth');
const CACHE_DIR = path.join(__dirname, '..', '..', '.wwebjs_cache');

function buildClientOptions(sessionName) {
  return {
    authStrategy: new LocalAuth({
      clientId: sessionName,
      dataPath: AUTH_DIR,
    }),
    puppeteer: {
      headless: true,
      args: [
        '--no-sandbox',
        '--disable-setuid-sandbox',
        '--disable-dev-shm-usage',
        '--disable-accelerated-2d-canvas',
        '--no-first-run',
        '--disable-gpu',
        '--single-process',
      ],
      executablePath: process.env.PUPPETEER_EXECUTABLE_PATH || undefined,
    },
    webVersionCache: {
      type: 'local',
      path: CACHE_DIR,
    },
  };
}

module.exports = { buildClientOptions, AUTH_DIR };
