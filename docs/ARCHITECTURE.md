# ChatSwitch — технологическая карта

Как устроена система, как подключается номер WhatsApp, какие сервисы в этом участвуют, что лежит на диске, как идут сообщения и статусы в реальном времени.

---

## 1. Обзор стека

| Слой | Технология | Назначение |
|------|------------|------------|
| Frontend (SPA) | Vue 3 + Inertia.js + TypeScript + Vite | `resources/js` — все страницы и компоненты |
| Backend | Laravel 11, PHP 8.3+ | Контроллеры, сервисы, очереди, политики |
| Realtime | Laravel Reverb (WebSocket) | Приватные каналы `App.Models.User.{id}` и `chats.{id}` |
| БД | MySQL 8 (SQLite в тестах) | `whatsapp_sessions`, `chats`, `messages`, `users`, `roles` |
| Кэш / очереди / сессии | Redis + Laravel Horizon | Очередь `whatsapp-inbound`, мониторинг |
| WhatsApp‑мост | Node.js микросервис `whatsapp-service` на `whatsapp-web.js` | По одному Chromium‑клиенту на сессию |
| Браузер | Chrome for Testing (`~/.cache/puppeteer/chrome/linux-*`) | Headless, НЕ snap — чтобы уважал `--user-data-dir` |
| Управление процессами | pm2 | `chatswitch-whatsapp` под pm2 |

---

## 2. Карта компонентов

```mermaid
flowchart LR
    subgraph Browser["Браузер оператора"]
      UI["Vue 3 SPA<br/>resources/js"]
    end

    subgraph Server["Сервер (один VPS)"]
      direction TB

      subgraph Laravel["Laravel 11 (PHP 8.3)"]
        Routes["Routes: web.php / api.php / channels.php"]
        Controllers["Controllers<br/>final readonly"]
        Services["Services<br/>WhatsappService"]
        Policies["Policies / Form Requests"]
        Jobs["Jobs<br/>ProcessWhatsappInboundJob"]
        Events["Events + Broadcasting<br/>WhatsappStatusChanged<br/>MessageCreated"]
      end

      Reverb["Laravel Reverb<br/>WebSocket :8080"]
      Redis[("Redis<br/>cache + queue")]
      MySQL[("MySQL<br/>whatsapp_sessions<br/>chats / messages")]
      Horizon["Horizon<br/>очереди"]

      subgraph WAS["whatsapp-service (Node / pm2)"]
        direction TB
        Express["Express REST<br/>:3050 (127.0.0.1)"]
        Manager["clientManager<br/>Map&lt;name, WhatsAppClient&gt;"]
        ClientA["WhatsAppClient wa-xxxx<br/>+ per-session Mutex"]
        ClientB["WhatsAppClient wa-yyyy"]
        Cleanup["sessionProfileCleanup<br/>SingletonLock / fuser / kill"]
      end

      subgraph Chromes["Headless Chrome (по одному на сессию)"]
        ChromeA["Chrome for Testing<br/>--user-data-dir=.wwebjs_auth/session-wa-xxxx"]
        ChromeB["Chrome for Testing<br/>--user-data-dir=.wwebjs_auth/session-wa-yyyy"]
      end
    end

    WhatsApp[("WhatsApp Web<br/>web.whatsapp.com")]

    UI -- "HTTPS (Inertia)" --> Routes
    Routes --> Controllers --> Services
    Services -- "HTTP Bearer token" --> Express
    Express --> Manager --> ClientA
    Manager --> ClientB
    ClientA -- "puppeteer" --> ChromeA
    ClientB -- "puppeteer" --> ChromeB
    ChromeA <-- "wss" --> WhatsApp
    ChromeB <-- "wss" --> WhatsApp
    ClientA -.->|"webhook POST<br/>HMAC X-Signature"| Routes
    Controllers --> MySQL
    Controllers --> Events
    Jobs --> Redis
    Horizon --> Redis
    Events -. "broadcast" .-> Reverb
    UI <==>|"WebSocket Reverb"| Reverb
    ClientA -. "user-data-dir" .- Cleanup
```

