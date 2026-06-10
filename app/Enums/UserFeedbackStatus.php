<?php

declare(strict_types=1);

namespace App\Enums;

enum UserFeedbackStatus: string
{
    case New = 'new';
    case Read = 'read';
    case Resolved = 'resolved';
}
