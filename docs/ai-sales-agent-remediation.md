# AI Sales Agent — Remediation Guide

Полное руководство по всем изменениям, фича-флагам, порядку поэтапного раската и откату.

---

## Что изменилось

### Фаза 0 — Фундамент

| Файл | Изменение |
|---|---|
| `app/Support/AiFeatureFlags.php` | Центральный хелпер per-tenant флагов поверх `SystemSetting` |
| `app/Services/Memory/EntityMemoryService::mergeAiFacts()` | Системный AI-merge без `authorizeManage`: обновляет только секцию `## AI-факты (авто)` в `memory.md`, не трогает ручной текст, делает бэкап |
| `config/ai.php` | Расширен: `body_limit_chars`, `history_char_budget`, `memory_extraction_*`, `rolling_summary_*`, `orchestrator_lease_timeout_minutes`, `retry_*` |

### Фаза 1 — Память и контекст

| ID | Описание |
|---|---|
| **C1/CRM1** | `ConversationMemoryExtractor` + `ExtractConversationMemoryJob`: LLM извлекает структурированные факты (бюджет, требования, возражения, договорённости) и пишет в `EntityMemory` контакта через `mergeAiFacts`. Запускается с debounce 30 сек после каждого входящего и после каждого AI-ответа. Флаг: `ai.memory_extraction` |
| **C2** | `PromptBuilder`: когда флаг `ai.history_includes_ai_replies` включён, AI-сгенерированные исходящие включаются в историю с ролью `assistant` (не удаляются). Continuity-блок из 5 сообщений удаляется — история содержит полный разговор |
| **C3** | Когда флаг `ai.history_contact_scoped` включён, история и EntityMemory загружаются по всем чатам контакта (а не только по текущему), с учётом `messages_cleared_at` каждого чата |
| **CL4** | `BODY_LIMIT` параметризован через `config('ai.body_limit_chars')` (env `AI_BODY_LIMIT_CHARS`, default 700). Усечения логируются на уровне `debug` |
| **CL10/CL11** | Голосовые без расшифровки получают информативный плейсхолдер `<голосовое сообщение — расшифровка ещё не готова>` вместо `<сообщение без текста>` |
| **C4** | Когда флаг `ai.rolling_summary` включён: fallback при неудаче LLM-сжатия возвращает последние N сообщений вербатим (не первое+последнее — деструктивный вариант). История сжатий кэшируется по хешу контента |

### Фаза 2 — Надёжность очередей

| ID | Описание |
|---|---|
| **C5** | `AiFunnelOrchestratorService::run`: `RUNNING` старше `ai.orchestrator_lease_timeout_minutes` (default 5 мин) — реклейм (не зависание). `FAILED` — сброс в `PENDING` для одного ретрая |
| **C5** | `RunAiFunnelOrchestratorJob`: `tries=3`, `backoff=[30,90,270]`, `failed()` помечает застрявшие ранны как `failed` |
| **C5** | `GenerateAiReplyJob`: аналогично `tries=3`, `backoff=[30,90,270]`, `failed()` помечает `AiResponseLog` как `failed` |
| **C5** | `OpenAiChatService`: ретрай на `429,500,502,503,504` и `ConnectionException` (configurable через `ai.retry_on_http_statuses` / `ai.retry_base_backoff_ms`) |
| **C6** | `RunAiFunnelOrchestratorJob` + `GenerateAiReplyJob`: `WithoutOverlapping` per-chat предотвращает двойную генерацию |
| **C6** | `GenerateAiReplyJob`: post-LLM проверка latest-inbound — если пришло новое сообщение пока LLM генерировал, ответ отбрасывается (статус `cancelled`) |

### Фаза 2c — Статусы экшенов

| ID | Описание |
|---|---|
| **T1/T2** | `AiFunnelActionExecutor::runAction`: результат `['skipped'=>true]` теперь сохраняется как `STATUS_SKIPPED` (не `STATUS_DONE`). Причина (`reason`) попадает в `error` поле для аудита |
| **T3** | Сбой одного экшена изолирован: `catch(Throwable)` логирует warning, возвращает `['failed'=>true]`, остальные экшены плана выполняются |

### Фаза 3 — LLM конфиг

| ID | Описание |
|---|---|
| **L1/L2** | `OpenAiModelResolver::chatModel($companyId, $scenario)`: per-task модели через `services.openai.models.{scenario}` (env `OPENAI_MODEL_AI_REPLY`, `OPENAI_MODEL_FUNNEL_ORCHESTRATOR` и т.д.) |
| **L3** | Лимит токенов AI-ответа: `services.openai.max_reply_tokens` (env `OPENAI_MAX_REPLY_TOKENS`, default 700) |
| **L7** | `ChatDepartmentClassifierService`: включает последние 5 сообщений чата в промпт маршрутизатора — повышает точность выбора отдела |

