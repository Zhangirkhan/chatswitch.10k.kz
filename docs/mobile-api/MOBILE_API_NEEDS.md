# Что нужно от backend для Flutter mobile v1

Документ для backend-kоманды и product: **какие API уже есть**, **чего не хватает**, и **статус интеграции во Flutter**.

**Последнее обновление:** 2026-06-09

---

## Передать mobile-команде сейчас

Краткий handoff (можно копировать в Slack/Telegram):

1. **AI-чипы (критично):** после `POST /api/v1/chats/{id}/ai/chat` в поле ввода и при отправке — **`reply_draft`**, не `reply`.  
   `reply` — полный ответ AI («Лучше так:», кавычки, пояснение); `reply_draft` — только текст для клиента.  
   Fallback: `reply_draft ?? reply` для старых backend.

2. **Inbox (backend готов):** `POST .../close|reopen`, `GET .../chats?filter=mine|favorites|auto_reply|closed`, `POST .../chats` `{contact_id}`, поля `is_lead_closed`, WS `chats.notify` → `lead_reopened`.

3. **Перевод черновика:** `POST .../translate-draft` — уже в mobile API; Flutter probe + chip.

4. **Ещё ждёт backend (не блокирует релиз):** optional `brand_color`, realtime funnel/AI на `chat.{id}`.

**Доки:** этот файл + [`CHAT_TRANSLATION_AND_AI_HINTS.md`](./CHAT_TRANSLATION_AND_AI_HINTS.md) + [`BACKEND_REQUESTS.md`](./BACKEND_REQUESTS.md).  
**OpenAPI:** [`openapi/mobile-v1.yaml`](../../openapi/mobile-v1.yaml) — `AiChatResponse.reply_draft`, `filter` на `GET /chats`.

**Flutter (ожидается на клиенте):**

```dart
// AiService / чипы — только draft
final draft = (res.data['reply_draft'] as String?)?.trim()
    ?? (res.data['reply'] as String?)?.trim()
    ?? '';
textController.text = draft;
```

---

## Accel Mobile (Flutter)

Приложение: **Accel** (`accel_mobile`, bundle `com.accel.mobile`). Все запросы — Sanctum + `/api/v1/*` на `https://{slug}.accel.kz`.

**AI toggle в шапке чата:** ✅ `PATCH /api/v1/chats/{id}/ai` + `Switch` (`ChatService.patchAiSettings`).

---

## Статус реализации Flutter (сводка)

| Область | Flutter | Backend / QA |
|---------|---------|--------------|
| Inbox: фильтры, close lead, FAB «Написать клиенту» | ✅ | ✅ API + WS `lead_reopened` |
| AI toggle в шапке чата | ✅ Switch | ✅ `PATCH .../ai` |
| AI-чипы (Ответить, Улучшить, …) | ✅ `reply_draft` в поле ввода | ✅ `AiChatResponse.reply_draft` |
| AI-панель (bottom sheet) | ✅ показ `reply`, вставка `reply_draft` | ✅ |
| Перевод inbound | ✅ + кеш prefs | ✅ |
| Перевод черновика (чип «Перевести») | ✅ probe + chip | ✅ `POST .../translate-draft` |
| Подпись отправителя в пузырях (outbound) | ✅ `Message.displaySenderLabel` | ✅ `sender_name` + `sender` в API/WS — §2c |
| Полоска воронки в чате | ✅ из `GET /chats/{id}` | ✅ |
| Вкладка «Клиенты» (CRM list + detail с табами) | ✅ | ✅ contacts API |
| Рассылки: wizard + история | ✅ `GET /broadcasts` (+ local fallback) | ✅ |
| `brand_color` tenant | ✅ fallback `AppColors.primary` | ⚠️ optional в `GET /settings` |
| Staff picker для рассылок (admin) | ❌ шлёт от self | ❌ `GET /users` |
| Org tasks | ⚠️ web-fallback | ❌ REST v2 |
| CRM file upload | ❌ сообщение «только веб» | ❌ multipart v2 |

Подробнее по переводу и AI-чипам: [`CHAT_TRANSLATION_AND_AI_HINTS.md`](./CHAT_TRANSLATION_AND_AI_HINTS.md).

---

## Flutter готов — остаётся на backend

