# Безопасность и оптимизации (Chatswitch)

Документ фиксирует **уже внесённые изменения**, **рекомендуемые доработки** по безопасности и **направления оптимизации** кода и инфраструктуры. Его стоит пересматривать после крупных релизов и пентестов.

---

## 1. Уже реализовано в коде

### 1.1. Авторизация: реакции и перевод сообщений (IDOR)

**Проблема:** эндпоинты опирались только на `message_id` без проверки права `view` на чат — теоретически возможны реакция и отправка текста в перевод по чужому сообщению.

**Сделано:**

- `App\Http\Controllers\MessageController::react` — перед валидацией: загрузка `chat`, проверка `$this->authorize('view', $message->chat)`.
- `App\Http\Controllers\MessageTranslationController::translate` — аналогично.

**Тесты:** `tests/Feature/Chat/MessageReactionTest.php` — назначение на чат в успешных сценариях; добавлен кейс «сотрудник без доступа к чату → 403».

### 1.2. Посты организации: защита от stored XSS

- **Сервер:** `App\Support\OrganizationRichTextSanitizer` (Symfony HtmlSanitizer, `allowSafeElements`, только `http`/`https` для ссылок и медиа).
- **Точки:** при сохранении/обновлении поста в `OrganizationController::validatePostPayload`, при отдаче в `transformPost` (легаси-данные в БД тоже проходят через санитайзер).
- **Зависимость:** `symfony/html-sanitizer`.
- **Тесты:** `tests/Unit/OrganizationRichTextSanitizerTest.php`, `tests/Feature/Organization/DepartmentPostSanitizationTest.php`.

### 1.3. Sanctum: срок жизни и отзыв токенов

- **`config/sanctum.php`:** `expiration` из `SANCTUM_EXPIRATION_MINUTES` (пусто = без истечения по времени; положительное число = минуты).
- **Отзыв всех PAT:** `User::revokeAllPersonalAccessTokens()` после смены пароля в `PasswordController`, после сброса пароля в `NewPasswordController`, при смене пароля админом и при `is_active => false` в `UserManagementController`.
- **`.env.example`:** комментарии к `SANCTUM_EXPIRATION_MINUTES` и к secure-cookie сессии.

### 1.4. Удаление сообщения и link preview

- **`MessageController::destroy`:** перед логикой «своё исходящее» добавлены `$this->authorize('view', $message->chat)` и проверка чата.
- **`LinkPreviewController`:** для hostname дополнительно проверяются все A/AAAA (и fallback `gethostbynamel`), что адреса публичные (`FILTER_FLAG_NO_PRIV_RANGE | NO_RES_RANGE`).

### 1.5. Тесты доступа

- `tests/Feature/Chat/MessageTranslationAccessTest.php` — перевод без доступа к чату → 403.
- `tests/Feature/Chat/AccessControlTest` — удаление сообщения в чужом чате → 403.
- `tests/Feature/Settings/UserManagementTest` — отзыв токенов при смене пароля и деактивации.

### 1.6. Сильные стороны (уже есть в проекте)

| Область | Реализация |
|--------|------------|
| Доступ к чатам | `ChatPolicy`, `$this->authorize('view', $chat)` в веб- и API-контроллерах чатов |
| Вещание | `routes/channels.php`: подписка на `chat.{id}` только при `$user->can('view', $chat)` |
| Медиа | `MediaController::show` — `authorize('view', $chat)` по сообщению |
| Веб-CSRF | Стандартный middleware Laravel для web-маршрутов |
| Логин | `LoginRequest`: rate limit; `AuthenticatedSessionController::store`: `session()->regenerate()` |
| Выход | `invalidate` + `regenerateToken` |
| Массовое присвоение | У `User` в `fillable` нет полей роли; роли через Spatie и админские Form Request |
| Аналитика диалогов | `DialogAnalyticsService::scopedChatsQuery` + ограничения в `DialogAnalyticsRequest` по `employee_id` / `department_id` |
| Текст чатов в UI | `resources/js/utils/waMarkup.ts`: экранирование HTML до разметки — снижение XSS в пузырьке сообщения |
| Вебхуки WhatsApp | Подпись / Bearer вместо сессии; отдельные middleware где нужно |
| Превью ссылок | `LinkPreviewController`: схема, localhost, литеральные приватные IP, DNS A/AAAA → только публичные адреса |

---

## 2. Оставшиеся рекомендации (по желанию)

### 2.1. DOMPurify на клиенте для постов

Дополнительный рубёж в `Post.vue` перед `v-html`, если данные когда-либо попадут в браузер минуя серверный санитайзер.

### 2.2. Куки сессии в production

В `.env` на HTTPS: `SESSION_SECURE_COOKIE=true`; при необходимости `SESSION_SAME_SITE=strict`, `SESSION_ENCRYPT=true`.

### 2.3. Mobile: один токен / ротация при логине

При жёсткой политике — ограничить число `personal_access_tokens` на пользователя или сбрасывать старые при `auth/login`.

### 2.4. Регрессии

Периодически: `docs/chat-system-test-checklist-top100.md`.

---

## 3. Оптимизации и устойчивость

### 3.1. Запросы к БД

- Проверять **N+1** на тяжёлых страницах (списки чатов, таймлайн, аналитика): `->with([...])`, индексы по `chat_id`, `message_timestamp`, `created_at`, полям фильтров аналитики.
- Для `LIKE '%...%'` в поисках контактов/пользователей — индексы часто не используются; при росте данных: полнотекстовый поиск (PostgreSQL/MySQL FTS) или отдельный поисковый движок.

### 3.2. Кэш и аналитика

- `DialogAnalyticsController` уже использует `Cache::remember` — следить за ключом (уже включает пользователя и фильтры) и TTL при изменении логики расчёта.
- При смене бизнес-правил аналитики — версионировать ключ или сбрасывать кэш.

### 3.3. Медиа и сессия

- В `MediaController` уже вызываются `session()->save()` / `session_write_close()` — это снижает блокировки сессии при параллельной загрузке картинок; сохранять этот паттерн для похожих «много запросов подряд» эндпоинтов.

### 3.4. Очереди и внешние сервисы

- Исходящие сообщения и синхронизация с WhatsApp — через jobs; при пиках нагрузки: мониторинг очереди, `failed_jobs`, retry с backoff.
- HTTP-клиент к WhatsApp-сервису: таймауты и идемпотентность там, где возможны повторы.

### 3.5. Сборки и фронтенд

- Разделение чанков, lazy-импорт тяжёлых страниц (настройки, аналитика), избыток данных в Inertia props — точки для Lighthouse и профилирования в браузере.

---

## 4. Краткий чеклист приёмки

- [x] Пост организации: ввод HTML/JS в теле — скрипты вырезаются на сервере; при необходимости добавить DOMPurify на клиенте.
- [x] Mobile: задать `SANCTUM_EXPIRATION_MINUTES` в проде при необходимости TTL; токены отзываются при смене пароля / деактивации.
- [ ] Production: secure cookies и SameSite по схеме деплоя (`.env`).
- [x] Реакция / перевод / удаление в чужом чату — 403 (тесты).
- [ ] CSRF на state-changing web POST без токена — 419.
- [ ] Права администратора только на маршрутах с `role:administrator` и в политиках.

---

*Обновлено: серверный HTML-sanitizer для постов, Sanctum expiration env, отзыв PAT, усиление link preview, authorize на destroy, тесты.*
