# Что нужно от backend для Flutter mobile v1

Документ для backend-команды и product: **какие API уже есть**, **чего не хватает**, и **что значит «после обновления API»** в мобильном приложении.

---

## Accel Mobile (Flutter)

Приложение: **Accel** (`accel_mobile`, bundle `com.accel.mobile`). Все запросы — Sanctum + `/api/v1/*` на `https://{slug}.accel.kz`.

**AI toggle в шапке чата:** реализован в Flutter через `PATCH /api/v1/chats/{id}/ai` + `Switch` (см. `ChatService.patchAiSettings`). Если toggle не работает — проверьте, что маршрут есть в `routes/api-tenant.php` на тенанте.

---

## Сводка: готово vs нужно добавить

| Функция | Mobile API сейчас | Статус | Что нужно |
|---------|-------------------|--------|-----------|
| Вход email/password | `POST /api/v1/auth/login` | ✅ OK | — |
| Workspace resolve | `GET /api/v1/workspace` | ✅ OK | Mobile принимает `{ slug, name }` без `id`; желательно добавить `id`, `is_active` |
| Вход PIN | `POST /api/v1/auth/login/pin` | ✅ OK | — |
| Модули tenant | `GET /api/v1/settings` → `modules` | ✅ OK | — |
| **Акцент tenant (brand)** | `GET /api/v1/settings` → `brand_color` | ⚠️ optional | Hex `#RRGGBB` или `#AARRGGBB`; Flutter fallback `AppColors.primary` |
| Список чатов | `GET /api/v1/chats` | ✅ OK | Расширить `ChatResource` + `filter` query (см. ниже) |
| **Закрытие лида** | ❌ нет | ⚠️ stub | `POST /chats/{id}/close` + `is_lead_closed` в ChatResource |
| **Фильтры inbox** | ❌ нет | ⚠️ client-side | `GET /chats?filter=mine\|favorites\|auto_reply\|closed` |
| **Создание чата** | ❌ нет | ⚠️ stub | `POST /chats` body `{contact_id}` |
| Сообщения, read, assign | `chats/*`, `messages/*` | ✅ OK | — |
| AI-панель в чате (подсказки оператору) | `POST /api/v1/chats/{id}/ai/chat` | ✅ OK | Не путать с toggle |
| Перевод входящего сообщения | `POST /api/v1/messages/{id}/translate` body `{ "lang": "ru" }` | ✅ OK | Ответ: `translation` |
| **Перевод черновика (чип «Перевести»)** | `POST /api/v1/chats/{id}/translate-draft` | ❌ нет в Mobile API | Проброс из `routes/tenant.php`; Flutter: probe при входе в чат + чип (`DraftTranslationService`) |
| **AI toggle auto/manual** | ✅ `PATCH /api/v1/chats/{id}/ai` | OK | — |
| Воронка: доска, карточки, PATCH | `funnels/board/*`, `PATCH chats/{id}/funnel` | ✅ OK | Mobile DnD = тот же PATCH (не отдельный endpoint) |
| Полоска воронки в чате | ✅ `GET /api/v1/chats/{id}` | OK | `funnel`, `funnel_stage`, `funnel_progress` в ChatResource |
| CRM профиль клиента | `GET /contacts/{id}/profile`, `summary` | ✅ OK | — |
| Рассылки: preview + start + status | `POST preview`, `POST`, `GET {id}` | ✅ OK | — |
| **История рассылок** | ✅ `GET /api/v1/broadcasts` | OK | пагинация `page` / `per_page` |
| AI workspace tab | `POST /api/v1/ai-chat/query` | ✅ OK | опционально `contacts[]` в ответе |
| Календарь CRUD | `GET/POST/PUT/DELETE /calendar/events` | ✅ OK | — |
| Задачи организации | только веб | ⚠️ web-fallback | REST API для tasks (v2) |
| Загрузка файлов в CRM fields | только веб | ⚠️ | multipart в mobile API (v2) |
| Список staff для рассылок (admin) | ❌ нет | ⚠️ | `GET /api/v1/users` или аналог |

---

## Приоритет 1 — backend follow-up

### 1. `POST /api/v1/chats/{chat}/translate-draft` — **проброс в Mobile API**

**Зачем:** чип «Перевести» над полем ввода (исходящий черновик на язык клиента).