**Смысл таблицы:** mobile-код уже сделан и выкатывается; без доработки **сервера** на demo часть UX будет пустой или деградирует до pull-to-refresh. Backend-команда — только пункты в колонке «Что нужно от backend».

| # | Функция | Flutter (готово) | Backend (осталось) | Блокирует релиз? |
|---|---------|------------------|--------------------|------------------|
| 1 | **Подпись отправителя в исходящих пузырях** | `Message.displaySenderLabel`, `_buildSenderLabel` — text/image/file/voice | ✅ `OutboundSenderDisplayName` в API/WS + при создании outbound — §2c | — |
| 2 | **Realtime funnel / AI в открытом чате** | Подписка на `chat.{chatId}`; полоска воронки из `GET /chats/{id}` | Broadcast смены `funnel_stage_id`, `ai_enabled` на канал чата — §7 | Нет — refresh работает |
| 3 | **Realtime доска воронок** | Подписка на `funnel-board.{funnelId}` | Подтвердить события на demo — §7 | Нет |
| 4 | **`brand_color` tenant** | `AppConfigProvider.brandColor` → `ThemeData.primary`; fallback green | Опционально: hex в `GET /settings` — §1b | Нет |
| 5 | **Sample JSON для QA** | Парсинг envelope исправлен на клиенте | Примеры ответов demo tenant: board/data, calendar, ai-chat/query, contacts/profile — §A–D | QA / отладка |
| 6 | **OpenAPI** | Клиент не зависит от спеки | Дописать schemas: funnel fields в Chat, outbound `sender_name` — §OpenAPI | Нет |

**Уже закрыто с обеих сторон (не в таблице выше):** inbox close/filter/create, AI toggle, AI chips + `reply_draft`, translate inbound/draft, broadcasts list, funnel strip, CRM clients, calendar, AI workspace, outbound `sender_name`.

**Не «ждём backend» — v2 / отдельные эпики (Flutter тоже не готов):**

| Функция | Flutter | Backend |
|---------|---------|---------|
| Staff picker рассылок (admin) | ❌ шлёт от `auth.user.id` | ❌ `GET /users` |
| Org tasks | ⚠️ web-scrape fallback | ❌ REST |
| CRM file upload | ❌ «только веб-CRM» | ❌ multipart |

---

## Сводка: готово vs нужно добавить

| Функция | Mobile API сейчас | Статус | Что нужно |
|---------|-------------------|--------|-----------|
| Вход email/password | `POST /api/v1/auth/login` | ✅ OK | — |
| Workspace resolve | `GET /api/v1/workspace` | ✅ OK | Mobile принимает `{ slug, name }` без `id`; желательно добавить `id`, `is_active` |
| Вход PIN | `POST /api/v1/auth/login/pin` | ✅ OK | — |
| Модули tenant | `GET /api/v1/settings` → `modules` | ✅ OK | — |
| **Акцент tenant (brand)** | `GET /api/v1/settings` → `brand_color` | ⚠️ optional | Hex `#RRGGBB`; Flutter fallback `AppColors.primary` |
| Список чатов | `GET /api/v1/chats` | ✅ OK | `filter` query — server-side (§2b) |
| **Закрытие лида** | `POST /api/v1/chats/{id}/close` | ✅ OK | `is_lead_closed`, `lead_closed_at` в ChatResource |
| **Фильтры inbox** | `GET /api/v1/chats?filter=...` | ✅ OK | `mine`, `favorites`, `auto_reply`, `closed` |
| **Создание чата** | `POST /api/v1/chats` | ✅ OK | `{contact_id, whatsapp_session_id?}` |
| Сообщения, read, assign | `chats/*`, `messages/*` | ✅ OK | outbound `sender_name` / `sender` — §2c |
| **Подпись отправителя (UI)** | — | ✅ Flutter | ✅ Backend: `sender_name` / `sender` — §2c |
| AI-панель / чипы в чате | `POST /api/v1/chats/{id}/ai/chat` | ✅ OK | `reply_draft` для composer — § CHAT doc |
| Перевод входящего | `POST /api/v1/messages/{id}/translate` | ✅ OK | Flutter: `MessageService.translate` |
| **Перевод черновика** | `POST /api/v1/chats/{id}/translate-draft` | ✅ OK | Flutter: `DraftTranslationService` + probe |
| **AI toggle auto/manual** | `PATCH /api/v1/chats/{id}/ai` | ✅ OK | Flutter: Switch в AppBar |
| Воронка: доска, карточки, PATCH | `funnels/board/*`, `PATCH chats/{id}/funnel` | ✅ OK | — |
| Полоска воронки в чате | `GET /api/v1/chats/{id}` | ✅ OK | `funnel`, `funnel_stage`, `funnel_progress` |
| CRM профиль клиента | `GET /contacts/{id}/profile`, `summary` | ✅ OK | Flutter: табы в `ClientDetailPage` |
| Рассылки | preview, store, list, status | ✅ OK | Flutter: `BroadcastService.listCampaigns` |
| AI workspace tab | `POST /api/v1/ai-chat/query` | ✅ OK | — |
| Календарь CRUD | `GET/POST/PUT/DELETE /calendar/events` | ✅ OK | — |
| Задачи организации | только веб | ⚠️ web-fallback | REST API для tasks (v2) |
| Загрузка файлов в CRM fields | только веб | ⚠️ | multipart в mobile API (v2) |
| Список staff для рассылок (admin) | ❌ нет | ⚠️ | `GET /api/v1/users` или аналог |
| **Push-уведомления (Android FCM)** | ✅ клиент готов | ⚠️ API готов, нужен Firebase | `POST/DELETE /api/v1/devices` + FCM sender — см. [`PUSH_NOTIFICATIONS_BACKEND.md`](./PUSH_NOTIFICATIONS_BACKEND.md) |

