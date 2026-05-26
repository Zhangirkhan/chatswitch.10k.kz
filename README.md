# Accel

SaaS-платформа для работы с WhatsApp в команде: чаты, медиа, AI-ассистент, воронки, аналитика. Мульти-тенантность: каждая компания работает на собственном поддомене `<slug>.accel.kz`.

- **Бэкенд** — Laravel 11 (PHP 8.3)
- **Фронтенд** — Vue 3 + Inertia
- **Realtime** — Laravel Reverb (WebSocket)
- **WhatsApp-сервис** — отдельный Node.js-демон в `whatsapp-service/` (whatsapp-web.js)
- **Очередь** — `database` driver через supervisor (`accel-queue`)

## Архитектура хостов

| URL | Назначение |
|-----|------------|
| `https://accel.kz/` | Публичный лендинг + заявки на регистрацию |
| `https://accel.kz/404` | Красивая 404-страница (на неё же редиректит несуществующие поддомены) |
| `https://app.accel.kz/` | Супер-админка (компании, тарифы, биллинг, заявки) |
| `https://<slug>.accel.kz/` | Рабочее пространство конкретного тенанта |

Все три контекста обслуживает одно Laravel-приложение, домены различает middleware `tenant.resolve` + конфиг [`config/tenancy.php`](config/tenancy.php).

## Доступ в супер-админку

| Параметр | Значение |
|----------|----------|
| URL | <https://app.accel.kz/login> |
| Email | `super@accel.kz` |
| Пароль | `Super#Admin2026` |

> Это **временный** пароль. Сразу после первого входа замените его через профиль или Tinker:
>
> ```bash
> php artisan tinker --execute='\App\Models\User::withoutGlobalScope("tenant")->where("email","super@accel.kz")->first()->update(["password" => "ваш-новый-пароль"]);'
>
> Пароль хешируется автоматически (`casts.password = hashed`). Не используйте `Hash::make()` в `update()`.
> ```

В супер-админке вы можете:

- Создавать новые компании (поддомен выбирается при создании)
- Назначать тарифы, продлевать подписки, выставлять счета
- Управлять статусом тенантов (active / trial / suspended / canceled)
- Принимать заявки с лендинга (`tenant_signup_requests`)

При создании компании Laravel автоматически:

1. Создаёт `Company` + владельца с временным паролем (он отображается на странице после сохранения).
2. Запускает `IssueTenantCertificateJob` → выпускает SSL-cert через Let’s Encrypt и подключает nginx-блок для `<slug>.accel.kz`.
3. Через 5–15 секунд `https://<slug>.accel.kz/login` доступен.

Скрипт автоматизации — [`deploy/nginx/issue-tenant-cert.sh`](deploy/nginx/issue-tenant-cert.sh) (`sudoers` дает `www-data` право только на него).

## Требования

- PHP **8.3+** (`pdo`, `mbstring`, `openssl`, `tokenizer`, `xml`, `ctype`, `json`, …)
- Composer 2
- Node.js **20+** и npm
- MySQL / MariaDB
- Redis (опционально — для кэша/очередей)
- nginx + certbot (для production deploy с мульти-тенантностью)

## Установка

```bash
composer install
cp .env.example .env
php artisan key:generate
```

Настройте `.env` (БД, `APP_URL`, при необходимости Reverb, Redis):

```env
APP_URL=https://accel.kz
TENANCY_ROOT_DOMAIN=accel.kz
TENANCY_ADMIN_SUBDOMAIN=app
TENANCY_FALLBACK_SLUG=demo
SUPER_ADMIN_EMAIL=super@accel.kz
```

```bash
php artisan migrate
# опционально демо-данные
php artisan db:seed
```

## Фронтенд

```bash
npm install
npm run dev          # разработка (Vite)
npm run build        # production-сборка (vue-tsc + vite build)
```

## Деплой (release-кластер)

Production-обновления без простоя: несколько релизов кода, общие `storage` и `.env`, переключение симлинка `/var/www/accel.kz`.

```bash
# один раз
sudo deploy/cluster/init.sh

# каждое обновление
sudo deploy/cluster/deploy.sh

# откат
sudo deploy/cluster/rollback.sh
```

Подробнее: [`deploy/cluster/README.md`](deploy/cluster/README.md). Отдельный PHP-FPM pool: `sudo deploy/php-fpm/install-pool.sh`.

## Запуск приложения

```bash
php artisan serve
```

В production через supervisor поднимаются:

- `accel-queue-default` — обработчик очередей (`provisioning,whatsapp,default`)
- `accel-reverb` — WebSocket-сервер на `127.0.0.1:6001`

## Сервис WhatsApp

Каталог [`whatsapp-service/`](whatsapp-service/) — отдельное Node-приложение для сессий WhatsApp. См. инструкции внутри каталога.

