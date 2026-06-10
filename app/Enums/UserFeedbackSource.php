<?php

declare(strict_types=1);

namespace App\Enums;

enum UserFeedbackSource: string
{
    case Web = 'web';
    case Mobile = 'mobile';
}