---

## Push-уведомления (Android FCM) — ⚠️ API готов, нужен Firebase

**Зачем:** Reverb работает только при живом приложении; push нужен в background / killed.

**Mobile (готово):** `PushNotificationService`, `DeviceService`, регистрация после login, deep link в чат / team chat, учёт `NotificationPrefs`.

**Backend (2026-06-09):** см. [`PUSH_NOTIFICATIONS_BACKEND.md`](./PUSH_NOTIFICATIONS_BACKEND.md):

| # | Что | Статус |
|---|-----|--------|
| 1 | `POST /api/v1/devices` — upsert FCM token | ✅ |
| 2 | `DELETE /api/v1/devices/{id}` / `POST .../unregister` | ✅ |
| 3 | FCM data push: `client_message`, `team_message`, `chat_assigned`, `lead_closed`, `lead_reopened` | ✅ (при `FIREBASE_FCM_ENABLED=true`) |
| 4 | Firebase project + `google-services.json` для `com.accel.mobile` | ❌ ops |

**Включение на сервере:** `.env` → `FIREBASE_FCM_ENABLED=true`, `FIREBASE_CREDENTIALS=/secure/path/firebase-sa.json`.

**Scope v1:** клиентские сообщения, team chat, назначения/lead. Звонки (`call_incoming`) — не в v1.

**Блокер E2E:** без реального Firebase project + `google-services.json` уведомления не дойдут на устройство.

---

## Приоритет 1 — backend follow-up

### 1. `POST /api/v1/chats/{chat}/translate-draft` — ✅ Mobile API + Flutter

**Зачем:** чип «Перевести» над полем ввода (исходящий черновик на язык клиента).

**Backend:** ✅ `routes/api-tenant.php` → `ChatDraftTranslationController::translate`, `throttle:30,1`.

**Flutter (готово):** `DraftTranslationService` + probe при открытии чата (`ChatTranslationPrefs`); при 404 чип скрыт; чип в `ChatComposerChips`.

**Body:** `{ "text": "...", "lang": "kk" }` (lang optional). **Response 200:** `{ "translation", "target_lang", "unchanged" }`.

---

### 1b. `GET /api/v1/settings` → `brand_color` (tenant accent)

**Зачем:** акцент приложения (кнопки, чипы, NavigationBar) под бренд tenant вместо дефолтного Accel green.

**Flutter (готово):** `AppConfigProvider.brandColor` → `ThemeData.colorScheme.primary` через `AppTheme.light/dark(brandPrimary:)`. Fallback — `AppColors.primary`, если поле отсутствует.

```json
{
  "modules": { "...": true },
  "brand_color": "#2563EB"
}
```

