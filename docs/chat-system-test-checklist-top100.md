# Топ‑100 проверок системы чатов (ChatSwitch)

Документ составлен по анализу репозитория: маршруты [`routes/web.php`](../routes/web.php), [`routes/api.php`](../routes/api.php), контроллеры чата/сообщений/сессий, [`ChatService`](../app/Services/ChatService.php), джобы WhatsApp, события Echo, [`whatsapp-service`](../whatsapp-service/), политики [`ChatPolicy`](../app/Policies/ChatPolicy.php).

Используйте как регрессионный чеклист перед релизом. Порядок: от **системных сценариев** к **интеграции номеров**, затем **сообщения и UI**, **роли**, **сообщества**, **инфраструктура**.

---

## 1. Системные сообщения и автоматика (1–15)

1. **Системное сообщение в чате** (`direction=system`): запись в БД, отображение в UI, **не** уходит в WhatsApp (см. `ChatService::logSystemMessage`).
2. **Смена отделов у чата** — создаётся системное сообщение (`logDepartmentChange`) и broadcast в чат.
3. **Смена назначенных сотрудников** — системное сообщение (`logAssignmentChange`) и обновление списка ответственных.
4. **Пользователь «Система»** (`config/chatswitch.php`, миграция `ensure_system_user_for_automated_messages`): запись в `users`, `is_active=false`, вход в приложение недоступен.
5. **Исходящее от имени «Система»** в WhatsApp: тело с подписью `OperatorSignature` для служебного пользователя без ролей (`*Система*` + текст).
6. **Входящий звонок WhatsApp** — автоотклонение в Node (`Events.INCOMING_CALL` → `reject()`), вебхук `call_incoming` в Laravel.
7. **После отклонения звонка** — постановка `ProcessWhatsappCallRejectedJob`, не чаще одного автоответа на чат за окно rate limit (см. джобу).
8. **Текст автоответа** после звонка — доставка в WA и в UI, `sent_by_user_id` = системный пользователь.
9. **Звонок `fromMe`** — не должен порождать автоответ и лишние вебхуки (игнор в Node и в `broadcastIncomingCallNotification`).
10. **Звонок без известного чата** (`peerJid` не совпал с `chats.whatsapp_chat_id`) — отклонение в Node, в логах Laravel предупреждение, без падения очереди.
11. **Broadcast `ChatsListNotify` с типом `call_incoming`** — получатели с доступом к чату, заголовок/иконка/учёт `is_muted`.
12. **Broadcast при назначении** (`ChatsListNotify`, тип `assignment`) — только добавленные `user_id`.
13. **Системное сообщение при исходящем от администратора** — логика `releaseAdministratorIfSoleAssigneeOnOutbound` / `attachAdministratorWhenJoiningStaffedChat` (назначения не «ломают» супервизию).
14. **Пересчёт превью чата** после удаления сообщения (`refreshChatLastMessageSnapshot`).
15. **Пользователь с ролью без доступа к чату** — не видит системные/обычные сообщения чужого чата (политика `view`).

---

## 2. Подключённые номера, этапы и reconciling (16–35)

