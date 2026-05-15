# Воронки продаж

Модуль для управления этапами продаж. Воронки создаются администратором, привязываются к отделам, а аналитика показывает, на каком этапе находится каждый отдел.

---

## Концепция

```
Воронка (Funnel)
└── Этап 1 (FunnelStage, position=0)
└── Этап 2 (FunnelStage, position=1)
└── Этап 3 (FunnelStage, position=2)
    ...

Отдел (Department)
├── подключён к Воронке (department_funnel)
└── отмечает активные этапы (department_funnel_stage)
```

Воронка — это справочник-контейнер с упорядоченными этапами. Отдел подключается к воронке и явно отмечает, какие этапы в данный момент используются.

---

## Модели

### `Funnel`

Файл: `app/Models/Funnel.php`

| Поле | Тип | Описание |
|------|-----|----------|
| `name` | string | Название воронки |
| `description` | text\|null | Описание |
| `color` | string(16) | HEX-цвет (`#25d366` по умолчанию) |
| `is_active` | boolean | Активна ли воронка |
| `position` | integer | Порядок в списке |

**Отношения:**
- `stages()` — этапы, отсортированные по `position` (HasMany → FunnelStage)
- `departments()` — отделы через pivot `department_funnel` (BelongsToMany → Department)

### `FunnelStage`

Файл: `app/Models/FunnelStage.php`

| Поле | Тип | Описание |
|------|-----|----------|
| `funnel_id` | integer | Ссылка на воронку |
| `name` | string | Название этапа |
| `color` | string(16) | HEX-цвет этапа |
| `position` | integer | Порядок внутри воронки |
| `is_active` | boolean | Активен ли этап |

При удалении воронки этапы удаляются каскадно (FK `cascadeOnDelete`).

---

## База данных

| Таблица | Назначение |
|---------|-----------|
| `funnels` | Справочник воронок |
| `funnel_stages` | Этапы, принадлежащие воронке |
| `department_funnel` | Pivot: отдел ↔ воронка |
| `department_funnel_stage` | Pivot: отдел ↔ этап (явный выбор активных этапов) |

---

## API и маршруты

Все маршруты — в группе `middleware(['auth', 'role:administrator'])`.

### Воронки

| Метод | URL | Имя | Действие |
|-------|-----|-----|----------|
| `GET` | `/funnels` | `settings.funnels` | Страница управления воронками |
| `POST` | `/funnels` | `settings.funnels.store` | Создать воронку |
| `PUT` | `/funnels/{funnel}` | `settings.funnels.update` | Обновить воронку |
| `DELETE` | `/funnels/{funnel}` | `settings.funnels.destroy` | Удалить воронку |

### Этапы воронки

| Метод | URL | Имя | Действие |
|-------|-----|-----|----------|
| `POST` | `/funnels/{funnel}/stages` | `settings.funnels.stages.store` | Создать этап |
| `PUT` | `/funnels/{funnel}/stages/{stage}` | `settings.funnels.stages.update` | Обновить этап |
| `DELETE` | `/funnels/{funnel}/stages/{stage}` | `settings.funnels.stages.destroy` | Удалить этап |
| `POST` | `/funnels/{funnel}/stages/reorder` | `settings.funnels.stages.reorder` | Изменить порядок этапов |

#### Reorder: тело запроса

```json
{
  "stage_ids": [3, 1, 2]
}
```

Массив id этапов в нужной последовательности. Позиция устанавливается по индексу. Этапы, не принадлежащие воронке, молча игнорируются.

---

## Контроллер `FunnelController`

Файл: `app/Http/Controllers/FunnelController.php`

Реализует полный CRUD воронок и этапов. Ключевые моменты:

- При создании воронки `position` = `MAX(position) + 1` (транзакция).
- При создании этапа `position` = `MAX(position) + 1` внутри воронки (транзакция).
- `reorderStages` — защита от «чужих» id: проверяет принадлежность каждого этапа текущей воронке.
- `updateStage` / `destroyStage` — проверяют `stage.funnel_id === funnel.id`, иначе `404`.

---

## Аналитика