Hex: `#RGB`, `#RRGGBB`, `#AARRGGBB`.

---

### 2. Расширить `ChatResource` / `GET /api/v1/chats/{id}`

**Зачем:** полоска воронки и AI badge в чате **без лишних запросов** к `GET /funnels/board/card/{chat}`.

**Сейчас в mobile:** Flutter загружает полоску воронки одним `GET /api/v1/chats/{id}` (`ChatService.getChatDetail`). Если в ответе нет `funnel` / `funnel_stage` — полоска пустая; `GET /funnels/board/card/{chat}` не используется в экране чата.

**Добавить в JSON чата** (как в Inertia `ChatFunnelStateService::inertiaExtras`):

```json
{
  "data": {
    "id": 123,
    "contact_id": 45,
    "ai_enabled": false,
    "ai_mode": "draft",
    "funnel_id": 2,
    "funnel_stage_id": 7,
    "funnel_tracking_enabled": true,
    "funnel_stage_locked": false,
    "funnel": {
      "id": 2,
      "name": "Продажи",
      "color": "#3B82F6",
      "stages": [
        { "id": 5, "name": "Новый", "color": "#94A3B8" },
        { "id": 7, "name": "КП", "color": "#3B82F6" }
      ]
    },
    "funnel_stage": { "id": 7, "name": "КП", "color": "#3B82F6" },
    "funnel_progress": {
      "stage_index": 1,
      "stages_count": 5,
      "percent": 40
    }
  }
}
```

**Также желательно** в `GET /api/v1/chats` (список inbox) для **богатых карточек**:

| Поле | UI |
|------|-----|
| `funnel_stage` / `funnel_stage_id` + `name`, `color` | Badge стадии воронки |
| `assignments[]` или `assignees[]` (`id`, `name`, `avatar_url`) | Строка ответственного |
| `whatsapp_session_id` + `whatsapp_session` / `session_label` | Иконка и подпись WA |
| `ai_enabled` | Индикатор AI в списке |
| `contact_id` | Навигация в CRM |
| `is_lead_closed` | Фильтр «Закрытые», меню «Закрыть лид» |
| `lead_closed_at` | optional, сортировка закрытых |

Минимум v1: `contact_id`, `funnel_stage_id` для badge (можно v1.1).

---

### 2b. Inbox: закрытие лида, фильтры, создание чата — ✅ backend готов

**Backend (2026-06-05):**

```http
POST /api/v1/chats/{chat}/close
Response 200: { "data": ChatResource с is_lead_closed: true, lead_closed_at: "..." }

POST /api/v1/chats/{chat}/reopen
Response 200: { "data": ChatResource с is_lead_closed: false }

GET /api/v1/chats?filter=all|mine|favorites|auto_reply|closed&page=1&per_page=50

POST /api/v1/chats
Body: { "contact_id": 123, "whatsapp_session_id": 1? }
Response 201: { "data": ChatResource }
```

**Reopen автоматически:** inbound от клиента → `is_lead_closed=false` + WS `chats.notify`:

- `kind`: `lead_reopened` (или `lead_closed` при ручном close)
- `extra.is_lead_closed`, `extra.lead_closed_at`

**Flutter — ✅ выполнено (2026-06-02):**

1. ~~Убрать заглушки~~ SnackBar-workaround для close / create chat убран.
2. Inbox: server-side `filter` (`ChatService.getChatsPage`); client-side только offline-fallback.
3. Close lead: локальный `Chat` обновляется из ответа `POST .../close`.
4. WS inbox: `chats.notify` → `lead_closed` / `lead_reopened` в `ChatsProvider`.
5. FAB «Написать клиенту»: `POST /chats` через `startChatWithContact`.
6. Long-press меню на строке чата; фильтры **Все / Мои / Избранные / Автоответ / Закрытые**; иконка «Автоответ».

**Код:** `ChatService.closeLead` / `reopenLead`, `getChatsPage(filter:)`, `chat_inbox_filter.dart`, `chat_actions_sheet.dart`, `new_client_chat_page.dart`.

---

### 2d. AI-чипы: `reply_draft` — ✅ backend + Flutter

**Проблема:** AI возвращает структурированный ответ оператору («Лучше так:», текст в « », пояснение). Если подставлять `reply` в composer — клиенту уходит весь промпт.

