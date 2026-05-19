<?php

declare(strict_types=1);

namespace App\Enums;

enum EntityMemorySubjectType: string
{
    case Tenant = 'tenant';
    case Contact = 'contact';
    case Employee = 'employee';
    case ClientCompany = 'client_company';

    public function label(): string
    {
        return match ($this) {
            self::Tenant => 'Наша компания',
            self::Contact => 'Клиент',
            self::Employee => 'Сотрудник',
            self::ClientCompany => 'Компания клиента',
        };
    }

    public function fileSlug(): string
    {
        return $this->value;
    }
}