- Несколько номеров и один IP / внешние адреса: [docs/WHATSAPP_MULTI_NUMBER_AND_IP.md](docs/WHATSAPP_MULTI_NUMBER_AND_IP.md)
- Удалить старые демо-сессии из БД (`demo-main`, `demo-sales`, `demo-support`): `php artisan whatsapp:purge-demo-sessions --force`

## Управление тенантами

```bash
# Выпустить SSL-сертификат и подключить nginx-блок вручную
php artisan tenant:issue-cert <slug>            # асинхронно (через очередь)
php artisan tenant:issue-cert <slug> --sync     # синхронно
php artisan tenant:issue-cert all               # для всех активных тенантов

# Несуществующий slug → редирект на https://accel.kz/ (nginx + Laravel)
php artisan tenants:sync-nginx-map --reload

# Wildcard *.accel.kz — убирает ERR_CERT_COMMON_NAME_INVALID на чужих поддоменах (DNS TXT)
sudo deploy/nginx/issue-wildcard-cert.sh
sudo deploy/nginx/install-wildcard-ssl.sh
```

## Очистка чатов и контактов

Полное удаление **всех** чатов (включая сообщения, реакции, вложения в БД, назначения, связи с отделами) и **всех** контактов конкретного тенанта. Файлы вложений удаляются с диска **`local`** по путям из `message_media`.

**Не удаляется:** пользователи, сессии WhatsApp, отделы, сообщества и прочие справочники.

### Artisan

```bash
# только посмотреть счётчики
php artisan chats:purge --dry-run

# локально — запрос подтверждения в консоли
php artisan chats:purge

# без вопроса; в production без этого флага команда не выполнится
php artisan chats:purge --force
```

Реализация: `app/Console/Commands/PurgeChatsAndContacts.php`.

### Shell-обёртка

```bash
chmod +x scripts/purge-chats-and-contacts.sh   # один раз
./scripts/purge-chats-and-contacts.sh --dry-run
./scripts/purge-chats-and-contacts.sh --force
```

Операция **необратима** — используйте `--dry-run` перед боевым запуском.

## Тарифы и подписки

| Параметр | Значение |
|----------|----------|
| Цена | **40 000 ₸ / месяц** (в БД: `price_cents = 4_000_000` тиын) |
| Триал | **14 дней** для новых компаний |
| Тариф по умолчанию | `standard` (код в `config/billing.php`) |

После триала в супер-админке (`app.accel.kz` → компания):

- **Оплатить (1 месяц)** — активирует подписку, в истории новая запись;
- **Отказаться** — статус `canceled`, история сохраняется;
- **Сменить тариф** — новая строка в таблице «История тарифов».

Команда для истёкших триалов (в cron каждый час): `php artisan subscriptions:expire-trials` → статус `past_due`.

```env
BILLING_DEFAULT_PLAN_CODE=standard
BILLING_TRIAL_DAYS=14
BILLING_STANDARD_PRICE_CENTS=4000000
```

## reCAPTCHA (вход)

На страницах **Вход** и **Забыли пароль** (тенант и `app.accel.kz`):

```env
RECAPTCHA_ENABLED=true
RECAPTCHA_SITE_KEY=ваш-site-key
RECAPTCHA_SECRET_KEY=ваш-secret-key
RECAPTCHA_VERSION=v3          # или v2 — виджет «Я не робот»
RECAPTCHA_MIN_SCORE=0.5       # только для v3
```

Ключи: [Google reCAPTCHA Admin](https://www.google.com/recaptcha/admin). Для v3 добавьте домены `accel.kz`, `*.accel.kz`, `app.accel.kz`.

После смены: `php artisan config:clear` и `npm run build`.

## Swagger / OpenAPI (Mobile API v1)

Спека: [`openapi/mobile-v1.yaml`](openapi/mobile-v1.yaml).

На поддомене тенанта: `https://<slug>.accel.kz/docs/api` — UI Swagger, `.../docs/api/openapi.yaml` — YAML.

Доступ по **HTTP Basic Auth** (не нужен вход в CRM):

| Переменная | По умолчанию | Описание |
|------------|--------------|----------|
| `DOCS_API_USERNAME` | `docs` | Логин в диалоге браузера |
| `DOCS_API_PASSWORD` | — | Пароль (обязателен в production) |

Пример в `.env`:

```env
DOCS_API_USERNAME=docs
DOCS_API_PASSWORD=ваш-секретный-пароль
```

После смены: `php artisan config:clear`.

## Прочие команды

```bash
vendor/bin/pint              # стиль PHP (Pint)
php artisan test             # PHPUnit
php artisan phones:normalize # нормализация телефонов в данных (см. `--dry-run`)
php artisan route:cache      # production-кэш роутов
php artisan optimize:clear   # сбросить все кэши
```

## Лицензия

Проект основан на шаблоне Laravel; фреймворк распространяется под [MIT](https://opensource.org/licenses/MIT). Лицензия конечного продукта уточняется у владельца репозитория.

В документацию
Логин docs
Пароль nznivue9pHFPRb8uiuBq