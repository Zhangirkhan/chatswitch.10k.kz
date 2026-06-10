<?php

declare(strict_types=1);

namespace App\Enums;

enum UserFeedbackType: string
{
    case Complaint = 'complaint';
    case Suggestion = 'suggestion';
}