### Фаза 4a — Гварды воронки

| ID | Описание |
|---|---|
| **F1/F4** | `FunnelStageTransitionGuard` (флаг `ai.funnel_sequence_guard`): блокирует прыжок вперёд на более чем `funnel.ai.max_skip_stages` (default 2) этапов при `confidence < funnel.ai.skip_stages_min_confidence` (default 0.90) |
| **F2** | `ChatFunnelStateService::applyFromAi`: применяет `FunnelStageWipGuard` перед AI-переходом (как в `applyManual`) |
| **F5** | `InboundAiDispatchService` (флаг `ai.funnel_sequence_guard`): когда оркестратор включён, параллельно запускает `AnalyzeChatFunnelJob` как «второе мнение» для диагностики расхождений |

### Фаза 4b — CRM write-back

| ID | Описание |
|---|---|
| **CRM2** | Таблица `contact_tags` + модель `ContactTag` (source: manual/ai/import) |
| **CRM3** | `AiCrmWritebackService::writeContactEnrichment()` (флаг `ai.crm_writeback`): уписывает AI-теги (бюджет, требования, источник) и обновляет `contacts.ai_enriched_at` |
| **CRM4** | Поле `contacts.ai_enriched_at` — видимость в UI (когда AI последний раз обогатил) |
| **CRM5** | `AiCrmWritebackService::syncContactFunnelStage()` (флаг `ai.crm_writeback`): при смене этапа через AI обновляет `contacts.ai_funnel_stage_id` — контакт-уровневая синхронизация |

---

## Все фича-флаги

| Ключ (`SystemSetting.key`) | Что включает | Default |
|---|---|---|
| `ai.memory_extraction` | Автоматическое извлечение фактов в EntityMemory после каждого хода | OFF |
| `ai.history_includes_ai_replies` | AI-ответы включаются в историю с ролью `assistant`; continuity-блок удаляется | OFF |
| `ai.history_contact_scoped` | История загружается по всем чатам контакта | OFF |
| `ai.rolling_summary` | Безопасный fallback при сжатии: last N messages вместо first+last | OFF |
| `ai.funnel_sequence_guard` | Гвард прыжков через этапы + WIP + второе мнение классификатора | OFF |
| `ai.crm_writeback` | Теги, `ai_enriched_at`, `ai_funnel_stage_id` на контакте | OFF |

---

## Поэтапный раскат

### Шаг 1 — Demo/Staging (проверить без рисков)

```php
// В php artisan tinker или seeders:
use App\Models\Company;
use App\Support\AiFeatureFlags;

$demoCompany = Company::where('slug', 'demo')->firstOrFail();

AiFeatureFlags::enable(AiFeatureFlags::MEMORY_EXTRACTION, $demoCompany->id);
AiFeatureFlags::enable(AiFeatureFlags::HISTORY_INCLUDES_AI_REPLIES, $demoCompany->id);
```

Отправьте несколько тестовых сообщений. Проверьте:
- Что в `entity_memories` появляется секция `## AI-факты (авто)`
- Что в истории видны и входящие, и AI-ответы
- Что логи не показывают ошибок (`[memory-extractor]`, `[prompt-builder]`)

### Шаг 2 — Пилот (1–3 реальных компании)

```php
$pilotIds = [123, 456, 789]; // ID ваших пилотных компаний
foreach ($pilotIds as $id) {
    AiFeatureFlags::enable(AiFeatureFlags::MEMORY_EXTRACTION, $id);
    AiFeatureFlags::enable(AiFeatureFlags::HISTORY_INCLUDES_AI_REPLIES, $id);
    AiFeatureFlags::enable(AiFeatureFlags::ROLLING_SUMMARY, $id);
}
```

Мониторьте в течение 3–5 дней:
- Качество ответов (обратная связь от менеджеров)
- Использование токенов в `ai_usage_logs`
- Ошибки в `failed_jobs`

### Шаг 3 — Все компании

```php
Company::whereNotNull('id')->chunk(100, function ($companies) {
    foreach ($companies as $company) {
        AiFeatureFlags::enable(AiFeatureFlags::MEMORY_EXTRACTION, $company->id);
        AiFeatureFlags::enable(AiFeatureFlags::HISTORY_INCLUDES_AI_REPLIES, $company->id);
        AiFeatureFlags::enable(AiFeatureFlags::ROLLING_SUMMARY, $company->id);
    }
});
```

