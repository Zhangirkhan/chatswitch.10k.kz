<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\DepartmentResource;
use App\Models\Department;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

final class DepartmentController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        $departments = Department::query()
            ->where('is_active', true)
            ->orderBy('name')
            ->get();

        return DepartmentResource::collection($departments);
    }
}