16. **Список сессий** в «Настройки → Подключения» (`WhatsappSessionController@index`): отображение всех записей, порядок, флаг `whatsappServiceReachable`.
17. **Health WhatsApp-сервиса** (`WhatsappService::healthPing` / `healthReachable`) — при недоступности UI показывает недоступность микросервиса.
18. **Создание новой сессии** (`POST /settings/connections`) — запись в БД, `session_name`, привязка к пользователям при необходимости.
19. **Инициализация сессии** (`initialize`) — запрос к Node, статус `connecting`.
20. **Получение QR** (`GET .../qr`) — картинка/JSON, повторные запросы при ожидании QR.
21. **Статус сессии** (`GET .../status`) — `isReady`, `hasQR`, `isInitializing`, `lastError`.
22. **Verify** (`POST .../verify`) — «живость» Puppeteer и состояние `CONNECTED`.
23. **Этап `qr_pending`** — обновление из вебхука `qr_generated` и broadcast `WhatsappStatusChanged`.
24. **Этап `connected`** — вебхук `connected`, заполнение `phone_number`, `wa_name`, `wa_platform`, `connected_at`.
25. **Этап `disconnected`** — вебхук `disconnected`, `disconnected_at`, **без** смены `desired_state` на logged_out (watchdog может поднять снова).
26. **`desired_state = logged_out`** — после явного «Выйти» reconciling в `index` **не** переинициализирует сессию.
27. **`desired_state = active`** — при отсутствии клиента в Node вызывается `initializeSession`, статус `connecting`.
28. **Logout** (`POST .../logout`) — разрыв WA Web, очистка клиента в Node, корректный `desired_state`.
29. **Destroy сессии** — удаление из БД, Node `destroy`, отсутствие «фантомных» сессий при старте Node (эндпоинт `legal-sessions`).
30. **Обновление отображаемого имени/цвета сессии** (`PATCH .../connections/{session}`).
31. **Диагностика сессии** (`GET .../diagnostics`) — данные для администратора.
32. **Несколько активных номеров** — чаты привязаны к `whatsapp_session_id`, переключение сессий в списке чатов/новом чате.
33. **Чат без сессии** (`whatsapp_session_id` null) — UI блокировки отправки (см. `ChatInput`), пересылка недоступна.
34. **Событие `WhatsappStatusChanged`** на канале `whatsapp-status` — только роли administrator/manager (`routes/channels.php`).
35. **Токен `legal-sessions`** — только с `Bearer` совпадающим с `WHATSAPP_SERVICE_TOKEN` / `LARAVEL_API_TOKEN`.

---

## 3. Входящие и исходящие сообщения, типы, очередь (36–55)

36. **Вебхук `message_received`** — постановка `ProcessWhatsappInboundJob`, очередь `whatsapp`.
37. **Входящий текст** — создание/поиск чата и контакта, `storeInboundMessage`, инкремент `unread_count`.
38. **Входящее медиа** — job + `POST /api/whatsapp/inbound-media`, дедупликация, broadcast `NewMessageReceived`.
39. **Исходящий текст** — подпись оператора `OperatorSignature`, `SendOutboundMessageJob`, ack `sent`/`delivered`/`read` по вебхуку `message_status`.
40. **Монотонность ack** — события WA не откатывают галочку назад (ранги в `WhatsappWebhookController@onMessageStatus`).
41. **Ответ (quote)** — `quoted_message_id` в отправке и в Node `send-message`.
42. **Пересылка одного сообщения** — `forward` + очередь типа `forward`.
43. **Массовая пересылка** — `forward-bulk`, проверка сессии.
44. **Загрузка файла** — `uploadFile`, типы MIME, лимиты, очередь `media`.
45. **Голосовое / аудио** — конвертация webm/ограничения в `SendOutboundMessageJob` (не ломать WA).
46. **Опрос** — `sendPoll`, доставка в WA и отображение.
47. **Контакт (vCard)** — `sendContact`.
48. **Реакция на сообщение** — синхронно в WA или `deferred` + `SyncMessageReactionToWhatsappJob`; нормализация id `@lid`.
49. **Реакция на входящее от клиента** — вебхук `message_reaction`, обновление UI.
50. **Удаление сообщения** — политика: админ — любые исходящие; остальные — только свои исходящие.
51. **Typing** — `POST .../typing`, broadcast `UserTyping`, Node `set-typing`.
52. **Mark read** — сброс `unread_count`, `sendSeen` в Node.
53. **Маршрут `messages.retry`** — в [`routes/web.php`](../routes/web.php) объявлен `MessageController::retry`, но в текущем [`MessageController.php`](../app/Http/Controllers/MessageController.php) метода **нет** (ожидается ошибка до реализации — закрыть тестом или добавить `retry`).
54. **Пустая очередь `default` без `whatsapp`** — убедиться, что воркер слушает `--queue=whatsapp,default` (см. `deploy/supervisor/chatswitch-queue.conf`).
55. **Повторные попытки job** при transport error — `initializeSession` в `SendOutboundMessageJob`.

---

## 4. UI списка чатов и окна переписки (56–70)

