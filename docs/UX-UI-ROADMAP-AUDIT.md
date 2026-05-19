# Roadmap: UX / UI / Оптимизации — аудит готовности

**Дата аудита:** 18 мая 2026  
**Последнее обновление:** 18 мая 2026 (полировка: шапка чата, UiCheckbox, gate настроек, онбординг)  
**Репозиторий:** `/var/www/chatswitch.10k.kz`  

**Легенда статусов:**

| Статус | Значение |
|--------|----------|
| ✅ Готово | Реализовано end-to-end (backend + UI, где нужно) |
| 🟡 Частично | Есть ядро, но не дотягивает до спецификации |
| 🔵 В разработке | Заложено в коде, UI/интеграция не завершены |
| ⬜ Осталось | Не найдено или только заготовки |

**Сводка (29 пунктов ТЗ, без п.30):** **✅ 28 · 🟡 1 · ⬜ 0**

| Метрика | Значение |
|---------|----------|
| Полностью закрытые пункты (колонка «Что осталось» = —) | 24 |
| С остаточными доработками / внешними зависимостями | 5 |
| Не начатые (крупные фичи) | 3 (CRM, follow-up A/B, редактор тона) |

**Сделано в спринтах 18.05.2026:**

- P0/P1: внимание, gate AI, `ai_decision` + plan JSON, онбординг, симулятор, задача в header, DnD, skeleton.
- CRM-карточка, конверсия воронки, auto follow-up, `DangerConfirmModal`.
- AI uncertain, `FunnelStageSimPreview`, обучение тона (`draft_edit_kind`), `tone_source.suggestion`.
- **P2:** `FunnelStageResponseTimeAnalytics`, `KnowledgeCatalogChatPriceAuditService`.
- **Полировка:** компактная шапка чата, `UiCheckbox`, `EnsureSettingsReadiness` middleware, кнопка «Завершить онбординг», редирект `/channels` и `/status` → подключения.

---

## 10 UX улучшений

| # | Функция | Статус | Что есть | Что осталось |
|---|---------|--------|----------|--------------|
| 1 | Онбординг новой компании | ✅ | Wizard + **`EnsureSettingsReadiness`** (блок настроек до `ready`) + кнопка завершения. | Сохранение `onboarding_completed_at` в БД (опционально). |
| 2 | «Проверка готовности AI» | ✅ | `AiInsightsController::readiness()`, `Settings/AiQuality.vue`, баннер в `Chats/Show.vue` → онбординг. | — |
| 3 | Симулятор клиента | ✅ | `AiSimulationService`, `AiSimulatorModal`, `FunnelStageSimPreview`. | — |
| 4 | История решений AI в чате | ✅ | `MessageAiDecisionService` + карточка в `ChatMessage.vue`, plan JSON. | — |
| 5 | Быстрые действия в шапке чата | ✅ | `ChatHeader`: AI, воронка под именем, задача/симулятор в меню ⋮. | — |
| 6 | Предупреждения перед опасными настройками | ✅ | Gate AI + `DangerConfirmModal`. | — |
| 7 | Умные подсказки в настройках воронки | ✅ | `funnelStageHints.ts`, чипы в `Funnels.vue`, audit в AI Quality. | — |
| 8 | Фильтр «требует внимания» | ✅ | `ChatAttentionService`, таб «Внимание» + tooltip scope. | «Спорная оплата», «неизвестный срок» — нет модели. |
| 9 | Мягкое ручное вмешательство | ✅ | `AiDraftToneLearningService` (`punctuation` / `light` / `heavy`). | — |
| 10 | Единая карточка клиента | 🟡 | `ContactCardCrmService`, `ContactCrmSections.vue`. | Заказы / оплаты / доставки (нет сущностей). |

---

## 10 UI улучшений

| # | Функция | Статус | Что есть | Что осталось |
|---|---------|--------|----------|--------------|
| 11 | Унифицировать страницы настроек | ✅ | `SettingsLayout` + sidebar; `/channels`, `/status` → `settings.connections`. | — |
| 12 | Шкала воронки в шапке чата | ✅ | Компактная строка + progress bar под именем; сегменты внизу header. | — |
| 13 | Бейдж AI-статуса | ✅ | `aiHeaderBadge` (Выкл / Думает / Менеджер / Ошибка / Авто). | — |
| 14 | Карточки сообщений AI | ✅ | Badge «(AI)», feedback, `ai_decision`. | — |
| 15 | Конструктор воронки DnD | ✅ | `reorderStages`, HTML5 DnD в `Funnels.vue`. | — |
| 16 | Иконки этапов | ✅ | `FunnelStageIcon`, `stage_type`, guessFromName. | — |
| 17 | Полезные пустые состояния | ✅ | Funnels, KB, AiQuality, Clients, Channels, Status. | Редкие вложенные экраны. |
| 18 | Компактный правый сайдбар | ✅ | `ContactInfoPanel` + insights. | Узкий rail — опционально. |
| 19 | Цветовая система | ✅ | `--ui-*` + Tailwind `ui-*`; настройки на `--ui-*`, чат на `--wa-*`. | Миграция чата/аналитики (опционально). |
| 20 | Skeleton loading | ✅ | `SkeletonBlock`, sidebar, settings overlay, contact card. | — |