**Backend (2026-06-05):** `POST /api/v1/chats/{id}/ai/chat` → [`ChatAiAssistantController`](../../app/Http/Controllers/ChatAiAssistantController.php) + [`AssistantClientDraftExtractor`](../../app/Support/AssistantClientDraftExtractor.php).

**Response 200:**

```json
{
  "reply": "Лучше так:\n«Здравствуйте! ...»\nТак звучит спокойнее...",
  "reply_draft": "Здравствуйте! ...",
  "reply_intro": null,
  "reply_variants": null,
  "product": null
}
```

| Поле | Куда во Flutter |
|------|-----------------|
| `reply_draft` | Поле ввода, отправка сообщения, «Вставить в ответ» |
| `reply` | AI bottom sheet — полный текст для оператора |
| `reply_variants[]` | Кнопки выбора варианта (если AI вернул «Вариант 1:») |
| `product` | Опционально `product_id` при `POST .../messages` |

**Flutter (готово):** `AiService.draftForComposer` → `reply_draft`; панель — `replyForPanel` → `reply`.

Подробнее: [`CHAT_TRANSLATION_AND_AI_HINTS.md`](./CHAT_TRANSLATION_AND_AI_HINTS.md) §3.

---

### 2c. `MessageResource.sender_name` на исходящих (outbound) — ✅ backend

**Зачем:** в WhatsApp и mobile клиент видит подпись внутри исходящего пузыря: **«Администратор ESL (Администратор)»**, **«Сани (AI)»**.

**Backend (2026-06-05):** [`OutboundSenderDisplayName`](../../app/Support/OutboundSenderDisplayName.php) — при создании outbound, в `MessageResource`, WS `message.received`.

**Контракт для `GET /api/v1/chats/{id}/messages` и WS `messages.*`:**

| Поле | Направление | Значение |
|------|-------------|----------|
| `sender_name` | **все outbound** | `"Имя (Роль)"` для оператора/админа или `"Имя (AI)"` для автоответа — **паритет с WhatsApp push / веб-CRM** |
| `sent_by_user_id` | outbound от пользователя | ID staff; `null` для AI |
| `sender` | optional | `{ "id": 12, "name": "Сани", "role": "AI" }` — mobile соберёт `"Сани (AI)"` если `sender_name` пуст |
| `metadata.sender_display_name` / `metadata.sender_label` | optional fallback | строка, если основное поле не заполнено |

**Пример outbound в ленте:**

```json
{
  "id": 9001,
  "direction": "outbound",
  "body": "Здравствуйте!",
  "sender_name": "Администратор ESL (Администратор)",
  "sent_by_user_id": 3
}
```

```json
{
  "id": 9002,
  "direction": "outbound",
  "body": "Могу помочь с заказом.",
  "sender_name": "Сани (AI)",
  "sent_by_user_id": null
}
```

**Inbound:** для 1:1 `sender_name` клиента не обязателен (mobile не показывает подпись); для **групповых** чатов — желательно для входящих от участников.

**Flutter (готово):** `Message.displaySenderLabel` + `_buildSenderLabel` в `chat_screen.dart`.

---

### 3. `GET /api/v1/broadcasts` — ✅ Mobile API + Flutter

**Зачем:** экран «Последние рассылки» вместо локального списка ID в `SharedPreferences`.

**Backend:** ✅ `BroadcastController::apiIndex` в `routes/api-tenant.php`.

```http
GET /api/v1/broadcasts?page=1&per_page=20
```

**Response (предложение):**

```json
{
  "data": [
    {
      "id": 42,
      "status": "running",
      "source": "excel",
      "ready_count": 120,
      "sent_count": 45,
      "skipped_count": 3,
      "created_at": "2026-06-02T10:00:00Z",
      "finished_at": null
    }
  ],
  "meta": { "current_page": 1, "last_page": 3 }
}
```

**Права:** administrator, manager (как store/preview).

**После добавления на Flutter:** ✅ `BroadcastService.listCampaigns` → `GET /api/v1/broadcasts`; local SharedPreferences — fallback при 404/403.

---

## Приоритет 2 — улучшает UX, не блокирует v1

### 4. `GET /api/v1/users` (или `/staff`)

