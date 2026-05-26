const https = require('https');

function laravelHostHeader() {
  const explicit = (process.env.LARAVEL_HOST || '').trim();
  if (explicit !== '') {
    return explicit;
  }

  const publicUrl = (process.env.LARAVEL_PUBLIC_URL || 'https://accel.kz').replace(/\/+$/, '');
  try {
    return new URL(publicUrl).host;
  } catch {
    return 'accel.kz';
  }
}

/** Axios options for calls to Laravel on the same host (127.0.0.1 + self-signed cert). */
function laravelAxiosOptions(extra = {}) {
  const baseUrl = (process.env.LARAVEL_URL || 'http://127.0.0.1').replace(/\/+$/, '');
  const options = { ...extra };

  try {
    const host = new URL(baseUrl).hostname;
    if (host === '127.0.0.1' || host === 'localhost') {
      options.httpsAgent = new https.Agent({ rejectUnauthorized: false });
      options.headers = {
        Host: laravelHostHeader(),
        ...(extra.headers || {}),
      };
    }
  } catch {
    // ignore malformed LARAVEL_URL
  }

  return options;
}

module.exports = { laravelAxiosOptions, laravelHostHeader };
