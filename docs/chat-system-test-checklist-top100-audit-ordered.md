# Аудит топ-100 проверок системы чатов ChatSwitch

Формат: по каждому чекпоинту сначала указан вопрос/проверка, затем вывод по статическому аудиту кода. Реальные E2E-сценарии с живым WhatsApp, QR, Puppeteer и браузерной доставкой Reverb отдельно не прогонялись.

## 1. Системные сообщения и автоматика

1. Вопрос: системное сообщение в чате (`direction=system`) записывается в БД, отображается в UI и не уходит в WhatsApp?
   Вывод: OK — `ChatService::logSystemMessage` создаёт системное сообщение и делает broadcast в чат; отправки через WhatsApp-сервис нет.

2. Вопрос: при смене отделов у чата создаётся системное сообщение и broadcast?
   Вывод: OK — `ChatController::syncDepartments` вызывает `ChatService::logDepartmentChange`, который логирует системное сообщение.

3. Вопрос: при смене назначенных сотрудников создаётся системное сообщение и обновляется список ответственных?
   Вывод: OK — `ChatAssignmentController` вызывает `logAssignmentChange` в `store`, `sync`, `destroy` и возвращает обновлённые assignments.

4. Вопрос: пользователь «Система» существует, неактивен и не может войти в приложение?
   Вывод: OK — миграция создаёт `system@chatswitch.internal` с `is_active=false`, а middleware блокирует неактивных пользователей.

5. Вопрос: исходящее от имени «Система» получает подпись `*Система*`?
   Вывод: OK — `OperatorSignature` для пользователя без ролей строит подпись только из имени, автоответ использует системного пользователя.

6. Вопрос: входящий WhatsApp-звонок автоотклоняется в Node и отправляет webhook `call_incoming` в Laravel?
   Вывод: OK — `Events.INCOMING_CALL` вызывает `call.reject()` и `notifyLaravel('call_incoming', ...)`.

7. Вопрос: после отклонения звонка ставится `ProcessWhatsappCallRejectedJob` и соблюдается rate limit?
   Вывод: OK — webhook dispatches job, job работает в очереди `whatsapp` и ограничивает автоответ одним разом на 90 секунд на чат.

8. Вопрос: автоответ после звонка доставляется в WhatsApp и UI от системного пользователя?
   Вывод: OK — job создаёт outbound-сообщение от системного пользователя, делает broadcast и ставит `SendOutboundMessageJob`.

9. Вопрос: звонок `fromMe` не порождает автоответ и лишние webhook-эффекты?
   Вывод: OK — `fromMe` отсекается и в Node, и в Laravel notification/job path.

10. Вопрос: звонок без известного чата отклоняется, логируется и не роняет очередь?
    Вывод: PARTIAL — очередь не падает, но уровень лога в job сейчас `info`, тогда как чеклист ожидает warning.

11. Вопрос: `ChatsListNotify` с типом `call_incoming` отправляется получателям с доступом к чату, с title/icon/is_muted?
    Вывод: PARTIAL — payload корректный, но `ChatBroadcastAudience` для неназначенных чатов шире, чем `ChatPolicy::view`, есть риск лишних list-уведомлений.

12. Вопрос: broadcast при назначении отправляется только добавленным `user_id`?
    Вывод: OK — `broadcastAssignmentAdded` считает diff и шлёт `ChatsListNotify` только в каналы добавленных пользователей.

13. Вопрос: исходящее от администратора не ломает супервизию назначений?
    Вывод: OK — `releaseAdministratorIfSoleAssigneeOnOutbound` и `attachAdministratorWhenJoiningStaffedChat` реализованы и покрыты feature-тестами.

14. Вопрос: после удаления сообщения пересчитывается preview последнего сообщения чата?
    Вывод: FAIL — `refreshChatLastMessageSnapshot` есть, но `MessageController::destroy` его не вызывает.

15. Вопрос: пользователь без доступа к чату не видит системные/обычные сообщения чужого чата?
    Вывод: OK — доступ к чату контролируется `ChatPolicy::view`, есть feature-тесты на запрет для неназначенного сотрудника.

## 2. Подключённые номера, этапы и reconciling

16. Вопрос: список сессий в настройках отображает записи, порядок и `whatsappServiceReachable`?
    Вывод: OK — `WhatsappSessionController::index` отдаёт сессии по `created_at` и флаг доступности сервиса.