**Зачем:** admin при рассылке выбирает отправителя (сейчас mobile всегда шлёт от `auth.user.id`).

**Минимум:** `{ id, name, email }[]` для active staff tenant.

---

### 5. Organization tasks REST API

**Зачем:** убрать web-scraping в `OrganizationPage` (задачи отделов).

**Сейчас:** Flutter показывает задачи через web-fallback + banner «beta».

**Нужно:** CRUD или read-only API для department posts/tasks (отдельный эпик).

---

### 6. File upload для CRM contact fields

**Зачем:** загрузка файлов в карточке клиента с телефона.

**Сейчас:** сообщение «загрузка файлов только в веб-CRM».

**Нужно:** `multipart` на `PATCH /api/v1/contacts/{id}/fields` или отдельный upload endpoint.

---

### 7. Realtime — проверить события на demo

Flutter уже подписан на каналы. Нужно подтвердить, что backend **шлёт события**:

| Канал | Когда обновлять UI |
|-------|-------------------|
| `private-t.{companyId}.chat.{chatId}` | новое сообщение, **смена funnel stage**, **смена ai_enabled** |
| `private-t.{companyId}.funnel-board.{funnelId}` | перемещение карточки на доске |
| `private-t.{companyId}.chats.list.{userId}` | уже работает (inbox) |

Если funnel/AI events не broadcastятся — полоска в чате и доска обновятся только после pull-to-refresh.

---

## Что **не** нужно делать (уже решено в mobile)

| Не нужно | Почему |
|----------|--------|
| Новый API для in-chat AI panel | Уже есть `POST /api/v1/chats/{id}/ai/chat` |
| VoIP / звонки API | UI звонков в mobile v1 **намеренно не делаем** |
| Team chat tab API (v1) | Отложено; tile в профиле — v1.1 |
| Дублировать веб-layout 1:1 | Mobile UX свой (PageView kanban, full-screen CRM) |

---

## OpenAPI

После добавления endpoints — обновить [`openapi/mobile-v1.yaml`](../../openapi/mobile-v1.yaml):

- [x] `PATCH /chats/{chat}/ai`
- [x] `GET /broadcasts`
- [x] `POST /chats/{chat}/translate-draft`
- [x] `AiChatResponse.reply_draft`
- [ ] поля funnel/ai в `Chat` schema (частично)
- [ ] (опционально) `GET /users`
- [x] `MessageResource.sender_name` + `sender` — outbound (§2c)

---

## Баги интеграции v1 — что проверить на demo (2026-06-02)

После QA на устройстве выявлено: **API endpoints существуют**, но mobile ломается из‑за **JSON envelope**, **scope по роли** и **отсутствия sample responses**. Flutter-side fixes уже внесены (unwrap `data`, SafeArea, error states); ниже — что нужно **подтвердить/добавить на backend**.

### A. Доска воронок (`GET /funnels/board/data`)

| Проблема на mobile | Причина | Нужно от backend |
|--------------------|---------|------------------|
| Вечный skeleton / пустая доска | Parse падал на `{ data: { funnel, stages } }`; default scope был `mine` вместо `all`/`department` | **Sample JSON** ответа `board/data` с demo tenant |
| Нет picker воронки | ✅ `GET /api/v1/funnels/active` | OK | `[{ id, name, color }]` для всех ролей с module_funnels |
| Карточки есть на вебе, нет в app | Admin на вебе смотрит `scope=all`, mobile слал `mine` | Документировать default scope: admin=`all`, manager/employee=`department` |
| `funnel_id` unknown on first launch | Нет list API | Либо `funnels/active`, либо onboarding setting `default_funnel_id` |

**Ожидаемый envelope (один из двух, зафиксировать):**

```json
{
  "funnel": { "id": 1, "name": "Универсальная продажа", "color": "#01b964" },
  "stages": [{ "id": 10, "name": "Новый", "cards": [], "cards_total": 0, "has_more": false }]
}
```

или

```json
{
  "data": {
    "funnel": { "id": 1, "name": "..." },
    "stages": [ "..."]
  }
}
```

---

### B. Календарь (`GET /calendar/events`)