---

## 3. Справочник портов и путей

| Ресурс | Где |
|--------|-----|
| Публичный HTTPS Laravel | `https://chatswitch.10k.kz/` |
| Reverb WebSocket | `:8080` (через reverse‑proxy) |
| whatsapp-service REST | `http://127.0.0.1:3050` (только loopback) |
| Chrome‑бинарник | `/root/.cache/puppeteer/chrome/linux-146.0.7680.153/chrome-linux64/chrome` |
| Профили сессий | `/var/www/chatswitch.10k.kz/whatsapp-service/.wwebjs_auth/session-<name>/` |
| web‑version cache | `/var/www/chatswitch.10k.kz/whatsapp-service/.wwebjs_cache/<name>/` |
| Логи Node | `/var/www/chatswitch.10k.kz/whatsapp-service/logs/{out,error}.log` |
| pm2 app | `chatswitch-whatsapp` |

Laravel ↔ Node общаются по REST: `Bearer <WHATSAPP_SERVICE_TOKEN>` в обе стороны. Входящие webhook‑и от Node дополнительно подписываются HMAC (`X-Signature`).

---

## 4. Модель данных (ключевые таблицы)

```mermaid
erDiagram
    WHATSAPP_SESSIONS ||--o{ CHATS : "owns"
    CHATS ||--o{ MESSAGES : "has"
    USERS }o--o{ WHATSAPP_SESSIONS : "user_whatsapp_session"
    USERS ||--o{ CHATS : "assigned_to"

    WHATSAPP_SESSIONS {
        id bigint PK
        session_name string UK "wa-xxxxxxxx"
        display_name string "WhatsApp #1"
        phone_number string "реальный номер из wid.user"
        wa_name string "pushname"
        wa_platform string "android / iphone / web"
        status enum "connecting / qr_pending / connected / disconnected"
        connected_at datetime
        disconnected_at datetime
    }
    CHATS {
        id bigint PK
        whatsapp_session_id bigint FK "SET NULL при удалении сессии"
        wa_chat_id string "contact@c.us"
        assigned_user_id bigint FK
    }
    MESSAGES {
        id bigint PK
        chat_id bigint FK
        whatsapp_session_id bigint FK
        direction enum "inbound / outbound / system"
        type string "chat / image / audio / ..."
        body text
        message_timestamp datetime
        ack enum "pending / sent / delivered / read"
    }
```

`whatsapp_session_id` на `chats` и `messages` — `SET NULL`, поэтому удаление сессии не ломает историю: перед удалением в каждый чат добавляется системное сообщение «📵 Номер отключён».

---

## 5. Жизненный цикл сессии WhatsApp

```mermaid
stateDiagram-v2
    [*] --> connecting : admin нажал «Подключить»\nили auto-restore при старте Node
    connecting --> qr_pending : Chrome открыл web.whatsapp.com\nи отдал QR
    qr_pending --> connecting : QR просрочился, Chrome сгенерировал новый
    qr_pending --> connected : оператор отсканировал QR\n(event READY)
    connecting --> connected : прошла авторизация по сохранённому профилю
    connected --> disconnected : logout, потеря связи,\nclient.on('disconnected')
    disconnected --> connecting : админ нажал «Переподключить»
    connected --> [*] : destroy (удаление) —\nFK SET NULL + system-notice в чаты
    disconnected --> [*] : destroy
```

Состояние `whatsapp_sessions.status` синхронизируется двумя путями:

1. **Node → Laravel webhook** (мгновенно): `connected`, `disconnected`, `qr_generated`, `auth_failure`, `message_received`, `message_status`.
2. **Laravel → Node REST** (при заходе на страницу `/settings/connections` и периодическом polling через `useSessionStatus`): `GET /api/sessions/:name/status` → контроллер `reconcileSessionsWithMicroservice()` приводит БД в соответствие.