17. Вопрос: health WhatsApp-сервиса корректно показывает недоступность микросервиса в UI?
    Вывод: OK — `WhatsappService::healthPing/healthReachable` вызываются в `index`, UI получает `whatsappServiceReachable`.

18. Вопрос: создание новой сессии пишет запись в БД, `session_name` и привязку пользователей при необходимости?
    Вывод: PARTIAL — сессия создаётся и инициализируется, но привязка пользователей в `POST /settings/connections` не реализована, она живёт отдельно в управлении пользователями.

19. Вопрос: инициализация сессии отправляет запрос в Node и ставит статус `connecting`?
    Вывод: OK — `initialize` ставит `status=connecting`, `desired_state=active` и вызывает Node `/initialize`.

20. Вопрос: получение QR возвращает картинку/JSON и поддерживает повторные запросы?
    Вывод: OK — Laravel проксирует JSON, Node отдаёт QR как data URL или сообщает, что QR ещё недоступен.

21. Вопрос: статус сессии содержит `isReady`, `hasQR`, `isInitializing`, `lastError`?
    Вывод: OK — Node `/status` возвращает эти поля, Laravel синхронизирует состояние БД.

22. Вопрос: verify проверяет живость Puppeteer и состояние `CONNECTED`?
    Вывод: OK — `verify` ходит в Node/client state и возвращает `alive`, `state`, `browser_connected` и session snapshot.

23. Вопрос: этап `qr_pending` обновляется вебхуком `qr_generated` и broadcast'ится?
    Вывод: OK — `onQrGenerated` ставит `status=qr_pending` и шлёт `WhatsappStatusChanged`.

24. Вопрос: этап `connected` заполняет телефон, имя, платформу и `connected_at`?
    Вывод: OK — `onConnected` обновляет `phone_number`, `wa_name`, `wa_platform`, `connected_at`.

25. Вопрос: этап `disconnected` не меняет `desired_state` на `logged_out`?
    Вывод: OK — webhook меняет только фактический статус и `disconnected_at`.

26. Вопрос: `desired_state=logged_out` после logout не переинициализируется reconcile'ом?
    Вывод: OK — reconcile пропускает такие сессии.

27. Вопрос: `desired_state=active` при отсутствии клиента в Node вызывает initialize и ставит `connecting`?
    Вывод: PARTIAL — при ошибочном/пустом ответе Node reconcile инициализирует, но строгий кейс «нет клиента в Node» частично покрывается отдельной командой `whatsapp:heal`.

28. Вопрос: logout разрывает WA Web, очищает клиента в Node и фиксирует `desired_state`?
    Вывод: OK — Laravel вызывает Node `/logout`, Node удаляет client, БД получает `desired_state=logged_out`.

29. Вопрос: destroy удаляет сессию, вызывает Node destroy и предотвращает phantom-сессии?
    Вывод: OK — Laravel вызывает `destroySession` и удаляет запись, Node startup сверяется с `/api/whatsapp/legal-sessions`.

30. Вопрос: обновление отображаемого имени/цвета сессии работает?
    Вывод: OK — `WhatsappSessionController::update` обновляет `display_name` и `display_color`.

31. Вопрос: диагностика сессии отдаёт данные администратору?
    Вывод: OK — endpoint авторизован через `manage` и возвращает session, health, latency, node status и счётчики.

32. Вопрос: несколько активных номеров корректно привязаны к чатам и доступны в списке/новом чате?
    Вывод: PARTIAL — `whatsapp_session_id` и выбор сессии в новом чате есть, но отдельного фильтра/переключателя списка по сессии во frontend-аудите не найдено.

33. Вопрос: чат без сессии блокирует отправку и пересылку?
    Вывод: OK — `ChatInput` показывает предупреждение при `sessionId=null`, пересылка в `Show.vue` блокируется toast'ом.

34. Вопрос: `WhatsappStatusChanged` на `whatsapp-status` доступен только administrator/manager?
    Вывод: OK — `routes/channels.php` разрешает канал только administrator/manager.

35. Вопрос: `legal-sessions` защищён Bearer-токеном?
    Вывод: OK — Laravel сверяет Bearer с service token, Node запрашивает этот endpoint с bearer-токеном.

## 3. Входящие и исходящие сообщения, типы, очередь