### Маршрут

```
GET /analytics/funnels   →   Api\FunnelAnalyticsController
```

Доступен ролям `administrator`, `manager`, `employee`. Можно фильтровать по `?department_id=`.

### Ответ

```jsonc
{
  "summary": {
    "total_funnels": 3,
    "active_funnels": 2,
    "connected_funnels": 2,       // воронки, подключённые хотя бы к 1 отделу
    "total_stages": 12,
    "selected_stages": 8,         // этапы, явно отмеченные отделами
    "departments_in_scope": 4,
    "stage_coverage_percent": 66.7
  },
  "funnels": [
    {
      "id": 1,
      "name": "Основная воронка",
      "color": "#25d366",
      "is_active": true,
      "stages_count": 4,
      "selected_stages_count": 3,
      "coverage_percent": 75.0,
      "departments_count": 2,
      "departments": [{ "id": 1, "name": "Продажи" }],
      "stages": [
        { "id": 1, "name": "Новый лид", "color": "#3b82f6", "is_active": true, "selected": true },
        { "id": 2, "name": "Переговоры", "color": "#f59e0b", "is_active": true, "selected": true }
      ]
    }
  ]
}
```

---

## Интерфейс

**Страница настроек:** `/settings/funnels` (`resources/js/Pages/Settings/Funnels.vue`)

- Создание / редактирование воронок с выбором цвета из палитры (15 пресетов).
- Drag-and-drop сортировка этапов (ручной порядок).
- Переключатель `is_active` для воронки и каждого этапа.

**Страница аналитики:** `/analytics` → вкладка «Воронки продаж» (`resources/js/Pages/Analytics/Dialogs.vue`)

- KPI-плитки: всего / активных / подключённых воронок, этапов, % охвата.
- Таблица по каждой воронке: этапы, отделы, покрытие.

**Настройки отдела:** `/settings/departments`

- В модалке отдела — пикер воронок и явный выбор этапов, которые отдел использует.

---

## Права

| Действие | Роль |
|----------|------|
| Создание / редактирование / удаление воронок и этапов | `administrator` |
| Подключение воронок к отделу | `administrator` |
| Просмотр аналитики по воронкам | `administrator`, `manager`, `employee` |

---

## AI-трекинг и ручная смена этапа в чате (WhatsApp)

Работает только для **чатов с клиентом** (не группы), при включённом модуле `module_funnels`.

### Как устроено

1. **Каталог** для чата строится из отделов, прикреплённых к чату: воронки из `department_funnel` и этапы из `department_funnel_stage` (если этапы для воронки не выбраны — берутся все активные этапы воронки).
2. После **каждого входящего** сообщения клиента (очередь WhatsApp) ставится отложенная задача `AnalyzeChatFunnelJob` (debounce по `config/funnel.php`, по умолчанию 45 с). К моменту запуска проверяется, что это всё ещё последнее входящее сообщение — иначе задача пропускается.
3. **Независимо от AI-автоответа** (`ai_enabled`): классификация вызывается отдельно через `ChatFunnelClassifierService` (OpenAI JSON). Если у чата включён **«Закрепить этап»** (`funnel_stage_locked`), AI не меняет воронку.
4. Состояние хранится в `chats`: `funnel_id`, `funnel_stage_id`, `funnel_tracking_enabled`, `funnel_stage_locked`, служебные поля последнего анализа. История переходов — `chat_funnel_transitions`.
5. **Realtime:** событие `ChatFunnelUpdated` на канале `private-chat.{id}` с именем `funnel.updated` — шапка чата обновляет зелёную полоску прогресса без перезагрузки страницы.
6. **Ручная смена:** клик по полоске в шапке открывает модалку; API `PATCH /chats/{chat}/funnel`, история `GET /chats/{chat}/funnel/history`.

### Переменные окружения (опционально)

См. `config/funnel.php`: `FUNNEL_AI_MIN_CONFIDENCE`, `FUNNEL_AI_ROLLBACK_MIN_CONFIDENCE`, `FUNNEL_AI_DEBOUNCE_SECONDS`, и т.д.