---

## 6. Как подключается новый номер (пошагово)

```mermaid
sequenceDiagram
    autonumber
    actor Admin as Админ (браузер)
    participant Vue as Connections.vue
    participant L as Laravel<br/>WhatsappSessionController
    participant DB as MySQL
    participant N as whatsapp-service<br/>(Node / pm2)
    participant M as per-session Mutex
    participant C as Chrome for Testing
    participant W as WhatsApp Web

    Admin->>Vue: «Добавить подключение»
    Vue->>L: POST /settings/connections
    L->>L: генерирует session_name = "wa-" + 8 hex
    L->>N: GET /health (Bearer token)
    N-->>L: { status: ok }
    L->>DB: INSERT whatsapp_sessions\nstatus = connecting
    L->>N: POST /api/sessions/{name}/initialize
    N->>M: runExclusive(name, initialize)
    M->>N: releaseStaleChromiumProfileLocks()<br/>(снять SingletonLock, kill осиротевший pid)
    N->>C: new Chromium with<br/>--user-data-dir=.wwebjs_auth/session-{name}
    C->>W: открывает web.whatsapp.com
    W-->>C: QR-код
    C-->>N: event QR_RECEIVED
    N->>L: webhook POST /api/webhooks/whatsapp<br/>event=qr_generated + HMAC
    L->>DB: status = qr_pending
    L-->>Vue: broadcast WhatsappStatusChanged (Reverb)
    Vue-->>Admin: показать QR в модалке

    Admin->>Admin: сканирует QR телефоном
    W-->>C: authenticated + ready
    C-->>N: event READY (client.info.wid, pushname, platform)
    N->>L: webhook event=connected<br/>{phone, name, platform}
    L->>DB: status = connected<br/>phone_number, wa_name, wa_platform, connected_at
    L-->>Vue: broadcast
    Vue-->>Admin: карточка «Подключено»
```

Ключевые защиты от ошибки «The browser is already running…»:

- **Per‑session Mutex** (`sessionMutex.js`) — параллельные вызовы `initialize/destroy/logout` на одну сессию выстраиваются в очередь.
- **`releaseStaleChromiumProfileLocks`** — перед каждым `initialize` читает PID из `SingletonLock` (symlink `host-PID`), если процесс мёртв → удаляет lock, если жив‑осиротевший → `process.kill(-9)` + `fuser -k`.
- **`_hardDestroyClient`** — на `destroy/logout` с таймаутом 8 с; если зависло — `pupBrowser.process().kill('SIGKILL')`.
- **`sweepStaleLocksOnStartup`** — при старте Node обходит все `session-*` и снимает осиротевшие локи от упавшего предыдущего процесса.

Подробности про то, почему нельзя использовать `/usr/bin/chromium-browser` (snap): см. раздел 11.

---

## 7. Входящее сообщение (Inbox)

```mermaid
sequenceDiagram
    participant W as WhatsApp Web
    participant C as Chrome (session-wa-xxxx)
    participant N as whatsapp-service
    participant L as Laravel /api/webhooks/whatsapp
    participant Q as Redis queue<br/>whatsapp-inbound
    participant J as ProcessWhatsappInboundJob
    participant DB as MySQL
    participant R as Reverb
    participant UI as Chats.vue (оператор)

    W-->>C: новое сообщение
    C-->>N: Events.MESSAGE_RECEIVED<br/>(+dedup по id 8s)
    N->>N: messageInbound.js: загрузить media,<br/>контакт, пуш-имя
    N->>L: POST webhook event=message_received<br/>HMAC подпись
    L->>L: проверка HMAC + токена
    L->>Q: ProcessWhatsappInboundJob::dispatch(data)
    L-->>N: 200 {status: queued}
    Q->>J: worker подхватывает
    J->>DB: upsert Chat (по wa_chat_id + session)
    J->>DB: insert Message (direction=inbound, ack=delivered)
    J->>R: broadcast MessageCreated(chats.{id})
    J->>R: broadcast ChatUpdated(App.Models.User.{id})
    R-->>UI: WebSocket push
    UI-->>UI: список чатов + окно чата обновляются без F5
```