**Откуда:** веб `POST /chats/{chat}/translate-draft` → [`ChatDraftTranslationController`](../../app/Http/Controllers/ChatDraftTranslationController.php).

**Flutter (готово):** `DraftTranslationService` + probe при открытии чата; при 404 чип скрыт.

```php
// routes/api-tenant.php
Route::post('chats/{chat}/translate-draft', [ChatDraftTranslationController::class, 'translate'])
    ->middleware('throttle:30,1');
```

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

### 2b. Закрытие лида + фильтры inbox + создание чата

**Flutter (готово):**

- `ChatService.closeLead` / `reopenLead` — `POST /api/v1/chats/{id}/close|reopen`
- `ChatService.getChatsPage(filter: ...)` — query `filter=mine|favorites|auto_reply|closed`
- `ChatService.startChatWithContact` — `POST /api/v1/chats` body `{contact_id}`
- Inbox UI: фильтры **Все / Мои / Избранные / Автоответ / Закрытые**; long-press меню; FAB «Написать клиенту»

**Backend:**

```http
POST /api/v1/chats/{chat}/close
Response 200: { "data": ChatResource с is_lead_closed: true }

GET /api/v1/chats?filter=all|mine|favorites|auto_reply|closed

POST /api/v1/chats
Body: { "contact_id": 123, "whatsapp_session_id": 1? }
Response 201: { "data": ChatResource }
```

**Reopen:** при inbound message от клиента — `is_lead_closed=false` + WS `chats.list`.

При 404 mobile показывает SnackBar; client-side фильтрация работает, если `is_lead_closed` уже есть в `GET /chats`.

---

**После добавления на Flutter:** уберём fallback на `board/card`, полоска будет из одного запроса.

---

### 3. `GET /api/v1/broadcasts` — список кампаний

**Зачем:** экран «Последние рассылки» вместо локального списка ID в `SharedPreferences`.

**Сейчас есть только:**

- `POST /api/v1/broadcasts/preview`
- `POST /api/v1/broadcasts`
- `GET /api/v1/broadcasts/{campaign}`

**Нужно:**

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

**После добавления на Flutter:** подключим list вместо SharedPreferences workaround.

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

После добавления endpoints — обновить [`accel/openapi/mobile-v1.yaml`](./openapi/mobile-v1.yaml):

- [ ] `PATCH /chats/{chat}/ai`
- [ ] `GET /broadcasts`
- [ ] поля funnel/ai в `Chat` schema
- [ ] (опционально) `GET /users`

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

Добавить schemas + examples в [`openapi/mobile-v1.yaml`](./openapi/mobile-v1.yaml):

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
- [x] **`GET /api/v1/broadcasts`** — `BroadcastController::apiIndex`
- [ ] **Realtime** — funnel/AI events на `chat.{chatId}` и board events на `funnel-board.{funnelId}` (желательно payload: `chat_id`, `funnel_stage_id` для incremental merge без полного reload)
- [x] **`GET /api/v1/funnels/active`** — `FunnelBoardController::active` (picker + first launch)
- [ ] **Sample JSON** — board/data, calendar/events, ai-chat/query, contacts/profile (demo tenant)
- [ ] **`contact_id` в ChatResource** — list + detail

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
| [`MOBILE_IMPLEMENTATION_GUIDE.md`](./MOBILE_IMPLEMENTATION_GUIDE.md) | Пошаговое внедрение в backend + Flutter (P0/P1/P2, контракты, QA) |
| [`FLUTTER_MOBILE_UI.md`](./FLUTTER_MOBILE_UI.md) §3.3 | Детальный UX-паритет с вебом |
| [`FEATURES_BY_ROLE.md`](./FEATURES_BY_ROLE.md) | Матрица ролей и routes |
| `lib/features/chat/chat_screen.dart` | Заглушка AI toggle (строка ~1687) |

---

## Mobile funnel drag-and-drop (UX 2026)

Перемещение карточки между этапами на телефоне:

```http
PATCH /api/v1/chats/{chat}/funnel
{"funnel_id": 1, "funnel_stage_id": 12}
```

Отдельного «DnD» endpoint нет. При `funnel_stage_locked` — HTTP 4xx + понятное сообщение; Flutter откатывает optimistic state.

---

*Обновлено: 2026-06-02 — Mobile UX 2026 (stage rail DnD, touch helpers, action sheets)*
