<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\StoreDepartmentPostRequest;
use App\Http\Requests\Api\V1\UpdateDepartmentPostRequest;
use App\Http\Resources\Api\V1\DepartmentPostResource;
use App\Models\Department;
use App\Models\DepartmentPost;
use App\Services\Organization\DepartmentPostService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Carbon;

final class DepartmentPostController extends Controller
{
    public function __construct(
        private readonly DepartmentPostService $posts,
    ) {}

    public function index(Request $request, Department $department): AnonymousResourceCollection
    {
        $user = $request->user();
        abort_if($user === null, 401);

        $this->posts->ensureEnabled();
        $this->posts->authorizeDepartmentAccess($user, $department);

        $status = $request->query('status');
        $statusFilter = is_string($status) ? $status : null;

        $posts = $this->posts->listForDepartment($department, $statusFilter);

        return DepartmentPostResource::collection($posts);
    }

    public function store(StoreDepartmentPostRequest $request, Department $department): JsonResponse
    {
        $user = $request->user();
        abort_if($user === null, 401);

        $this->posts->ensureEnabled();
        $this->posts->authorizeDepartmentAccess($user, $department);

        $post = $this->posts->create(
            $department,
            $user,
            $this->normalizePayload($request->validated()),
        );

        return (new DepartmentPostResource($post))
            ->response()
            ->setStatusCode(201);
    }

    public function show(Request $request, DepartmentPost $post): DepartmentPostResource
    {
        $user = $request->user();
        abort_if($user === null, 401);

        $this->posts->ensureEnabled();
        $department = $post->department()->firstOrFail();
        $this->posts->authorizeDepartmentAccess($user, $department);

        return new DepartmentPostResource($this->posts->loadPostRelations($post));
    }

    public function update(UpdateDepartmentPostRequest $request, DepartmentPost $post): DepartmentPostResource
    {
        $user = $request->user();
        abort_if($user === null, 401);

        $this->posts->ensureEnabled();
        $department = $post->department()->firstOrFail();
        $this->posts->authorizeDepartmentAccess($user, $department);
        $this->posts->authorizeAuthorOrAdmin($user, $post->author_id);

        $post = $this->posts->update(
            $post,
            $department,
            $this->normalizePayload($request->validated()),
        );

        return new DepartmentPostResource($post);
    }

    public function destroy(Request $request, DepartmentPost $post): JsonResponse
    {
        $user = $request->user();
        abort_if($user === null, 401);

        $this->posts->ensureEnabled();
        $department = $post->department()->firstOrFail();
        $this->posts->authorizeDepartmentAccess($user, $department);
        $this->posts->authorizeAuthorOrAdmin($user, $post->author_id);

        $this->posts->delete($post);

        return response()->json(['success' => true]);
    }

    public function complete(Request $request, DepartmentPost $post): DepartmentPostResource
    {
        $user = $request->user();
        abort_if($user === null, 401);

        $this->posts->ensureEnabled();
        $department = $post->department()->firstOrFail();
        $this->posts->authorizeDepartmentAccess($user, $department);

        return new DepartmentPostResource($this->posts->complete($post));
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function normalizePayload(array $data): array
    {
        if (array_key_exists('due_at', $data)) {
            $data['due_at'] = $data['due_at'] !== null ? Carbon::parse((string) $data['due_at']) : null;
        }

        return $data;
    }
}
