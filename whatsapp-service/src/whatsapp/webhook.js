const axios = require('axios');

const laravelUrl = (process.env.LARAVEL_URL || 'http://127.0.0.1').replace(/\/+$/, '');
const apiToken = process.env.LARAVEL_API_TOKEN || '';

async function notifyLaravel(event, data) {
  const url = `${laravelUrl}/api/whatsapp/webhook`;

  try {
    await axios.post(
      url,
      { event, data },
      {
        headers: {
          'Content-Type': 'application/json',
          ...(apiToken ? { Authorization: `Bearer ${apiToken}` } : {}),
        },
        timeout: 30000,
        maxBodyLength: 100 * 1024 * 1024,
      }
    );
  } catch (err) {
    console.error(`[webhook] ${event} failed:`, err.message);
  }
}

module.exports = { notifyLaravel };
