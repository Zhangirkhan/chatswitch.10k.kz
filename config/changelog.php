<?php

declare(strict_types=1);

return [

    'git_sync' => [
        'enabled' => (bool) env('CHANGELOG_GIT_SYNC_ENABLED', true),

        /** Абсолютный путь к git-репозиторию (по умолчанию — корень приложения). */
        'repository_path' => env('CHANGELOG_GIT_REPOSITORY_PATH'),

        /** Сколько новых коммитов обрабатывать за один запуск. */
        'batch_limit' => (int) env('CHANGELOG_GIT_BATCH_LIMIT', 20),

        /**
         * При первом запуске (нет записей с git_commit_hash) — сколько последних коммитов импортировать.
         * 0 = не импортировать историю, только новые коммиты после первого sync.
         */
        'bootstrap_commits' => (int) env('CHANGELOG_GIT_BOOTSTRAP_COMMITS', 15),

        /** Автопубликация записей из git. */
        'auto_publish' => (bool) env('CHANGELOG_GIT_AUTO_PUBLISH', true),
    ],

];
