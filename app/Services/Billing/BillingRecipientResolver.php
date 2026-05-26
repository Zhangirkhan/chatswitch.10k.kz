<?php

declare(strict_types=1);

namespace App\Services\Billing;

use App\Models\Company;
use App\Models\User;

final class BillingRecipientResolver
{
    public function resolve(Company $company): ?string
    {
        if ($company->owner_user_id !== null) {
            $ownerEmail = User::query()
                ->withoutGlobalScope('tenant')
                ->whereKey($company->owner_user_id)
                ->value('email');

            if (is_string($ownerEmail) && trim($ownerEmail) !== '') {
                return trim($ownerEmail);
            }
        }

        $email = $company->email;
        if ($email === null) {
            return null;
        }

        $email = trim($email);

        return $email !== '' ? $email : null;
    }
}
