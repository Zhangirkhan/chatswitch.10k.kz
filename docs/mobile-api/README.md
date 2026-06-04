# Accel Mobile API v1

REST API для Flutter-приложения Accel. Каждый тенант (компания) обслуживается на поддомене:

```
https://{slug}.accel.kz/api/v1/...
```

OpenAPI-спека: [`openapi/mobile-v1.yaml`](../../openapi/mobile-v1.yaml)  
Swagger UI (на поддомене тенанта): `https://{slug}.accel.kz/docs/api`

**Полный каталог функций по ролям (Flutter):** [FEATURES_BY_ROLE.md](./FEATURES_BY_ROLE.md)

**Мобильный UI (tab bar, экраны, вёрстка веба, API/пробелы):** [FLUTTER_MOBILE_UI.md](./FLUTTER_MOBILE_UI.md)

**Внедрение в Flutter (пошагово: backend P0/P1, контракты JSON, QA):** [MOBILE_IMPLEMENTATION_GUIDE.md](./MOBILE_IMPLEMENTATION_GUIDE.md)

**Перевод сообщений и AI-чипы в чате (Перевести / Ответить / улучшить текст):** [CHAT_TRANSLATION_AND_AI_HINTS.md](./CHAT_TRANSLATION_AND_AI_HINTS.md)

## Быстрый старт

### 1. Проверка workspace

```bash
curl -s "https://demo.accel.kz/api/v1/workspace" | jq
```

Ответ:

```json
{
  "data": {
    "id": 1,
    "slug": "demo",
    "name": "Demo Company",
    "is_active": true,
    "subscription_status": "active"
  }
}
```

### 2. Логин

Email/password или PIN — см. [PIN.md](./PIN.md).

```bash
curl -s -X POST "https://demo.accel.kz/api/v1/auth/login" \
  -H "Content-Type: application/json" \
  -H "Accept: application/json" \
  -d '{"email":"user@example.com","password":"secret"}' | jq
```

Ответ содержит `token`, `tenant`, `user`.

### 3. Авторизованные запросы

```bash
TOKEN="..."
curl -s "https://demo.accel.kz/api/v1/chats" \
  -H "Authorization: Bearer $TOKEN" \
  -H "Accept: application/json" | jq
```

## Мультитенантность

| Правило | Описание |
|---------|----------|
| Base URL | Всегда `https://{slug}.{root_domain}` — slug вводит пользователь |
| Логин | Email/password проверяются **только** внутри company_id текущего тенанта |
| Suspended | `tenant.active` middleware — 403 если подписка приостановлена |
| Токен | Laravel Sanctum Personal Access Token, заголовок `Authorization: Bearer` |

## Realtime (Reverb)

1. После логина сохраните `tenant.id` (company_id) и `user.id`.
2. Подключитесь к WebSocket Reverb (host/port из env сервера).
3. Авторизация каналов:

```bash
curl -s -X POST "https://demo.accel.kz/broadcasting/auth" \
  -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"channel_name":"private-t.1.chat.42","socket_id":"123.456"}'
```

### Каналы

| Канал | Назначение |
|-------|------------|
| `private-t.{companyId}.chat.{chatId}` | Сообщения, typing, реакции в WhatsApp-чате |
| `private-t.{companyId}.chats.list.{userId}` | Обновление списка чатов |
| `private-t.{companyId}.team-conversation.{id}` | Team chat комната |
| `private-t.{companyId}.team-inbox.{userId}` | Inbox team chat |

### События

- `.message.received` — новое сообщение в WhatsApp-чате
- `.user.typing` — оператор печатает
- `.message.reactions` — реакции обновлены
- `.team.message` — сообщение в team chat
- `.team.typing` — typing в team chat

## Основные группы эндпоинтов

| Префикс | Описание |
|---------|----------|
| `/api/v1/workspace` | Публичная информация о тенанте |
| `/api/v1/auth/*` | login, **login/pin**, logout, me |
| `/api/v1/chats/*` | WhatsApp-диалоги (в т.ч. `translate-draft`, `ai/chat`) |
| `/api/v1/messages/*` | Реакции, forward, translate |
| `/api/v1/media/{id}` | Скачивание вложений (Bearer) |
| `/api/v1/team-chat/*` | Внутренний чат |
| `/api/v1/contacts/*` | Клиенты |
| `/api/v1/funnels/*` | Воронки (CRUD — administrator) |
| `/api/v1/analytics/*` | Аналитика |
| `/api/v1/calendar/*` | Календарь |
| `/api/v1/ai-chat/*` | AI workspace |
| `/api/v1/chats/{id}/ai/chat` | AI-ассистент в чате |
| `/api/v1/settings` | Настройки и флаги модулей |
| `/api/v1/whatsapp/sessions` | Статус WhatsApp-сессий |

Полный список маршрутов:

```bash
php artisan route:list --path=api/v1
```

## Коды ошибок

| HTTP | Значение |
|------|----------|
| 401 | Нет или недействительный токен |
| 403 | Нет роли, деактивирован user/tenant, нет policy |
| 404 | Tenant/chat/contact не найден |
| 422 | Ошибка валидации |
| 429 | Rate limit |

## Локальная разработка

```env
TENANCY_ROOT_DOMAIN=accel.test
```

Эмулятор Android: пропишите `{slug}.accel.test` → IP dev-машины в `/etc/hosts` или используйте реальный staging-домен.

## Тесты

```bash
php artisan test tests/Feature/Api/V1/
```
