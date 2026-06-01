## Распознавание речи (ГС) в ChatAgents — архитектура и перенос в другой проект

Этот документ описывает **как устроена расшифровка голосовых сообщений (ГС)** в текущем проекте и как **перенести этот механизм** в другой код/стек, не ломая UX и бизнес-логику.

Документ намеренно “практический”: какие сущности участвуют, какие джобы запускаются, какие флаги конфигурации нужны, где хранится файл, где хранится текст и как он попадает в обработчики команд.

---

## 1) Цель и принципы дизайна

### Цель
- Принять входящее голосовое сообщение в WhatsApp.
- **Сохранить медиа** в локальное хранилище.
- Отправить файл в **OpenAI Whisper** (`/v1/audio/transcriptions`) и получить текст.
- Сохранить транскрипт в БД и (опционально) в виде `.txt` в хранилище.
- Если включены “voice commands” — прогнать расшифровку через **существующий пайплайн команд/интентов** (тот же, что для текста).

### Ключевые принципы
- **Ничего не переписывать “в ядре”**: транскрипт подаётся в уже существующие обработчики команд/автоответов.
- **Отделить хранение медиа** от распознавания: сначала гарантируем наличие файла, потом транскрибируем.
- **Быть устойчивым к задержкам**: повторные попытки/`backoff`, `delay`, повторный запуск, если файл ещё не скачался.
- **Не мешать пересылке медиа**: если подпись “в группу …” — это не “голосовая команда”, это команда переслать медиа.

---

## 2) High-level flow (коротко)

1) WhatsApp webhook → `ProcessWhatsappInboundJob`
2) Создаётся/обновляется чат и создаётся `Message` (+ `MessageMedia`)
3) Если это голосовое и оно считается “voice command”:
   - если на сессии можно транскрибировать → `TranscribeAudioJob(messageId)`
4) `TranscribeAudioJob`:
   - убеждается, что медиа сохранено в storage
   - отправляет файл в OpenAI Whisper (`whisper-1`)
   - сохраняет текст транскрипта в `message_transcripts`
   - запускает `ProcessTranscribedVoiceCommandJob`
5) `ProcessTranscribedVoiceCommandJob`:
   - берёт текст
   - подсовывает его в **существующие** обработчики команд (`AgentCommandService::handleFromVoiceTranscript`)
   - если команда не распознана — может отправить в “free-form auto reply”

---

## 3) Основные сущности и где они в коде

### 3.1 Message / MessageMedia
Входящее сообщение в WhatsApp сохраняется как запись `Message`. Медиа (включая ГС) — в `MessageMedia`.

Точка сохранения:
- `app/Jobs/ProcessWhatsappInboundJob.php`
  - `ChatService::storeInboundMessage(...)` сохраняет `Message`
  - медиа привязывается к сообщению и сохраняется отдельной сущностью

Хранение файла:
- `app/Services/InboundMessageMediaService.php` (используется для “ensureStored”)
  - отвечает за то, чтобы медиа реально лежало на диске (локальный storage).

### 3.2 MessageTranscript
Текст транскрипта сохраняется в отдельной таблице/модели:
- `app/Models/MessageTranscript.php`

Используемые поля (минимально важные для переноса):
- `message_id`: ссылка на `messages.id`
- `kind`: `audio` для ГС
- `text`: расшифровка
- `model`: `'whisper-1'`
- `text_disk_path`: где лежит текстовая копия (опционально)
- `source_mime`, `source_filename`: метаданные исходного файла

Сохранение транскрипта:
- `app/Services/MessageTranscriptStorageService.php`
  - `storeAudioText(...)` создаёт запись и пишет текстовый файл в `Storage::disk('local')`

### 3.3 “Как текст попадает дальше”
Когда у сообщения есть `transcript`, платформа может использовать его в разных местах:
- `app/Support/MessageInboundText.php`
  - `forMessage($message)` возвращает “эффективный текст” (подпись + transcript) для LLM/аналитики.

Но для “voice commands” важнее отдельный путь через `ProcessTranscribedVoiceCommandJob`, который **подставляет transcript в обычный pipeline команд**.

---

## 4) Решение: это voice command или пересылка медиа?

Ключевой фильтр:
- `app/Support/VoiceInboundHelper.php`

### 4.1 Типы голосовых
Голосовыми считаются типы:
- `ptt`, `audio`, `voice`

Метод:
- `VoiceInboundHelper::isVoiceType($type)`