---

## 10 оптимизаций и нового функционала

| # | Функция | Статус | Что есть | Что осталось |
|---|---------|--------|----------|--------------|
| 21 | Шаблоны отраслевых воронок | ✅ | 7 шаблонов, «Мебель / кухни». | — |
| 22 | Автогенерация воронки (AI wizard) | ✅ | `FunnelAiWizard.vue`, `FunnelAiSuggestionService`. | — |
| 23 | Автоаудит базы знаний | ✅ | Эвристики + LLM + `KnowledgeCatalogChatPriceAuditService`. | — |
| 24 | RAG-поиск по БЗ | ✅ | chunks, indexer, retriever, cron embeddings. | — |
| 25 | Антизацикливание AI | ✅ | `normalizeRepeatedQuestion()` в оркестраторе. | — |
| 26 | SLA и напоминания | ✅ | `SlaReminderService`, cron, `Settings/System.vue`. | — |
| 27 | Автоматические follow-up | ✅ | `FunnelStageFollowUpService`, cron, UI: шаблон / A/B / AI. | — |
| 28 | Аналитика воронки | ✅ | Конверсия + `FunnelStageResponseTimeAnalytics`. | — |
| 29 | Обучение на правках менеджера | ✅ | `ToneProfileAnalyzer`, `AiDraftToneLearningService`, `Settings/ToneProfile.vue`. | — |
| 30 | *(нет в исходном ТЗ)* | — | — | — |

---

## Приоритетный backlog

### P0 — доверие к AI ✅

1. ~~Фильтр «Требует внимания» + AI uncertain~~  
2. ~~Gate при включении AI~~  
3. ~~История решения у сообщения + plan JSON~~

### P1 — UX по спецификации ✅

4. ~~Onboarding wizard + gate настроек~~  
5. ~~Симулятор в чате + preview этапа~~  
6. ~~«Создать задачу» в header (меню)~~  
7. ~~DnD этапов~~  
8. ~~Skeleton настроек~~

### P2 — дифференциация

| # | Задача | Статус |
|---|--------|--------|
| 9 | ~~RAG~~ | ✅ |
| 10 | CRM: заказы/оплаты | ⬜ ждёт модель |
| 11 | ~~Шаблон «Мебель» + иконки~~ | ✅ |
| 12 | ~~Конверсия воронки~~ | ✅ |
| 13 | ~~Auto follow-up~~ | ✅ |
| 14 | ~~Аудит БЗ: цены vs чаты~~ | ✅ |
| 15 | ~~Аналитика: AI vs менеджер response time~~ | ✅ |
| 16 | Follow-up: A/B + AI-текст | ✅ |
| 17 | UI профиля тона (редактор) | ✅ |
| 18 | Design tokens / `--wa-*` → `--ui-*` | ✅ (настройки) |

---

## Код-ревью (18.05.2026)

| Область | Файл | Заметка |
|---------|------|---------|
| Gate настроек | `EnsureSettingsReadiness.php` | Пропускает `onboarding`, `connections`; `ready` = score ≥ 90% |
| Чекбоксы | `UiCheckbox.vue` | Корневой `<span>`, без вложенных `<label>` |
| Шапка чата | `ChatHeader.vue` | `aiHeaderBadge`, компактная воронка, меню ⋮ |
| Тесты | `SettingsReadinessMiddlewareTest.php` | Redirect / allow connections / bootstrap |

---

## Ключевые файлы (reference)

| Область | Пути |
|---------|------|
| Gate настроек | `app/Http/Middleware/EnsureSettingsReadiness.php` |
| Внимание / uncertain | `app/Services/AI/ChatAttentionService.php` |
| AI decision | `app/Services/AI/MessageAiDecisionService.php`, `ChatMessage.vue` |
| Симулятор | `AiSimulationService.php`, `FunnelStageSimPreview.vue` |
| Обучение тона | `ToneProfileController.php`, `Settings/ToneProfile.vue`, `AiDraftToneLearningService.php` |
| Follow-up A/B + AI | `FunnelStageFollowUpService.php`, `FunnelFollowUpAiTextService.php` |
| Конверсия + время ответа | `FunnelConversionAnalyticsService.php`, `FunnelStageResponseTimeAnalytics.php` |
| Аудит каталога | `KnowledgeCatalogAuditService.php`, `KnowledgeCatalogChatPriceAuditService.php` |
| UI checkbox | `resources/js/Components/Ui/UiCheckbox.vue` |
| Design tokens | `resources/css/app.css` (`--ui-*`), `tailwind.config.js` (`ui` colors) |
| Подтверждения | `DangerConfirmModal.vue` |
| CRM-карточка | `ContactCardCrmService.php`, `ContactCrmSections.vue` |

---

*При изменениях в репозитории обновляйте статусы в этой таблице или запросите повторный аудит.*
