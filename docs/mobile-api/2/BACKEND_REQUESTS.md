# Backend API requests (Flutter mobile v1)

> **Подробное описание для backend/product:** см. [`MOBILE_API_NEEDS.md`](./MOBILE_API_NEEDS.md) — контракты, чеклист, инструкции для Flutter.

Запросы к backend-команде для закрытия пробелов, выявленных при порте веб-функций Accel CRM в Flutter.

## ✅ Реализовано на backend (2026-06-05)

Следующие пункты **готовы на сервере** — Flutter может убрать 404-workaround и client-side-only фильтры:

| Endpoint | Статус |
|----------|--------|
| `POST /api/v1/chats/{chat}/close` | ✅ |
| `POST /api/v1/chats/{chat}/reopen` | ✅ |
| `GET /api/v1/chats?filter=all\|mine\|favorites\|auto_reply\|closed` | ✅ server-side |
| `POST /api/v1/chats` | ✅ |
| `ChatResource`: `is_lead_closed`, `lead_closed_at` | ✅ list + detail |
| Auto-reopen при inbound + WS `chats.notify` | ✅ `kind: lead_reopened` |

**Для Flutter:** см. раздел «Inbox: закрытие лида» в [`MOBILE_API_NEEDS.md`](./MOBILE_API_NEEDS.md#inbox-закрытие-лида-фильтры-создание-чата--готово).

---

## Приоритет 1 — блокирует полный паритет UI

### Закрытие лида + фильтр «Закрытые» — ✅ backend готов

**Зачем:** пункт меню «Закрыть лид» в inbox и в чате; фильтр «Закрытые»; синхронизация с веб-CRM.

**Flutter (готово):** `ChatService.closeLead` → `POST /api/v1/chats/{id}/close`; поле `is_lead_closed` в `Chat.fromJson`; client-side фильтры inbox.

**Контракт:**

```http
POST /api/v1/chats/{chat}/close
Authorization: Bearer {token}
Response 200: { "data": { ...ChatResource, "is_lead_closed": true, "lead_closed_at": "..." } }
```

Опционально:

```http
POST /api/v1/chats/{chat}/reopen
Response 200: { "data": { ...ChatResource, "is_lead_closed": false, "lead_closed_at": null } }
```

**Поля в `ChatResource`:**

- `is_lead_closed` (bool)
- `lead_closed_at` (datetime, optional)

**Reopen автоматически:** при inbound message от клиента backend сбрасывает `is_lead_closed=false` и шлёт WS на `private-t.{companyId}.chats.list.{userId}`:

```json
{
  "kind": "lead_reopened",
  "chat_id": 123,
  "extra": { "is_lead_closed": false, "lead_closed_at": null }
}
```

**Flutter:** убрать SnackBar «будет доступно после обновления сервера»; при `chats.notify` с `lead_closed` / `lead_reopened` — обновить чат в inbox или сделать pull-to-refresh.

---

### Фильтры inbox (server-side pagination) — ✅ backend готов

**Зачем:** фильтры «Мои», «Избранные», «Автоответ», «Закрытые» с корректной пагинацией.

**Flutter (готово):** `GET /api/v1/chats?filter=mine|favorites|auto_reply|closed`.

**Контракт:**

```http
GET /api/v1/chats?filter=all|mine|favorites|auto_reply|closed&page=1&per_page=50
```

| `filter` | Поведение backend |
|----------|-------------------|
| `all` или omit | Активные чаты, **без** закрытых лидов |
| `mine` | Назначенные текущему пользователю (admin/manager) |
| `favorites` | `is_favorite=true`, не закрытые |
| `auto_reply` | `ai_enabled=true`, `ai_mode=auto`, не группы |
| `closed` | Только `is_lead_closed=true` |

**Flutter:** передавать `filter` в API; client-side фильтрацию можно убрать (оставить только как fallback offline).

---

### Создание чата / «Написать клиенту» — ✅ backend готов

**Зачем:** FAB в списке чатов → выбор контакта → новый или существующий диалог.

**Flutter (готово):** `ChatService.startChatWithContact` → `POST /api/v1/chats`.

**Контракт:**

```http
POST /api/v1/chats
Authorization: Bearer {token}
Body: { "contact_id": 123, "whatsapp_session_id": 1 }
Response 201: { "data": ChatResource }
```

- `whatsapp_session_id` **опционален**: если не передан — берётся сессия из существующего чата контакта или первая доступная пользователю WA-сессия.
- Если чат уже есть — возвращается существующий (200/201 с тем же `id`).
- Закрытый лид при `POST` автоматически reopen'ится.

**Flutter:** убрать fallback SnackBar при 404; `primary_chat_id` можно оставить как оптимизацию до первого POST.

---

### `PATCH /api/v1/chats/{id}/ai` — ✅ backend готов

**Зачем:** переключатель автоответа AI в шапке чата (manual/auto).

**Контракт:**

- Request: `{ "ai_enabled": true|false, "ai_mode": "auto"|"manual" }`
- Response: обновлённый `ChatResource` + warnings при risky enable

**Flutter:** если ещё badge «скоро» — убрать, endpoint работает.

---

### Расширение `ChatResource` / `GET /api/v1/chats/{id}` — ✅ backend готов (detail)

**Зачем:** полоска воронки в чате без дополнительного вызова `GET /funnels/board/card/{chat}`.

**Есть в `GET /api/v1/chats/{id}`:**

- `contact_id`
- `funnel_id`, `funnel_stage_id`
- `funnel`, `funnel_stage`, `funnel_progress`
- `funnel_tracking_enabled`, `funnel_stage_locked`
- `ai_enabled`, `ai_mode`
- `is_lead_closed`, `lead_closed_at`

**В `GET /api/v1/chats` (list):** `contact_id`, `funnel_stage`, `ai_enabled`, `is_lead_closed`, `lead_closed_at` и др.

**Flutter:** полоску воронки брать из `getChatDetail`; fallback на `board/card` не нужен.

---

### `GET /api/v1/broadcasts` — ✅ backend готов

**Зачем:** история кампаний рассылок в мобильном приложении.

**Контракт:** `GET /api/v1/broadcasts?page=1&per_page=20` — paginated list.

**Flutter:** подключить list вместо SharedPreferences workaround, если ещё не сделано.

---

## Приоритет 2 — улучшает UX

### `GET /api/v1/users` (или staff list для admin)

**Зачем:** выбор отправителя рассылки для administrator (сейчас только self).

**Текущий workaround:** manager/admin отправляют от своего user.id.

---

### Organization tasks REST API

**Зачем:** задачи отделов без web-scraping fallback.

**Текущий workaround:** `TaskService` с web-fallback в `OrganizationPage` (banner «beta / web API»).

---

### File upload для CRM contact fields

**Зачем:** загрузка файлов в карточке клиента.

**Текущий workaround:** сообщение «загрузка файлов только в веб-CRM».

---

## Realtime (проверить на demo)

| Канал | События | Использование в Flutter |
|-------|---------|-------------------------|
| `private-t.{companyId}.chats.list.{userId}` | `chats.notify` (`lead_closed`, `lead_reopened`) | Inbox: чат ушёл/вернулся из «Закрытые» |
| `private-t.{companyId}.funnel-board.{funnelId}` | board updates | Tab «Воронки» → доска |
| `private-t.{companyId}.chat.{chatId}` | funnel/AI events | Обновление FunnelStrip в чате |

---

## Верификация

Контракты проверять на demo tenant по ролям: administrator, manager, employee.

Дата: 2026-06-05 (inbox close/filter/create — backend ready)