| Проблема на mobile | Причина | Нужно от backend |
|--------------------|---------|------------------|
| Spinner / пустой список при событиях на вебе | Parse падал на timezone в `starts_at`; диапазон был только 1 месяц | **Sample response** с реальными событиями demo tenant |
| Filter mismatch | Flutter шлёт `filter=all\|mine\|assigned_to_me` | Подтвердить exact values (не `assigned`?) |
| Query param names | Flutter шлёт `start`, `end` | Подтвердить: `start`/`end` vs `start_date`/`end_date` |

**Sample event:**

```json
{
  "data": [
    {
      "id": 1,
      "title": "Встреча",
      "starts_at": "2026-06-02T10:00:00+05:00",
      "ends_at": "2026-06-02T11:00:00+05:00",
      "color": "#25d366",
      "all_day": false
    }
  ]
}
```

---

### C. AI workspace (`POST /ai-chat/query`)

| Проблема на mobile | Причина | Нужно от backend |
|--------------------|---------|------------------|
| «Пустой ответ» при работающем вебе | Reply лежит в `data.reply`, не на верхнем уровне | **Sample 200** с точным path к тексту ответа |
| Нет contact cards | Optional | Формат `contacts: [{ id, name, phone }]` в ответе |

**Sample:**

```json
{
  "data": {
    "reply": "На этапе КП сейчас 12 сделок.",
    "contacts": [{ "id": 45, "name": "Иван", "phone": "+7700..." }]
  }
}
```

**Errors:** тело для 403 (`module off`), 429 (throttle), 422 — поле `message`.

---

### D. Карточка клиента (`GET /contacts/{id}/profile`)

| Проблема на mobile | Причина | Нужно от backend |
|--------------------|---------|------------------|
| Пустой экран «Клиент ?» при 200 | Schema mismatch: `sections[]` vs nested `who/context/agreements` | **Полный sample JSON** profile endpoint |
| Не открывается из чата | `GET /chats` list без `contact_id` | Добавить `contact_id` в **ChatResource** (list + detail) |
| Fallback | — | Sample для `GET /contacts/{id}/card?chat_id=` |

**Sample profile:**

```json
{
  "data": {
    "id": 45,
    "name": "Иван Петров",
    "phone": "+77001234567",
    "ai_summary": "Интересуется КП...",
    "sections": [
      {
        "key": "who",
        "title": "Кто",
        "fields": [{ "label": "Город", "value": "Алматы", "editable": true, "type": "text" }]
      }
    ]
  }
}
```

---

### E. OpenAPI gaps (mobile парсит «вслепую»)

Добавить schemas + examples в [`openapi/mobile-v1.yaml`](../../openapi/mobile-v1.yaml):

- [ ] `GET /funnels/board/data` + `GET /funnels/board/stage-cards`
- [ ] `GET /funnels/active` (**новый**)
- [ ] `GET /calendar/events`
- [ ] `POST /ai-chat/query`
- [ ] `GET /contacts/{id}/profile` + `GET /contacts/{id}/card`
- [ ] `ChatResource` — `contact_id`, funnel fields

---

## Чеклист для backend (минимум для снятия заглушек)

- [x] **`PATCH /api/v1/chats/{id}/ai`** — `ChatAiSettingsController::updateForApi`, Sanctum auth
- [x] **`ChatResource`** — funnel + ai поля в `GET /api/v1/chats/{id}`; в list — `funnel_stage` для badge
- [x] **`AiChatResponse.reply_draft`** — `AssistantClientDraftExtractor` в `POST .../ai/chat`
- [x] **`GET /api/v1/broadcasts`** — `BroadcastController::apiIndex`
- [x] **`POST /api/v1/chats/{id}/close|reopen`** — `ChatLeadClosureService`, `is_lead_closed` / `lead_closed_at`
- [x] **`GET /api/v1/chats?filter=...`** — server-side: `mine`, `favorites`, `auto_reply`, `closed`
- [x] **`POST /api/v1/chats`** — создание/поиск диалога по `contact_id`
- [x] **`contact_id` в ChatResource** — list + detail
- [x] **WS lead reopen** — `ChatsListNotify` `kind: lead_reopened` на inbound
- [ ] **Realtime** — funnel/AI events на `chat.{chatId}` и board events на `funnel-board.{funnelId}` (желательно payload: `chat_id`, `funnel_stage_id` для incremental merge без полного reload)
- [x] **`GET /api/v1/funnels/active`** — `FunnelBoardController::active` (picker + first launch)
- [ ] **Sample JSON** — board/data, calendar/events, ai-chat/query, contacts/profile (demo tenant)

