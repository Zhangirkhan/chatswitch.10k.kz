# Улучшения расшифровки голосовых (roadmap)

Документ дополняет [VOICE_TRANSCRIPTION_ARCHITECTURE.md](VOICE_TRANSCRIPTION_ARCHITECTURE.md) и описывает запланированные и внедряемые оптимизации после базовой интеграции Whisper + AI.

---

## Высокий приоритет

| # | Улучшение | Зачем | Статус |
|---|-----------|--------|--------|
| H1 | Очередь `transcription` (отдельно от `whatsapp`) | Тяжёлые job Whisper (до 180s) не блокируют inbound WhatsApp | Готово |
| H2 | `ShouldBeUnique` на `TranscribeAudioJob` по `message_id` | Нет дублей при повторном webhook / duplicate media | Готово |
| H3 | Статус расшифровки (`pending` → `processing` → `completed` / `failed` / `skipped`) | UI и поддержка: видно «идёт», «готово», «ошибка» | Готово |
| H4 | Повторная маршрутизация отдела после успешной транскрипции | Первый webhook видел пустой `body`; после текста — корректный отдел | Готово |
| H5 | Политика ГС с подписью: AI ждёт transcript, текст для LLM — из расшифровки | Подпись ≠ содержание голосового; не отвечать только по caption | Готово |

---

## Средний приоритет

| # | Улучшение | Зачем | Статус |
|---|-----------|--------|--------|
| M1 | Раздельные флаги `ACCEL_TRANSCRIBE_AUDIO` и `ACCEL_AI_VOICE_REPLIES` | Расшифровка для оператора без автоответа AI | Готово |
| M2 | Per-company OpenAI / лимиты | SaaS: отключение и учёт по тенанту | Запланировано |
| M3 | Порог длительности (min/max секунд из `metadata.media.duration`) | Экономия API на пустых/слишком длинных файлах | Готово |
| M4 | Структурированный аудит (`transcribe_started` / `succeeded` / `failed`, ms, bytes) | Мониторинг и алерты | Готово |
| M5 | История в классификаторах без жёсткого `whereNotNull('body')` | ГС в контексте funnel/appointment до sync body | Готово |

---

## Казахский язык (Whisper)

| Настройка | Назначение |
|-----------|------------|
| `ACCEL_WHISPER_DEFAULT_LANGUAGE=auto` | Не отправляет `language`, если язык не очевиден; Whisper сам определяет ru/kk/mixed |
| `OPENAI_WHISPER_LANGUAGE=ru|kk` | Жёсткая фиксация языка только для диагностики/экспериментов |
| `WhisperTranscriptionOptionsResolver` | По истории чата подставляет `ru` для явно русских и `kk` для явно казахских диалогов; при смешанном/неясном языке оставляет auto |
| `ACCEL_WHISPER_PROMPT_AUTO` | Нейтральная подсказка без перекоса: русский, қазақ тілі или mixed, сохранять язык говорящего |
| `ResponseStyleMatcher` | AI отвечает на языке последнего сообщения: русский и казахский равноправны |

---

## Низкий приоритет (не в текущем спринте)

- Локальный ffmpeg / VAD перед Whisper  
- Альтернативные провайдеры (Azure, self-hosted faster-whisper)  
- Кеш транскрипта по hash файла  
- Per-company API keys (M2)

---

## Связанные файлы (реализация)

- `app/Jobs/TranscribeAudioJob.php` — очередь, unique, статусы, метрики, re-route  
- `app/Support/VoiceInboundHelper.php` — политика AI / transcript  
- `app/Support/MessageInboundText.php` — приоритет transcript для inbound voice  
- `app/Services/MessageTranscriptService.php` — статусы  
- `config/accel.php`, `config/horizon.php`  
- `resources/js/Pages/Chats/Partials/ChatMessage.vue` — бейдж статуса  