### 4.2 Исключение: “голосовое с подписью ‘в группу …’”
Если админ отправил ГС с подписью, которая выглядит как “куда переслать” — это трактуется как **пересылка медиа**, а не голосовая команда.

Методы:
- `VoiceInboundHelper::effectiveCaption($message)`
- `VoiceInboundHelper::isGroupForwardCaption($message)`
  - использует `AgentDmMediaToGroupParser::captionLooksLikeGroupOnly($caption)`
- `VoiceInboundHelper::shouldProcessAsVoiceCommand($message)`
  - возвращает `true`, только если это voice-type и **не** `isGroupForwardCaption`

Практически: это защищает UX от “я продиктовал ‘в группу X’ и бот начал распознавать и выполнять”.

---

## 5) Включение/выключение транскрипции на уровне сессии

Решение “можно ли транскрибировать” зависит от:
- есть ли OpenAI key на сессии
- флагов `transcribe_audio` (на уровне `WhatsappSession`) и `AGENT_TRANSCRIBE_AUDIO` (глобально)

Метод:
- `VoiceInboundHelper::canTranscribeOnSession(WhatsappSession $session)`

Также есть “поднятие флага” на сессии:
- `VoiceInboundHelper::ensureSessionTranscribeFlag($session)`
  - если можно транскрибировать, но `transcribe_audio` на сессии ещё false — он выставляется в true
  - полезно для UI/настроек, чтобы было видно, что для этого номера включено

Конфиг:
- `config/agent.php`
  - `voice_commands` (`AGENT_VOICE_COMMANDS`)
  - `transcribe_audio` (`AGENT_TRANSCRIBE_AUDIO`)

---

## 6) Запуск транскрипции (где именно)

Точка входа — обработчик входящих сообщений:
- `app/Jobs/ProcessWhatsappInboundJob.php`

Ключевой кусок логики:
- вычисляется `$voiceCommand = VoiceInboundHelper::shouldProcessAsVoiceCommand($message)`
- если `$voiceCommand === true` и `VoiceInboundHelper::canTranscribeOnSession($session)`:
  1) `InboundMessageMediaService::ensureStored($message, $session)`
  2) если файл уже есть — `delay=0`, иначе `delay=3` секунды
  3) `TranscribeAudioJob::dispatch($message->id)->delay(...)`

Если транскрипция невозможна (например, нет OpenAI key) — отправляется подсказка администратору (для 1:1 чата).

---

## 7) TranscribeAudioJob: устойчивое распознавание

Файл:
- `app/Jobs/TranscribeAudioJob.php`

### 7.1 Идемпотентность
Если у `Message` уже есть `transcript`:
- job не транскрибирует повторно
- если включены voice commands → всё равно отправит `ProcessTranscribedVoiceCommandJob`

### 7.2 Ретраи/бэкофф
- `$tries = 5`
- `backoff = [5, 10, 20, 40, 60]`

Если медиа не успело сохраниться:
- job “release” и пробует позже.

### 7.3 Вызов Whisper
Реальный HTTP вызов:
- `OpenAiTextCompletionService::transcribeAudio($apiKey, $filePath, $filename)`
  - POST `https://api.openai.com/v1/audio/transcriptions`
  - `model: whisper-1`
  - file attach (байты файла)

### 7.4 Сохранение результата
После успеха:
- `MessageTranscriptStorageService::storeAudioText($message, $media, $text)`
  - создаёт запись `message_transcripts(kind=audio)`
  - пишет текстовую копию в `storage` (локальный диск)

И затем:
- `ProcessTranscribedVoiceCommandJob::dispatch($message->id)` (если `agent.voice_commands=true`)

---

## 8) ProcessTranscribedVoiceCommandJob: запуск существующего pipeline команд

Файл:
- `app/Jobs/ProcessTranscribedVoiceCommandJob.php`

### 8.1 Синхронизация body
Если у `Message.body` пусто или это “placeholder превью медиа”:
- `syncMessageBodyFromTranscript(...)` записывает расшифровку в `Message.body` (обрезка до 2000 символов)

Это важно:
- UI/история видит текст
- дальнейшие механики (например, контекст) будут консистентны

### 8.2 Приведение к формату webhook-подобных данных
Job строит упрощённый `webhookData`, где:
- `type = 'chat'`
- `body = transcriptText`
- `from`, `senderPhone`, `chatId`, `messageId`, `isGroup`

Это сделано, чтобы переиспользовать существующие методы, ожидающие структуру webhook.