36. Вопрос: webhook `message_received` ставит `ProcessWhatsappInboundJob` в очередь `whatsapp`?
    Вывод: OK — controller dispatches job, job возвращает `viaQueue(): whatsapp`.

37. Вопрос: входящий текст создаёт/находит чат и контакт, сохраняет сообщение и инкрементит unread?
    Вывод: OK — inbound job вызывает `findOrCreateChat` и `storeInboundMessage`, где есть atomic increment `unread_count`.

38. Вопрос: входящее медиа обрабатывается job + `/inbound-media`, дедуплицируется и broadcast'ится?
    Вывод: OK — `/api/whatsapp/inbound-media` защищён Bearer, возвращает retry при отсутствии сообщения и дедуплицирует media.

39. Вопрос: исходящий текст подписывается, уходит через очередь и получает ack по webhook?
    Вывод: OK — dispatcher добавляет `OperatorSignature`, ставит `SendOutboundMessageJob`, ack обновляется через `onMessageStatus`.

40. Вопрос: ack монотонный и не откатывает галочку назад?
    Вывод: OK — `onMessageStatus` сравнивает ранги ack и игнорирует понижение.

41. Вопрос: quote/ответ передаёт `quoted_message_id` в Laravel и Node?
    Вывод: OK — `quoted_message_id` сохраняется и отправляется в Node как `quotedMessageId`.

42. Вопрос: пересылка одного сообщения работает через `forward` и очередь типа `forward`?
    Вывод: OK — `MessageController::forward` создаёт outbound copy и dispatches payload type `forward`.

43. Вопрос: массовая пересылка проверяет сессию?
    Вывод: OK — `forwardBulk` проверяет одну исходную переписку и право `use` на выбранную WA-сессию.

44. Вопрос: загрузка файла проверяет MIME/лимиты и ставит очередь `media`?
    Вывод: OK — `UploadFileRequest` ограничивает файл и тип, controller dispatches `SendOutboundMessageJob` с payload type `media`.

45. Вопрос: голосовые/аудио не ломают WhatsApp?
    Вывод: OK — webm нормализуется, voice caption убирается, есть transcode webm в ogg fallback.

46. Вопрос: опрос отправляется в WA и отображается?
    Вывод: OK — `sendPoll` создаёт message type `poll`, сохраняет metadata и отправляет через Node `Poll`.

47. Вопрос: контакт vCard отправляется?
    Вывод: OK — `sendContact` строит vCard, сохраняет metadata и отправляет payload type `contact`.

48. Вопрос: реакция на сообщение синхронизируется с WA или откладывается?
    Вывод: OK — есть sync/deferred flow, `SyncMessageReactionToWhatsappJob` и нормализация id `@lid`.

49. Вопрос: реакция клиента приходит webhook'ом и обновляет UI?
    Вывод: OK — Node слушает `message_reaction`, Laravel обновляет реакции и broadcasts `MessageReactionsUpdated`.

50. Вопрос: удаление сообщения разрешено админу для любых исходящих, остальным только для своих исходящих?
    Вывод: PARTIAL — неадмин ограничен своими outbound, но админ сейчас может удалить любое сообщение, включая inbound/system.

51. Вопрос: typing делает broadcast и вызывает Node `set-typing`?
    Вывод: PARTIAL — обе операции есть, но нет guard для чата без `whatsappSession`.

52. Вопрос: mark read сбрасывает unread и вызывает `sendSeen` в Node?
    Вывод: PARTIAL — обе операции есть, но нет guard для чата без `whatsappSession`.

53. Вопрос: маршрут `messages.retry` реализован?
    Вывод: FAIL — маршрут объявлен, но `MessageController::retry` отсутствует.

54. Вопрос: queue worker слушает `whatsapp,default`?
    Вывод: OK — supervisor config использует `--queue=whatsapp,default`.

55. Вопрос: job ретраится при transport error и пробует `initializeSession`?
    Вывод: OK — `SendOutboundMessageJob` распознаёт retryable errors, вызывает `initializeSession` и бросает исключение для retry.

## 4. UI списка чатов и окна переписки

56. Вопрос: список чатов поддерживает пагинацию, поиск, закреплённые сверху и preview?
    Вывод: OK — `ChatSidebar` подгружает страницы, поиск идёт через query, pinned сортируются сверху, preview обновляется.

