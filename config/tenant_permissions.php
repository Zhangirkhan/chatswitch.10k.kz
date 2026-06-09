<?php

declare(strict_types=1);

return [

    'groups' => [
        'settings' => [
            'label' => 'Настройки',
            'permissions' => [
                'settings.manage' => 'Управление настройками компании',
                'settings.modules' => 'Включение и отключение модулей',
            ],
        ],
        'users' => [
            'label' => 'Пользователи',
            'permissions' => [
                'users.manage' => 'Создание и редактирование сотрудников',
                'users.view' => 'Просмотр списка сотрудников',
            ],
        ],
        'departments' => [
            'label' => 'Отделы',
            'permissions' => [
                'departments.manage' => 'Управление отделами',
            ],
        ],
        'roles' => [
            'label' => 'Роли',
            'permissions' => [
                'roles.manage' => 'Создание и редактирование ролей',
            ],
        ],
        'chats' => [
            'label' => 'Чаты',
            'permissions' => [
                'chats.view_all' => 'Просмотр всех чатов компании',
                'chats.view_department' => 'Просмотр чатов своего отдела',
                'chats.view_assigned' => 'Просмотр назначенных чатов',
                'chats.assign' => 'Назначение ответственных',
                'chats.send' => 'Отправка сообщений',
            ],
        ],
        'contacts' => [
            'label' => 'Клиенты',
            'permissions' => [
                'contacts.manage' => 'Редактирование клиентов',
                'contacts.view' => 'Просмотр клиентов',
            ],
        ],
        'whatsapp' => [
            'label' => 'WhatsApp',
            'permissions' => [
                'whatsapp.manage' => 'Подключение и настройка сессий',
                'whatsapp.use' => 'Работа с подключёнными сессиями',
            ],
        ],
        'broadcasts' => [
            'label' => 'Рассылки',
            'permissions' => [
                'broadcasts.manage' => 'Создание и отправка рассылок',
            ],
        ],
        'funnels' => [
            'label' => 'Воронки',
            'permissions' => [
                'funnels.manage' => 'Настройка воронок и этапов',
                'funnels.view' => 'Просмотр воронок и сделок',
            ],
        ],
        'analytics' => [
            'label' => 'Аналитика',
            'permissions' => [
                'analytics.view' => 'Просмотр аналитики',
            ],
        ],
        'calendar' => [
            'label' => 'Календарь',
            'permissions' => [
                'calendar.manage' => 'Управление событиями календаря',
            ],
        ],
        'team_chat' => [
            'label' => 'Командный чат',
            'permissions' => [
                'team_chat.use' => 'Участие в командном чате',
                'team_chat.pin' => 'Закрепление сообщений',
            ],
        ],
    ],

    /**
     * Права по умолчанию для стандартных ролей при миграции tenant.
     *
     * @var array<string, list<string>>
     */
    'default_role_permissions' => [
        'administrator' => ['*'],
        'manager' => [
            'users.view',
            'chats.view_department',
            'chats.view_assigned',
            'chats.assign',
            'chats.send',
            'contacts.view',
            'contacts.manage',
            'whatsapp.use',
            'broadcasts.manage',
            'funnels.view',
            'analytics.view',
            'calendar.manage',
            'team_chat.use',
            'team_chat.pin',
        ],
        'employee' => [
            'chats.view_department',
            'chats.view_assigned',
            'chats.send',
            'contacts.view',
            'whatsapp.use',
            'funnels.view',
            'analytics.view',
            'calendar.manage',
            'team_chat.use',
        ],
    ],

    /**
     * Системные slug ролей, которые нельзя удалить.
     *
     * @var list<string>
     */
    'protected_role_names' => [
        'administrator',
        'manager',
        'employee',
    ],

];
