<?php

declare(strict_types=1);

return [
    /**
     * Служебный пользователь для автоматических ответов (например после отклонения WA-звонка).
     * Email должен совпадать с записью, создаваемой миграцией `ensure_system_user_for_automated_messages`.
     */
    'system_user_email' => env('SYSTEM_USER_EMAIL', 'system@chatswitch.internal'),
];
