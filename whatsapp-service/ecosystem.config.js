module.exports = {
  apps: [
    {
      name: 'accel-whatsapp',
      script: 'src/index.js',
      cwd: __dirname,
      instances: 1,
      exec_mode: 'fork',
      node_args: '--max-old-space-size=4096',
      max_memory_restart: '800M',
      env: {
        NODE_ENV: 'production',
        // Бинарник Chrome не задаём явно — src/whatsapp/clientConfig.js сам подберёт
        // НЕ-snap Chrome (Puppeteer-скачанный Chrome for Testing или системный
        // google-chrome-stable). Snap /usr/bin/chromium-browser непригоден,
        // т.к. AppArmor игнорирует --user-data-dir и все сессии дерутся за один профиль.
        PUPPETEER_SKIP_DOWNLOAD: 'true',
      },
      log_date_format: 'YYYY-MM-DD HH:mm:ss',
      error_file: './logs/error.log',
      out_file: './logs/out.log',
      merge_logs: true,
    },
  ],
};
