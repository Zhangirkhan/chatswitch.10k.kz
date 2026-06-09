<?php

declare(strict_types=1);

namespace App\Tenancy;

use Illuminate\Database\Eloquent\Model;
use Spatie\Permission\Contracts\PermissionsTeamResolver;

final class TenantPermissionTeamResolver implements PermissionsTeamResolver
{
    private int|string|null $explicitTeamId = null;

    /**
     * @param  int|string|Model|null  $id
     */
    public function setPermissionsTeamId($id): void
    {
        if ($id instanceof Model) {
            $id = $id->getKey();
        }

        $this->explicitTeamId = $id;
    }

    public function getPermissionsTeamId(): int|string|null
    {
        if ($this->explicitTeamId !== null) {
            return $this->explicitTeamId;
        }

        return app(TenantContext::class)->companyIdOrNull();
    }
}
