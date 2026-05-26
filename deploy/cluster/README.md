# Release-кластер Accel

Схема деплоя без простоя: код живёт в **релизах**, nginx/supervisor смотрят на стабильный симлинк `/var/www/accel.kz`, данные — в **shared**.

```
/var/www/accel/
├── shared/
│   ├── .env
│   ├── backups/mysql/    # почасовые mysqldump, хранение 5 ч (cron)
│   ├── storage/          # логи, uploads, sessions
│   └── bootstrap/cache/
├── releases/
│   ├── 20260522_120000/
│   └── 20260522_150000/   ← активный после deploy
└── deploy/config.env

/var/www/accel.kz  →  /var/www/accel/releases/20260522_150000
```

## Почему это удобно

1. **Сборка фронта до переключения** — `npm run build` в новом релизе; пользователи не видят 500 из‑за отсутствующего `manifest.json`.
2. **Откат за секунду** — `rollback.sh` переключает симлинк на предыдущий релиз.
3. **Очередь и Reverb** — supervisor перезапускается после switch; пути в конфигах не меняются (`/var/www/accel.kz`).

## Однократная инициализация

```bash
sudo chmod +x /var/www/accel.kz/deploy/cluster/*.sh
sudo /var/www/accel.kz/deploy/cluster/init.sh
```

Переносит текущий `/var/www/accel.kz` в первый релиз и создаёт `shared`.

## Деплой обновления

```bash
# из git (ветка из config.env)
sudo /var/www/accel.kz/deploy/cluster/deploy.sh

# или конкретный ref
sudo /var/www/accel.kz/deploy/cluster/deploy.sh main
```

Шаги скрипта: rsync → `composer` + `npm run build` → `migrate` → `optimize` → симлинк → reload PHP-FPM / supervisor.

## Откат

```bash
sudo /var/www/accel.kz/deploy/cluster/rollback.sh
# или явно:
sudo /var/www/accel.kz/deploy/cluster/rollback.sh /var/www/accel/releases/20260522_120000
```

## PHP-FPM (отдельный пул)

```bash
sudo /var/www/accel.kz/deploy/php-fpm/install-pool.sh
```

Сокет: `/run/php/php8.3-fpm-accel.sock`, до 24 воркеров — не конкурирует с другими сайтами на `www.sock`.

## Кластер очередей (supervisor)

| Program | Очереди | Воркеры |
|---------|---------|---------|
| `accel-queue-default` | whatsapp, default | 4 |
| `accel-queue-provisioning` | provisioning | 2 |

```bash
sudo cp deploy/supervisor/accel-queue*.conf /etc/supervisor/conf.d/
sudo supervisorctl reread && sudo supervisorctl update
```

## Конфиг

```bash
sudo mkdir -p /var/www/accel/deploy
sudo cp deploy/cluster/config.env.example /var/www/accel/deploy/config.env
```

Переменная `RESTART_WHATSAPP=1` — перезапуск systemd-сервиса WhatsApp после деплоя (если настроен).
