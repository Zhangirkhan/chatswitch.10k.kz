# Задачи (посты-задачи по отделам)

Модуль внутренней постановки задач внутри отделов компании. Работает в разделе «Организация». Задача привязана к отделу, имеет статус, срок, описание, комментарии и вложения.

---

## Концепция

```
Отдел (Department)
└── Задача (DepartmentPost)
    ├── Комментарии (DepartmentPostComment)
    └── Вложения (DepartmentPostAttachment)
```

Сотрудники видят только задачи своих отделов. Администратор видит всё. Завершённые задачи переходят в **архив**.

---

## Статусы задачи

| Константа | Значение | Смысл |
|-----------|---------|-------|
| `STATUS_OPEN` | `open` | Новая задача |
| `STATUS_IN_PROGRESS` | `in_progress` | В работе |
| `STATUS_DONE` | `done` | Завершена (уходит в архив) |

В списке задач отдела порядок: `in_progress` → `open` → далее по дате создания (desc).

---

## Модель `DepartmentPost`

Файл: `app/Models/DepartmentPost.php`

| Поле | Тип | Описание |
|------|-----|----------|
| `department_id` | integer | Отдел-владелец |
| `author_id` | integer | Автор задачи |
| `title` | string(255) | Заголовок |
| `body` | text\|null | Подробное описание |
| `status` | enum | `open` / `in_progress` / `done` |
| `due_at` | datetime\|null | Срок выполнения |

**Отношения:**
- `department()` — BelongsTo → Department
- `author()` — BelongsTo → User
- `comments()` — HasMany → DepartmentPostComment
- `attachments()` — HasMany → DepartmentPostAttachment

---

## Маршруты

Все маршруты требуют аутентификации (`auth`).

| Метод | URL | Имя | Действие |
|-------|-----|-----|----------|
| `GET` | `/organization` | `organization.index` | Список отделов |
| `GET` | `/organization/departments/{department}` | `organization.departments.show` | Задачи отдела |
| `POST` | `/organization/departments/{department}/posts` | `organization.posts.store` | Создать задачу |
| `GET` | `/organization/posts/{post}` | `organization.posts.show` | Просмотр задачи |
| `PATCH` | `/organization/posts/{post}` | `organization.posts.update` | Обновить задачу |
| `DELETE` | `/organization/posts/{post}` | `organization.posts.destroy` | Удалить задачу |
| `GET` | `/organization/archive` | `organization.archive` | Архив (статус `done`) |

### Комментарии

| Метод | URL | Имя | Действие |
|-------|-----|-----|----------|
| `POST` | `/organization/posts/{post}/comments` | `organization.posts.comments.store` | Добавить комментарий |
| `DELETE` | `/organization/posts/{post}/comments/{comment}` | `organization.posts.comments.destroy` | Удалить комментарий |

### Вложения

| Метод | URL | Имя | Действие |
|-------|-----|-----|----------|
| `POST` | `/organization/posts/{post}/attachments` | `organization.posts.attachments.store` | Загрузить файл |
| `DELETE` | `/organization/posts/{post}/attachments/{attachment}` | `organization.posts.attachments.destroy` | Удалить файл |

---

## Контроллер `OrganizationController`

Файл: `app/Http/Controllers/OrganizationController.php`

### Создание задачи — тело запроса

```json
{
  "title": "Подготовить КП",
  "body": "Подробное описание задачи...",
  "status": "open",
  "due_at": "2026-05-20T18:00:00"
}
```

`status` и `due_at` — необязательны. По умолчанию статус `open`.

### Обновление задачи (`PATCH`)

Те же поля, все необязательны. Смену статуса на `done` выполняет оператор/менеджер — задача автоматически исчезает из активного списка и появляется в архиве.

---

## Права доступа

| Действие | Кто может |
|----------|-----------|
| Видеть отдел и его задачи | Члены отдела + администратор |
| Создавать задачи | Члены отдела + администратор |
| Редактировать / удалять задачу | Автор задачи + администратор |
| Добавлять комментарии | Члены отдела + администратор |
| Удалять комментарий | Автор комментария + администратор |
| Просматривать архив | Члены отдела + администратор (видят только свои отделы) |

---

## Интерфейс

| Страница | Маршрут | Компонент |
|---------|---------|-----------|
| Главная организации | `/organization` | `Organization/Index.vue` |
| Задачи отдела | `/organization/departments/{id}` | `Organization/Department.vue` |
| Карточка задачи | `/organization/posts/{id}` | `Organization/Post.vue` |
| Архив завершённых | `/organization/archive` | `Organization/Archive.vue` |

В левом сайдбаре — список отделов с бейджами количества активных задач. Архив вынесен отдельным пунктом в сайдбаре с счётчиком завершённых задач.

### Архив

Поиск по заголовку, содержимому, отделу и автору. Отображает задачи в статусе `done`, отсортированные по дате последнего обновления (desc).

---

## Вложения

Файлы загружаются через `POST /organization/posts/{post}/attachments`. Хранятся на диске (Laravel Storage). Модель `DepartmentPostAttachment` содержит:

| Поле | Описание |
|------|----------|
| `original_name` | Исходное имя файла |
| `mime_type` | MIME-тип |
| `size` | Размер в байтах |
| `uploaded_by` | id пользователя, загрузившего файл |

Метод `isImage()` — определяет, является ли вложение изображением (для превью). Метод `url()` — возвращает публичный URL файла.