### Шаг 4 — Дополнительные флаги (после стабилизации)

```php
// После 1–2 недель без инцидентов:
foreach ($allCompanyIds as $id) {
    AiFeatureFlags::enable(AiFeatureFlags::HISTORY_CONTACT_SCOPED, $id);
    AiFeatureFlags::enable(AiFeatureFlags::FUNNEL_SEQUENCE_GUARD, $id);
    AiFeatureFlags::enable(AiFeatureFlags::CRM_WRITEBACK, $id);
}
```

---

## Запуск миграции

```bash
php artisan migrate
```

Создаёт:
- Таблицу `contact_tags`
- Колонки `contacts.ai_funnel_stage_id` и `contacts.ai_enriched_at`

---

## Как проверить память клиента

### В tinker:
```php
use App\Enums\EntityMemorySubjectType;
use App\Services\Memory\EntityMemoryService;

$memory = app(EntityMemoryService::class)->get(
    EntityMemorySubjectType::Contact,
    $contactId
);

echo $memory->content;
```

Ищите секцию `## AI-факты (авто)` в конце файла. Она обновляется автоматически.

### Принудительный запуск extraction для чата:
```php
use App\Jobs\ExtractConversationMemoryJob;

ExtractConversationMemoryJob::dispatch($chatId, $companyId);
```

### Просмотр тегов контакта:
```php
$contact = Contact::with('tags')->find($contactId);
$contact->tags->where('source', 'ai')->pluck('name');
```

---

## Откат

Все поведенческие изменения управляются флагами. Быстрый откат:

```php
use App\Support\AiFeatureFlags;

// Откат одного флага для компании:
AiFeatureFlags::disable(AiFeatureFlags::MEMORY_EXTRACTION, $companyId);

// Снимок состояния всех флагов:
$snapshot = AiFeatureFlags::snapshot($companyId);
```

Поскольку флаги кэшируются в `SystemSetting` с TTL (см. `SystemSetting::CACHE_TTL`), при необходимости сбросьте кэш:
```bash
php artisan cache:clear
```

---

## Конфигурация через .env

| Переменная | Config key | Default | Описание |
|---|---|---|---|
| `AI_BODY_LIMIT_CHARS` | `ai.body_limit_chars` | `700` | Лимит символов на сообщение в истории |
| `AI_HISTORY_CHAR_BUDGET` | `ai.history_char_budget` | `24000` | Бюджет символов на полную историю |
| `AI_MEMORY_EXTRACTION_DEBOUNCE_SECONDS` | `ai.memory_extraction_debounce_seconds` | `30` | Задержка перед extraction (debounce) |
| `AI_MEMORY_EXTRACTION_MAX_TOKENS` | `ai.memory_extraction_max_tokens` | `800` | Макс токенов для extraction LLM |
| `AI_ORCHESTRATOR_LEASE_TIMEOUT_MINUTES` | `ai.orchestrator_lease_timeout_minutes` | `5` | Таймаут lease оркестратора |
| `AI_RETRY_HTTP_STATUSES` | `ai.retry_on_http_statuses` | `429,500,502,503,504` | HTTP-коды для ретрая OpenAI |
| `OPENAI_MAX_REPLY_TOKENS` | `services.openai.max_reply_tokens` | `700` | Макс токенов AI-ответа |
| `OPENAI_MODEL_AI_REPLY` | `services.openai.models.ai_reply` | — | Модель для основных ответов |
| `OPENAI_MODEL_FUNNEL_ORCHESTRATOR` | `services.openai.models.funnel_orchestrator` | — | Модель для оркестратора |
| `OPENAI_MODEL_MEMORY_EXTRACTION` | `services.openai.models.memory_extraction` | — | Модель для extraction |
| `AI_ROLLING_SUMMARY_FALLBACK_KEEP_MESSAGES` | `ai.rolling_summary_fallback_keep_messages` | `15` | Количество сообщений в fallback |

---

## Мониторинг

Ключевые лог-теги для мониторинга:
- `[memory-extractor]` — extraction фактов
- `[prompt-builder]` — усечения (`debug` уровень)
- `[ai-orchestrator]` — оркестратор (включая `reclaiming expired lease`)
- `[ai-reply]` — генерация ответов (включая `stale reply discarded`)
- `[ai-crm-writeback]` — CRM enrichment
- `[funnel-ai]` — AI-переходы воронки (включая WIP-отказы)

Failed jobs по классам:
- `RunAiFunnelOrchestratorJob` — оркестратор упал 3 раза
- `GenerateAiReplyJob` — генерация упала 3 раза
- `ExtractConversationMemoryJob` — extraction упала 2 раза