Очередь нужна, чтобы Node не ждал медленных операций (медиа, выборка контактов, broadcasting) — webhook возвращает `200 queued` за миллисекунды.

---

## 8. Исходящее сообщение

```mermaid
sequenceDiagram
    actor Op as Оператор
    participant UI as Chat.vue
    participant L as MessageController
    participant DB as MySQL
    participant S as WhatsappService (PHP)
    participant N as whatsapp-service
    participant C as Chrome (сессия чата)
    participant W as WhatsApp
    participant R as Reverb

    Op->>UI: печатает + Enter (или загружает файл)
    UI->>L: POST /chats/{id}/messages<br/>(FormRequest валидация)
    L->>DB: Message::create(direction=outbound, ack=pending)
    L->>R: broadcast MessageCreated (оптимистично)
    L->>S: sendMessage / sendMediaUpload
    S->>N: POST /api/send-message<br/>X-Whatsapp-Session: wa-xxxx
    N->>C: client.sendMessage(to, body)
    C->>W: wss → отправка
    W-->>C: ack 1 → 2 → 3 (sent / delivered / read)
    C-->>N: event message_ack
    N->>L: webhook event=message_status
    L->>DB: update Message.ack
    L->>R: broadcast status → UI обновляет галочки
```

---

## 9. Realtime каналы (Reverb)

| Канал | Тип | Кто может подписаться | Что транслируется |
|-------|-----|------------------------|-------------------|
| `App.Models.User.{id}` | private | только сам пользователь | список его чатов (`ChatUpdated`), статусы его сессий (`WhatsappStatusChanged`) |
| `chats.{chatId}` | private | админ/менеджер всегда, сотрудник — если `assigned_user_id = id` | `MessageCreated`, `MessageUpdated`, `TypingStarted` |

Авторизация каналов — `routes/channels.php`, защищена политиками (`ChatPolicy`). Фронт подписывается через `resources/js/echo.ts`.

---

## 10. Файловая система одной сессии

```
whatsapp-service/
├── .wwebjs_auth/
│   └── session-wa-xxxxxxxx/       ← user-data-dir Chromium
│       ├── Default/                 cookies, local storage (авторизация WhatsApp)
│       ├── SingletonLock            symlink "host-<pid>" пока Chrome жив
│       ├── SingletonCookie
│       ├── SingletonSocket
│       └── ...
├── .wwebjs_cache/
│   └── wa-xxxxxxxx/                 web.js кэш версии WhatsApp Web (отдельный на сессию)
└── logs/
    ├── out.log
    └── error.log
```

Полный сброс авторизации конкретного номера — `rm -rf .wwebjs_auth/session-<name>` при остановленной сессии. Затем `POST /api/sessions/<name>/initialize` выдаст новый QR.

---

## 11. Почему именно Chrome for Testing, а не snap Chromium

Snap‑версия `/usr/bin/chromium-browser` **игнорирует** переданный `--user-data-dir` (AppArmor принудительно подменяет профиль на `/root/snap/chromium/common/chromium`). В результате все WhatsApp‑сессии конкурируют за один профиль и со второй получаем:

```
The browser is already running for .../session-wa-xxxx.
Use a different `userDataDir` or stop the running browser first.
```

Поэтому `whatsapp-service/src/whatsapp/clientConfig.js::resolveChromeExecutable()`:

1. Если `PUPPETEER_EXECUTABLE_PATH` задан и не snap и существует — берёт его.
2. Иначе — последняя версия `~/.cache/puppeteer/chrome/linux-*` (скачивает Puppeteer).
3. Иначе — `/usr/bin/google-chrome-stable` / `/usr/bin/google-chrome`.
4. Snap‑пути игнорируются с warning'ом в лог.

Восстановить скачанный Chrome, если кэш очистили:

```bash
cd /var/www/chatswitch.10k.kz/whatsapp-service
npx @puppeteer/browsers install chrome@stable
pm2 restart chatswitch-whatsapp
```

---

## 12. Что происходит при `pm2 restart chatswitch-whatsapp`

```mermaid
flowchart TB
    A["pm2 шлёт SIGTERM"] --> B["index.js: destroyAll()<br/>каждый клиент через мьютекс →<br/>client.destroy() → Chrome close"]
    B --> C["процесс завершается"]
    C --> D["pm2 поднимает новый процесс"]
    D --> E["clientConfig.js<br/>resolveChromeExecutable()"]
    E --> F["sweepStaleLocksOnStartup(AUTH_DIR)<br/>убить осиротевшие Chrome pid'ы,<br/>удалить SingletonLock"]
    F --> G["Express listen :3050"]
    G --> H["autoRestoreAllSessions()<br/>последовательно, с задержкой 2.5с"]
    H --> I["каждая сессия:<br/>mutex → releaseStaleLocks → Client.initialize"]
    I --> J["READY → webhook connected → UI зелёный"]
```

---

## 13. Безопасность и разграничение

| Проверка | Где |
|----------|-----|
| Bearer `WHATSAPP_SERVICE_TOKEN` | middleware Express в Node и заголовок Laravel `WhatsappService` |
| HMAC webhook | Node подписывает payload `X-Signature`, Laravel сверяет в `VerifyWhatsappWebhook` middleware |
| Политики доступа | `ChatPolicy`, `WhatsappSessionPolicy` — менеджер/сотрудник/админ |
| Приватные каналы | `routes/channels.php` + policies |
| EnsureActiveUser | middleware на web‑группе — деактивированный юзер разлогинивается автоматически |
| FormRequest валидация | все mutating endpoint'ы в `App\Http\Requests\...` |
| Диагностика сессии | `WhatsappSessionController::diagnostics` требует `manage` policy (только админ) |

---

## 14. Операционные команды (шпаргалка)

```bash
# Статус сервиса
pm2 status
pm2 logs chatswitch-whatsapp --lines 50

# Рестарт с применением ecosystem.config.js
cd /var/www/chatswitch.10k.kz/whatsapp-service
pm2 delete chatswitch-whatsapp
pm2 start ecosystem.config.js
pm2 save

# Горизонт (очереди Laravel)
php artisan horizon:status
php artisan queue:restart

# Полный сброс одного номера (делать при остановленной сессии!)
curl -s -X POST -H "Authorization: Bearer $TOKEN" \
  http://127.0.0.1:3050/api/sessions/wa-xxxxxxxx/destroy
rm -rf .wwebjs_auth/session-wa-xxxxxxxx
# затем в UI — «Переподключить»

# Проверить живой ли Chrome для сессии
ps -ef | grep "user-data-dir=.*session-wa-xxxxxxxx"

# Быстрая диагностика из UI
# /settings/connections → «Подробности» у нужной сессии
```

---

## 15. Масштабирование (growing 5–30 номеров, десятки операторов)

- **Один VPS, один `whatsapp-service`**, несколько клиентов в одном Node процессе — нормально до ~30 сессий (память ~150–250 МБ на Chrome).
- Вертикально: поднять `--max-old-space-size` у Node и выделить больше RAM под Chrome‑ы.
- Горизонтально: разнести часть сессий в отдельный инстанс `whatsapp-service` (своя папка `.wwebjs_auth`, свой порт, свой токен) — Laravel выбирает инстанс по `session_name` (сейчас используется единственный base URL; при необходимости добавить маршрутизацию в `WhatsappService`).
- Очередь и broadcasting уже отвязаны — можно запускать Horizon с несколькими воркерами и Reverb с кластером.

---

Если правите архитектуру — обновляйте этот файл. Он держит «карту» кодовой базы и спасает от регресса по тем же граблям (snap Chromium, stale SingletonLock, гонки на `initialize`).