57. Вопрос: архивная страница и архивирование/разархивирование работают?
    Вывод: OK — есть `chats.archived`, `Archived.vue` и toggle archive.

58. Вопрос: открытие чата загружает сообщения и подписывается на Echo `private chat.{id}`?
    Вывод: OK — `ChatController::show` загружает сообщения, `Show.vue` подписывается на `Echo.private(chat.id)`.

59. Вопрос: бесконечная прокрутка/подгрузка сообщений работает?
    Вывод: OK — есть timeline endpoint и `loadMoreMessages` в `Show.vue`.

60. Вопрос: поиск по сообщениям внутри чата работает?
    Вывод: OK — `displayedMessages` фильтрует локальные сообщения по body.

61. Вопрос: панель контакта показывает данные, участников группы и поиск без кнопок звонка?
    Вывод: PARTIAL — карточка и участники есть, кнопок звонка не найдено; полноценный поиск внутри самой панели не найден, есть переход к поиску в ленте.

62. Вопрос: шапка чата содержит отделы, назначения и меню без звонков?
    Вывод: OK — `ChatHeader` содержит отделы, назначения и меню; кнопок звонков в проверенных местах нет.

63. Вопрос: закреплённое сообщение показывает баннер, переход и открепление?
    Вывод: OK — `Show.vue` содержит pinned banner, jump и unpin.

64. Вопрос: мультивыбор и пересылка из списка сообщений работают?
    Вывод: OK — `selectionMode`, selected ids и `ForwardMessageModal` реализованы.

65. Вопрос: информация о сообщении доступна только для исходящих текущего пользователя?
    Вывод: OK — `ChatMessage.vue` проверяет `sent_by_user_id === currentUserId`, `Show.vue` дополнительно проверяет outbound.

66. Вопрос: статусы сообщений отображаются и delivered/read симулируются где нужно?
    Вывод: PARTIAL — `MessageStatus` и локальная симуляция delivered есть, но в `Show.vue` не найден listener для `.message.ack` от `MessageAckUpdated`.

67. Вопрос: emoji picker и вложения есть в `ChatInput`?
    Вывод: OK — `EmojiPicker`, attachment menu, upload preview, контакты и опросы реализованы.

68. Вопрос: mute и dialog mute работают?
    Вывод: OK — backend `toggleMute` и frontend `MuteChatDialog`/controls присутствуют.

69. Вопрос: избранное и «непрочитано» работают?
    Вывод: OK — `toggleFavorite` и `toggleUnread` есть в backend и UI.

70. Вопрос: очистка чата работает с подтверждением и сбросом preview?
    Вывод: OK — `clear` удаляет сообщения, сбрасывает preview/unread, UI вызывает подтверждение.

## 5. Контакты, старт чата, группы

71. Вопрос: старт чата по номеру редиректит в существующий или новый чат?
    Вывод: OK — `chats.start` находит/создаёт контакт и чат, затем redirect на `chats.show`.

72. Вопрос: создание группы создаёт группу с участниками и связью с WA?
    Вывод: OK — `createGroup` проверяет session `use`, собирает участников и создаёт group chat по WA `chatId`.

73. Вопрос: синхронизация групп работает?
    Вывод: OK — `syncGroups` проходит по connected-сессиям и upsert'ит group chats.

74. Вопрос: участники группы отображаются в `ContactInfoPanel`?
    Вывод: OK — endpoint `groupParticipants` и UI-блок участников реализованы.

75. Вопрос: страницы контактов `chats.contacts` и `contacts.index` есть?
    Вывод: OK — оба маршрута и соответствующие controller methods существуют.

76. Вопрос: upsert контакта дедуплицирует по телефону/WA id?
    Вывод: PARTIAL — `contacts.upsert` дедуплицирует только по `phone_number`, `whatsapp_id`-варианты отдельно не учитываются.

77. Вопрос: сохранение контакта из чата работает?
    Вывод: OK — `saveContact` валидирует имя, выводит телефон и обновляет `contact_id`/`chat_name`.

78. Вопрос: связанные чаты одного контакта на разных номерах показываются в `ContactInfoPanel`?
    Вывод: FAIL — backend отдаёт `contactChats`, `ContactInfoPanel` умеет принять prop, но `Show.vue` не передаёт `:contact-chats`.

