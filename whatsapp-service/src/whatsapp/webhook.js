const axios = require('axios');
const crypto = require('crypto');
const { laravelAxiosOptions } = require('../laravelHttp');

const laravelUrl = (process.env.LARAVEL_URL || 'http://127.0.0.1').replace(/\/+$/, '');
const apiToken = process.env.LARAVEL_API_TOKEN || '';
const webhookSecret = process.env.WHATSAPP_WEBHOOK_SECRET || '';

function signBody(rawBody) {
  if (!webhookSecret) {
    return null;
  }
  return crypto.createHmac('sha256', webhookSecret).update(rawBody).digest('hex');
}

async function notifyLaravel(event, data) {
  const url = `${laravelUrl}/api/whatsapp/webhook`;
  const payload = JSON.stringify({ event, data });
  const signature = signBody(payload);

  try {
    const res = await axios.post(url, payload, {
      ...laravelAxiosOptions({
        headers: {
          'Content-Type': 'application/json',
          ...(apiToken ? { Authorization: `Bearer ${apiToken}` } : {}),
          ...(signature ? { 'X-Webhook-Signature': signature } : {}),
        },
      }),
      timeout: 30000,
      maxBodyLength: 100 * 1024 * 1024,
      transformRequest: [(v) => v],
      validateStatus: () => true,
    });
    if (res.status >= 400) {
      console.error(`[webhook] ${event} → ${res.status} ${JSON.stringify(res.data).slice(0, 300)}`);
    }
  } catch (err) {
    console.error(`[webhook] ${event} failed:`, err.message);
  }
}

module.exports = { notifyLaravel };