56. **Список чатов** — пагинация, поиск, закреплённые сверху, превью последнего сообщения.
57. **Архив** — страница `chats.archived`, архивирование/разархивирование.
58. **Открытие чата** — `chats.show`, загрузка сообщений, Echo `private chat.{id}`.
59. **Бесконечная прокрутка / подгрузка** — `timeline` или пагинация в `Show.vue`.
60. **Поиск по сообщениям внутри чата** — фильтр `displayedMessages`.
61. **Панель контакта** — данные, участники группы, поиск (после удаления кнопок звонков).
62. **Шапка чата** — отделы, назначение сотрудников, меню (без звонков).
63. **Закреплённое сообщение в чате** — баннер, переход, открепление.
64. **Мультивыбор и пересылка** из списка сообщений.
65. **Информация о сообщении** — только исходящие от текущего пользователя (ограничение в UI/контроллере).
66. **Статусы сообщений** — компонент `MessageStatus`, симуляция delivered/read где применимо.
67. **Эмодзи-пикер и вложения** в `ChatInput`.
68. **Mute / диалог mute** (`toggleMute`, `MuteChatDialog`).
69. **Избранное и «непрочитано»** — `toggleFavorite`, `toggleUnread`.
70. **Очистка чата** — `clear`, подтверждение, последствия для превью.

---

## 5. Контакты, старт чата, группы (71–82)

71. **Старт чата по номеру** — `chats.start`, редирект в существующий или новый чат.
72. **Создание группы** — `createGroup`, участники, связь с WA.
73. **Синхронизация групп** — `syncGroups`.
74. **Участники группы** — `groupParticipants`, отображение в `ContactInfoPanel`.
75. **Страница контактов** — `chats.contacts`, `contacts.index`.
76. **Upsert контакта** — `contacts.upsert`, дубликаты по телефону/WA id.
77. **Сохранение контакта из чата** — `saveContact`.
78. **Связанные чаты одного контакта на разных номерах** — блок в `ContactInfoPanel` (`contactChats`).
79. **Ресинк имён в группе** — `ChatService::resyncGroupSenderNames` (если вызывается из админки/команды).
80. **Link preview** — `GET /link-preview` (middleware роли).
81. **Отдача медиа** — `GET /media/{media}` с авторизацией.
82. **Новый чат (панель)** — `NewChatPanel`, выбор сессии и контакта.

---

## 6. Назначения, отделы, роли и доступ (83–90)

83. **Администратор** — видит все чаты, может назначать, синхронизировать отделы, управлять закреплением.
84. **Руководитель** — видит чаты отдела и назначения подчинённых; `syncDepartments` при `view`.
85. **Сотрудник** — видит назначенные на себя и «пул» отдела без назначенных; после назначения другим — чат исчезает из пула.
86. **Назначение одного пользователя** — `POST .../assign`.
87. **Синхронизация назначений** — `assign/sync`.
88. **Снятие назначения** — `DELETE .../assign/{assignment}`.
89. **Синхронизация отделов чата** — `POST .../departments`.
90. **Политика `sendMessage` / `manage` / `delete`** — согласованность с UI кнопок.

---

## 7. Сообщества (Communities) (91–95)

91. **Список сообществ** — `communities.index`.
92. **Создание / редактирование / удаление** — store, update, destroy.
93. **Просмотр сообщества** — `show`, доступ по роли из контроллера.
94. **Доступные группы для привязки** — `available-groups`.
95. **Привязка / отвязка группы** — `link-group`, `unlink-group`.

---

## 8. Реалтайм, API, безопасность, инфра (96–100)

96. **Канал `chat.{id}`** — только `authorize('view', $chat)`; подписка Echo в `Show.vue`.
97. **Канал `chats.list.{userId}`** — только владелец `userId`.
98. **Вебхук WhatsApp** — middleware подписи `whatsapp.webhook`, отклонение при неверном HMAC.
99. **Inbound-media** — Bearer token, 404 `retry: true` пока сообщение не создано.
100. **Reverb / broadcasting** — конфиг `chatswitch-reverb`, доставка `NewMessageReceived`, `MessageAckUpdated`, `MessageReactionsUpdated`, `UserTyping`, `ChatsListNotify`, `WhatsappStatusChanged` без утечек в публичные каналы.

---

## Как прогонять

- **Роли**: минимум три тестовых пользователя — администратор, руководитель отдела, сотрудник.
- **Номера**: две WA-сессии + чаты на каждой для проверки пересечения контактов.
- **Очередь**: воркер с `--queue=whatsapp,default`; после сценариев со звонком — проверка `failed_jobs`.
- **Node**: health `GET /health`, логи `storage/logs/whatsapp-node.log` (если настроено) и stdout сервиса.

При изменении маршрутов или политик обновляйте нумерацию и добавляйте ссылки на PR внизу файла.
