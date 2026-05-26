const axios = require('axios');
const FormData = require('form-data');
const { laravelAxiosOptions } = require('../laravelHttp');

const laravelUrl = (process.env.LARAVEL_URL || 'http://127.0.0.1').replace(/\/+$/, '');
const apiToken = process.env.LARAVEL_API_TOKEN || '';

/**
 * Загружает бинарник медиа отдельным multipart-запросом (обходит лимиты JSON-тела вебхука).
 *
 * @param {{ sessionName: string }} service
 * @param {string} waMessageId serialized WA message id
 * @param {{ mimetype?: string, filename?: string|null, data: string }} media base64 data from downloadMedia()
 */
async function uploadInboundMediaBuffer(service, waMessageId, media) {
  if (!apiToken) {
    console.error('[inbound-media] LARAVEL_API_TOKEN is empty');
    return;
  }

  const url = `${laravelUrl}/api/whatsapp/inbound-media`;
  let buffer;
  try {
    buffer = Buffer.from(media.data, 'base64');
  } catch (e) {
    console.error('[inbound-media] invalid base64:', e.message);
    return;
  }

  const mime = media.mimetype || 'application/octet-stream';
  const extFromMime = (mime.split(';')[0].split('/')[1] || 'bin').replace(/\W/g, '') || 'bin';
  const uploadName = media.filename && String(media.filename).trim()
    ? String(media.filename).trim()
    : `media.${extFromMime}`;

  const maxAttempts = 24;
  const delayMs = 500;

  for (let attempt = 1; attempt <= maxAttempts; attempt += 1) {
    const form = new FormData();
    form.append('session', service.sessionName);
    form.append('messageId', waMessageId);
    form.append('mimetype', mime);
    form.append('file', buffer, { filename: uploadName, contentType: mime.split(';')[0].trim() });

    try {
      const res = await axios.post(url, form, {
        ...laravelAxiosOptions({
          headers: {
            ...form.getHeaders(),
            Authorization: `Bearer ${apiToken}`,
          },
        }),
        timeout: 120000,
        maxBodyLength: Infinity,
        maxContentLength: Infinity,
        validateStatus: () => true,
      });

      if (res.status === 200) {
        return;
      }

      const retry = res.data && (res.data.retry === true || res.data.status === 'message_not_found');
      if (res.status === 404 && retry && attempt < maxAttempts) {
        await new Promise((r) => setTimeout(r, delayMs));
        continue;
      }

      console.error(
        `[${service.sessionName}] inbound-media ${res.status}:`,
        typeof res.data === 'object' ? JSON.stringify(res.data).slice(0, 400) : String(res.data).slice(0, 400),
      );
      return;
    } catch (err) {
      console.error(`[${service.sessionName}] inbound-media attempt ${attempt}:`, err.message);
      if (attempt < maxAttempts) {
        await new Promise((r) => setTimeout(r, delayMs));
      }
    }
  }
}

module.exports = { uploadInboundMediaBuffer };