79. Вопрос: ресинк имён в группе доступен из админки/команды?
    Вывод: OK — есть `ChatService::resyncGroupSenderNames` и команда `groups:resync-sender-names`.

80. Вопрос: link preview защищён middleware ролей?
    Вывод: OK — `GET /link-preview` находится внутри auth+verified+role группы.

81. Вопрос: медиа отдаётся через `GET /media/{media}` с авторизацией?
    Вывод: OK — `MediaController::show` вызывает `authorize('view', $chat)`.

82. Вопрос: новый чат через `NewChatPanel` поддерживает выбор сессии и контакта?
    Вывод: OK — `NewChatPanel` загружает контакты/сессии и стартует чат/группу.

## 6. Назначения, отделы, роли и доступ

83. Вопрос: администратор видит все чаты и может управлять назначениями/отделами/закреплением?
    Вывод: OK — `ChatPolicy` даёт администратору полный доступ.

84. Вопрос: руководитель видит чаты отдела и назначения подчинённых, `syncDepartments` при `view`?
    Вывод: OK — manager-ветка в `ChatPolicy` покрывает отделы и назначения сотрудников отдела.

85. Вопрос: сотрудник видит свои назначения и пул отдела без назначенных?
    Вывод: OK — employee-логика есть в `ChatPolicy`/`ChatService`, покрыта access tests.

86. Вопрос: назначение одного пользователя работает?
    Вывод: OK — `POST .../assign` создаёт `ChatAssignment`, логирует и broadcasts добавленным.

87. Вопрос: синхронизация назначений работает?
    Вывод: OK — `assign/sync` синхронизирует `user_ids` и логирует системное сообщение.

88. Вопрос: снятие назначения работает безопасно?
    Вывод: FAIL — метод удаляет переданный `ChatAssignment`, но не проверяет, что assignment принадлежит текущему `{chat}`.

89. Вопрос: синхронизация отделов чата работает?
    Вывод: OK — `SyncDepartmentsRequest` авторизует, controller syncs departments и логирует изменение.

90. Вопрос: политики `sendMessage`/`manage`/`delete` согласованы с UI кнопками?
    Вывод: PARTIAL — chat policies согласованы, но удаление сообщения идёт отдельной логикой в `MessageController`, есть расхождение по admin delete.

## 7. Сообщества

91. Вопрос: список сообществ работает?
    Вывод: OK — `communities.index` возвращает активные communities с группами.

92. Вопрос: создание/редактирование/удаление сообществ работает и авторизовано?
    Вывод: PARTIAL — CRUD реализован, но в `CommunityController` нет policy/authorize; доступ ограничен только общей role-группой.

93. Вопрос: просмотр сообщества имеет доступ по роли из контроллера?
    Вывод: PARTIAL — `show` есть, но отдельной проверки в controller нет, только общий route middleware.

94. Вопрос: доступные группы для привязки отдаются корректно?
    Вывод: OK — `availableGroups` фильтрует групповые чаты той же WA-сессии.

95. Вопрос: привязка/отвязка группы работает?
    Вывод: PARTIAL — бизнес-проверки group/session/community есть, но авторизация такая же слабая, как в CRUD communities.

## 8. Реалтайм, API, безопасность, инфраструктура

96. Вопрос: канал `chat.{id}` доступен только через `authorize('view', $chat)` и используется в `Show.vue`?
    Вывод: OK — channel auth вызывает `$user->can('view', $chat)`, `Show.vue` подписывается на `Echo.private(chat.id)`.

97. Вопрос: канал `chats.list.{userId}` доступен только владельцу `userId`?
    Вывод: OK — channel auth проверяет `$user->id === $userId`.

98. Вопрос: WhatsApp webhook защищён HMAC middleware?
    Вывод: OK — `whatsapp.webhook` проверяет `X-Webhook-Signature` через HMAC SHA-256, есть feature-тесты.

99. Вопрос: inbound-media защищён Bearer и возвращает `404 retry:true`, пока сообщение не создано?
    Вывод: OK — controller проверяет bearer token и возвращает `message_not_found` с `retry:true`.

100. Вопрос: Reverb/broadcasting доставляет события без утечек в публичные каналы?
     Вывод: PARTIAL — события идут в private channels, но есть два риска: `Show.vue` не слушает `.message.ack`, а `ChatBroadcastAudience` может отправить list-события шире, чем `ChatPolicy::view`.

