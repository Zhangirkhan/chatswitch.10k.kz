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
