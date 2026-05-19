<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\EntityMemorySubjectType;
use App\Services\Memory\EntityMemoryService;
use App\Services\Memory\EntityMemorySubjectResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

final class EntityMemoryController extends Controller
{
    public function __construct(
        private readonly EntityMemoryService $memories,
        private readonly EntityMemorySubjectResolver $subjects,
    ) {}

    public function show(Request $request, string $subjectType, int $subjectId): JsonResponse
    {
        $type = $this->parseType($subjectType);
        $user = $request->user();
        abort_unless($user !== null, 401);
        abort_unless($this->subjects->userCanManage($user, $type, $subjectId), 403);

        $memory = $this->memories->get($type, $subjectId, true);

        return response()->json([
            'success' => true,
            'memory' => $this->memories->toArray($memory),
        ]);
    }

    public function update(Request $request, string $subjectType, int $subjectId): JsonResponse
    {
        $type = $this->parseType($subjectType);
        $user = $request->user();
        abort_unless($user !== null, 401);

        $validated = $request->validate([
            'content' => ['required', 'string'],
        ]);

        $memory = $this->memories->update($type, $subjectId, (string) $validated['content'], $user);

        return response()->json([
            'success' => true,
            'memory' => $this->memories->toArray($memory),
        ]);
    }

    public function backups(Request $request, string $subjectType, int $subjectId): JsonResponse
    {
        $type = $this->parseType($subjectType);
        $user = $request->user();
        abort_unless($user !== null, 401);
        abort_unless($this->subjects->userCanManage($user, $type, $subjectId), 403);

        $items = $this->memories->backups($type, $subjectId)->map(static fn ($row): array => [
            'id' => $row->id,
            'content_hash' => $row->content_hash,
            'preview' => mb_substr(trim((string) $row->content), 0, 240),
            'created_at' => $row->created_at?->toIso8601String(),
            'created_by' => $row->createdBy ? [
                'id' => $row->createdBy->id,
                'name' => $row->createdBy->name,
            ] : null,
        ]);

        return response()->json([
            'success' => true,
            'data' => $items,
        ]);
    }

    public function restore(Request $request, string $subjectType, int $subjectId, int $backupId): JsonResponse
    {
        $type = $this->parseType($subjectType);
        $user = $request->user();
        abort_unless($user !== null, 401);

        $memory = $this->memories->restoreBackup($type, $subjectId, $backupId, $user);

        return response()->json([
            'success' => true,
            'memory' => $this->memories->toArray($memory),
        ]);
    }

    private function parseType(string $subjectType): EntityMemorySubjectType
    {
        $normalized = Str::replace('-', '_', strtolower($subjectType));

        return EntityMemorySubjectType::from($normalized);
    }
}
