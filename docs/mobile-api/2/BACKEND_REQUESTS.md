# Backend API requests (Flutter mobile v1)

> **Подробное описание для backend/product:** см. [`MOBILE_API_NEEDS.md`](./MOBILE_API_NEEDS.md) — что значит «после обновления API», полные контракты, чеклист.

Запросы к backend-команде для закрытия пробелов, выявленных при порте веб-функций Accel CRM в Flutter.

## Приоритет 1 — блокирует полный паритет UI

### Закрытие лида + фильтр «Закрытые»

**Зачем:** пункт меню «Закрыть лид» в inbox и в чате; фильтр «Закрытые»; синхронизация с веб-CRM.

**Flutter (готово):** `ChatService.closeLead` → `POST /api/v1/chats/{id}/close`; поле `is_lead_closed` в `Chat.fromJson`; client-side фильтры inbox.

**Ожидаемый контракт:**

```http
POST /api/v1/chats/{chat}/close
Response 200: { "data": { ...ChatResource, "is_lead_closed": true } }
```

Опционально:

```http
POST /api/v1/chats/{chat}/reopen
Response 200: { "data": { ...ChatResource, "is_lead_closed": false } }
```

**Поля в `ChatResource`:**

- `is_lead_closed` (bool)
- `lead_closed_at` (datetime, optional)

**Reopen автоматически:** при inbound message от клиента backend сбрасывает `is_lead_closed=false` и шлёт WS на `private-t.{companyId}.chats.list.{userId}`.

**Текущий workaround:** при 404 SnackBar «будет доступно после обновления сервера»; без локального fake-state.

---

### Фильтры inbox (server-side pagination)

**Зачем:** фильтры «Мои», «Избранные», «Автоответ», «Закрытые» с корректной пагинацией.

**Flutter (готово):** `GET /api/v1/chats?filter=mine|favorites|auto_reply|closed` (если param игнорируется — client-side фильтрация).

**Ожидаемый контракт:**

```http
GET /api/v1/chats?filter=all|mine|favorites|auto_reply|closed
```

Паритет с веб-inbox.

---

### Создание чата / «Написать клиенту»

**Зачем:** FAB в списке чатов → выбор контакта → новый или существующий диалог.

**Flutter (готово):** `ChatService.startChatWithContact` → `POST /api/v1/chats`; fallback на `contact.primary_chat_id`.

**Ожидаемый контракт:**

```http
POST /api/v1/chats
Body: { "contact_id": 123, "whatsapp_session_id": 1? }
Response 201: { "data": ChatResource }
```

**Текущий workaround:** если есть `primary_chat_id` — открывается существующий чат; иначе SnackBar при 404.

---

### `PATCH /api/v1/chats/{id}/ai`

**Зачем:** переключатель автоответа AI в шапке чата (manual/auto).

**Текущий workaround:** кнопка с badge «скоро» и tooltip «Управление автоответом — в веб-CRM».

**Ожидаемый контракт:**

- Request: `{ "ai_enabled": true|false, "ai_mode": "auto"|"manual" }` (уточнить поля)
- Response: обновлённый `ChatResource` + warnings при risky enable

---

### Расширение `ChatResource` / `GET /api/v1/chats/{id}`

**Зачем:** полоска воронки в чате без дополнительного вызова `GET /funnels/board/card/{chat}`.

**Нужные поля в ответе чата:**

- `contact_id`
- `funnel_id`, `funnel_stage_id`
- `funnel` (object: id, name, color, stages[])
- `funnel_stage` (object: id, name, color)
- `funnel_progress` (stage_index, stages_count, percent)
- `funnel_tracking_enabled`, `funnel_stage_locked`
- `ai_enabled`, `ai_mode` (read-only до PATCH)

**Текущий workaround:** `GET /api/v1/funnels/board/card/{chat}?funnel_id=X` + `GET /api/v1/chats/{id}`.

---

### `GET /api/v1/broadcasts`

**Зачем:** история кампаний рассылок в мобильном приложении.

**Текущий workaround:** локальный список `campaignId` в `SharedPreferences` + polling активной кампании.

**Ожидаемый контракт:** paginated list с id, status, counts (ready/sent/skipped), created_at.

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
| `private-t.{companyId}.funnel-board.{funnelId}` | board updates | Tab «Воронки» → доска |
| `private-t.{companyId}.chat.{chatId}` | funnel/AI events | Обновление FunnelStrip в чате |

---

## Верификация

Контракты проверять на demo tenant по ролям: administrator, manager, employee.

Дата: 2026-06-02
