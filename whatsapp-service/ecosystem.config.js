module.exports = {
  apps: [
    {
      name: 'chatswitch-whatsapp',
      script: 'src/index.js',
      cwd: __dirname,
      instances: 1,
      exec_mode: 'fork',
      node_args: '--max-old-space-size=2048',
      env: {
        NODE_ENV: 'production',
        PUPPETEER_CACHE_DIR: `${__dirname}/.cache/puppeteer`,
      },
      log_date_format: 'YYYY-MM-DD HH:mm:ss',
      error_file: './logs/error.log',
      out_file: './logs/out.log',
      merge_logs: true,
    },
  ],
};
