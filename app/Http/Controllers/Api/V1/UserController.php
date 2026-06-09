<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\ColleagueResource;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

final class UserController extends Controller
{
    /**
     * Active staff of the current tenant (for team chat «Написать коллеге», pickers).
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $user = $request->user();
        $companyId = $user?->company_id;

        if ($companyId === null) {
            return ColleagueResource::collection(collect());
        }

        $search = trim((string) $request->query('search', ''));

        $query = User::query()
            ->where('company_id', $companyId)
            ->where('is_active', true)
            ->where('id', '!=', $user->id)
            ->orderBy('name');

        if ($search !== '') {
            $query->where(function (Builder $q) use ($search): void {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%");
            });
        }

        $colleagues = $query
            ->limit(500)
            ->get(['id', 'name', 'email']);

        return ColleagueResource::collection($colleagues);
    }
}