## Чеклист для Flutter

### Inbox / CRM (2026-06-05)

- [x] Убрать SnackBar-workaround для `closeLead` / `startChatWithContact`
- [x] Inbox: server-side `filter` вместо client-side (кроме offline fallback)
- [x] Обработать WS `chats.notify` → `lead_closed` / `lead_reopened`
- [x] Парсить `is_lead_closed`, `lead_closed_at` в `Chat.fromJson`
- [x] Long-press меню на строке чата; FAB «Написать клиенту»
- [x] Вкладка shell «Клиенты» (CRM list); `ClientDetailPage` — секции как TabBar

### Чат: перевод, AI, подписи (2026-06-02)

- [x] AI toggle — `Switch` + `PATCH .../ai` (не заглушка «скоро»)
- [x] AI-чипы: `ChatComposerChips` + `AiService.ask` → **`reply_draft`** в поле ввода (`draftForComposer`)
- [x] AI-панель: показ полного `reply`; «Вставить в ответ» → `reply_draft`
- [x] Inbound перевод: `MessageService.translate` + кеш `ChatTranslationPrefs`
- [x] Чип «Перевести»: `DraftTranslationService` + probe endpoint
- [x] `translate_enabled`, `clientLanguage` (`message_language.dart`)
- [x] Подпись отправителя в пузырях: `Message.displaySenderLabel`, `_buildSenderLabel` (text/image/file/voice)
- [x] Backend: `sender_name` / `sender` на outbound в API и WS

### Push (Android FCM) — 2026-06-09

- [x] `DeviceService` + `PushNotificationService` (FCM token → `POST /api/v1/devices`)
- [x] Register после login / cold start; unregister при logout / 401
- [x] Foreground + background handler; deep link в `ChatScreen` / `TeamChatScreen`
- [x] Учёт `NotificationPrefs`; Android notification channels
- [x] Экран настроек: статус push вместо баннера «в разработке»
- [ ] E2E на устройстве после backend API + реальный `google-services.json`

---

## Безопасность клиента

План и статус hardening Flutter: [`MOBILE_CLIENT_SECURITY.md`](./MOBILE_CLIENT_SECURITY.md).

Кратко (клиент v1):
- Sanctum token → `flutter_secure_storage` (Keychain / EncryptedSharedPreferences)
- Глобальный 401/403 → logout + экран входа
- Upload validation (размер, расширения)
- Очистка локальных кэшей при logout / session expired
- **Backend:** IDOR, policies, rate limits, `/broadcasting/auth` — см. §4 в security doc

---

## Связанные файлы

| Файл | Описание |
|------|----------|
| [`CHAT_TRANSLATION_AND_AI_HINTS.md`](./CHAT_TRANSLATION_AND_AI_HINTS.md) | Перевод, AI-чипы, `reply_draft` |
| [`MOBILE_IMPLEMENTATION_GUIDE.md`](./MOBILE_IMPLEMENTATION_GUIDE.md) | Пошаговое внедрение в backend + Flutter (P0/P1/P2, контракты, QA) |
| [`FLUTTER_MOBILE_UI.md`](./FLUTTER_MOBILE_UI.md) §3.3 | Детальный UX-паритет с вебом |
| [`FEATURES_BY_ROLE.md`](./FEATURES_BY_ROLE.md) | Матрица ролей и routes |
| `lib/features/chat/chat_screen.dart` | Чат: chips, перевод, sender labels, AI panel |
| `lib/services/ai_service.dart` | `draftForComposer` / `replyForPanel` |

---

## Mobile funnel drag-and-drop (UX 2026)

Перемещение карточки между этапами на телефоне:

```http
PATCH /api/v1/chats/{chat}/funnel
{"funnel_id": 1, "funnel_stage_id": 12}
```

Отдельного «DnD» endpoint нет. При `funnel_stage_locked` — HTTP 4xx + понятное сообщение; Flutter откатывает optimistic state.

---

*Обновлено: 2026-06-05 — handoff mobile, §2d `reply_draft`; inbox close/filter/create — backend + Flutter*