### 8.3 Запуск обработчиков
1) (Опционально) обновление журнала задач по группе: `AgentTaskJournalService::tryRecordGroupInbound(...)`
2) Выполнение команд:
   - `AgentCommandService::handleFromVoiceTranscript($session, $chat, $message, $webhookData, $text)`
3) Если команда выполнена — лог в `WhatsappAuditLogger`
4) Если команда не выполнена:
   - проверка “это админ?” (`WaContactBinding::isClientAdminForSender`)
   - и затем `InboundAutoReplyService::maybeDispatchAutoReplyFromTranscript(...)`

То есть: **голосовое становится обычным текстом** и проходит по тем же рельсам.

---

## 9) Где настраивать/что перенести в другой проект (чек-лист)

Если вы переносите этот механизм, вам нужен минимум:

### 9.1 Хранение данных
- Таблица `messages` (или эквивалент) для входящих сообщений
- Таблица `message_media` для привязки медиа к сообщению
- Таблица `message_transcripts` для текстов транскриптов (audio/document)

### 9.2 Очереди
Нужен queue worker, минимум две джобы:
- `ProcessWhatsappInboundJob` (или ваш inbound handler)
- `TranscribeAudioJob`
- `ProcessTranscribedVoiceCommandJob`

Плюс ретраи/backoff.

### 9.3 Media storage
Слой “ensureStored”:
- обеспечить, что файл реально скачан/лежит на диске до транскрипции.

### 9.4 OpenAI интеграция
- endpoint: `POST /v1/audio/transcriptions`
- модель: `whisper-1`
- токен: per-session/per-tenant API key или общий
- таймауты 180s

### 9.5 Gate: не все ГС — команды
Обязательно перенесите правило:
- “голосовое с подписью ‘в группу …’” → это **пересылка**, не транскрипция-команда

### 9.6 Встраивание в pipeline команд
Чтобы “не переписывать логику”:
- после транскрипции вызывайте **ваш существующий** обработчик команд так, как будто это обычный текст.

---

## 10) Рекомендации по улучшению при переносе (не меняя логику)

1) **Идемпотентность по message_id**: если transcript уже есть — не дергать Whisper повторно.
2) **Debounce на повторные webhooks**: входящие WA события иногда дублируются.
3) **Лимиты**: обрезка текста транскрипта для `Message.body` (как здесь 2000), но хранить полный transcript отдельно.
4) **Логи и аудит**: писать события “transcribe started/failed/succeeded”.
5) **Конфиг флаги**:
   - global enable (`TRANSCRIBE_AUDIO=true`)
   - per-tenant override (`session.transcribe_audio`)
   - “voice commands” отдельно от “просто расшифровывать”

---

## 11) Где смотреть в коде (быстрые ссылки)

- Inbound webhook → маршрутизация в job: `app/Jobs/ProcessWhatsappInboundJob.php`
- Решение “команда vs пересылка”: `app/Support/VoiceInboundHelper.php`
- Транскрипция Whisper: `app/Jobs/TranscribeAudioJob.php`, `app/Services/OpenAiTextCompletionService.php::transcribeAudio`
- Сохранение транскрипта: `app/Services/MessageTranscriptStorageService.php`, `app/Models/MessageTranscript.php`
- Прогон через command pipeline: `app/Jobs/ProcessTranscribedVoiceCommandJob.php`, `app/Services/AgentCommandService.php::handleFromVoiceTranscript`
- “Эффективный текст” для LLM: `app/Support/MessageInboundText.php`
- Флаги: `config/agent.php` (`voice_commands`, `transcribe_audio`)

---

## 12) Минимальный “порт” в другой проект (псевдо-шаги интеграции)

1) При получении WA webhook:
   - сохранить сообщение + медиа (disk_path)
2) Если media.type в `{ptt,audio,voice}` и подпись не “в группу …”:
   - поставить в очередь `TranscribeAudioJob(messageId)`
3) В `TranscribeAudioJob`:
   - ensureStored(file)
   - call OpenAI Whisper
   - save transcript
   - enqueue `ProcessTranscribedVoiceCommandJob(messageId)`
4) В `ProcessTranscribedVoiceCommandJob`:
   - взять transcript.text
   - вызвать ваш existing command handler, как будто пользователь написал этот текст

Если хотите, я могу подготовить “минимальный модуль” (классы + миграции) под Laravel или под ваш целевой стек (Node/Nest/Python) — просто скажите, куда переносите.

