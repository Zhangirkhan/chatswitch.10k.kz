# ChatSwitch

Веб-приложение для работы с несколькими номерами WhatsApp: чаты, сообщения, медиа, назначения сотрудников, настройки подключений. Бэкенд — **Laravel 11**, интерфейс — **Vue 3 + Inertia**, в реальном времени — **Laravel Reverb** (при необходимости). Отдельный сервис **`whatsapp-service/`** (Node.js, whatsapp-web.js) обменивается данными с Laravel.

## Требования

- PHP **8.2+** с расширениями из стандартного набора Laravel (pdo, mbstring, openssl, tokenizer, xml, ctype, json и др.)
- Composer 2
- Node.js **18+** и npm
- База данных (MySQL/MariaDB или SQLite — как настроено в `.env`)
- Redis (если включены очереди/кэш по проекту)

## Установка

```bash
composer install
cp .env.example .env
php artisan key:generate
```

Настройте `.env` (БД, `APP_URL`, при необходимости Reverb, Redis, очереди).

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

## Запуск приложения

```bash
php artisan serve
```

При использовании веб-сокетов и очередей запускайте соответствующие процессы из документации Laravel (Reverb, `queue:work` и т.д.).

## Сервис WhatsApp

Каталог **`whatsapp-service/`** — отдельное Node-приложение для сессий WhatsApp. См. `README` или инструкции внутри каталога при развёртывании.

- Несколько номеров и один IP / внешние адреса: [docs/WHATSAPP_MULTI_NUMBER_AND_IP.md](docs/WHATSAPP_MULTI_NUMBER_AND_IP.md)
- Удалить старые демо-сессии из БД (`demo-main`, `demo-sales`, `demo-support`): `php artisan whatsapp:purge-demo-sessions --force`

## Очистка чатов и контактов

Полное удаление **всех** чатов (включая сообщения, реакции, вложения в БД, назначения, связи с отделами) и **всех** контактов. Файлы вложений удаляются с диска **`local`** по путям из `message_media`.

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

## Прочие команды

```bash
vendor/bin/pint              # стиль PHP (Pint)
php artisan test             # PHPUnit
php artisan phones:normalize # нормализация телефонов в данных (см. `--dry-run`)
```

## Лицензия

Проект основан на шаблоне Laravel; фреймворк распространяется под [MIT](https://opensource.org/licenses/MIT). Лицензия конечного продукта уточняется у владельца репозитория.
