<?php

declare(strict_types=1);

namespace App\Services\Memory;

use App\Enums\EntityMemorySubjectType;
use App\Models\Chat;
use App\Models\Contact;
use App\Models\EntityMemory;
use App\Models\EntityMemoryBackup;
use App\Models\User;
use App\Support\TenantCompany;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

final class EntityMemoryService
{
    public function __construct(
        private readonly EntityMemorySubjectResolver $subjects,
    ) {}

    public function get(EntityMemorySubjectType $type, int $subjectId, bool $createIfMissing = true): EntityMemory
    {
        $this->subjects->assertExists($type, $subjectId);
        $tenantId = TenantCompany::id();

        $memory = EntityMemory::query()
            ->where('tenant_company_id', $tenantId)
            ->where('subject_type', $type->value)
            ->where('subject_id', $subjectId)
            ->first();

        if ($memory instanceof EntityMemory) {
            return $memory;
        }

        if (! $createIfMissing) {
            throw ValidationException::withMessages([
                'subject' => 'Память для этой сущности ещё не создана.',
            ]);
        }

        $content = $this->defaultTemplate($type);

        return EntityMemory::query()->create([
            'tenant_company_id' => $tenantId,
            'subject_type' => $type->value,
            'subject_id' => $subjectId,
            'content' => $content,
            'content_hash' => $this->hash($content),
        ]);
    }

    public function content(EntityMemorySubjectType $type, int $subjectId): string
    {
        $memory = EntityMemory::query()
            ->where('tenant_company_id', TenantCompany::id())
            ->where('subject_type', $type->value)
            ->where('subject_id', $subjectId)
            ->first();

        if ($memory instanceof EntityMemory) {
            return trim((string) $memory->content);
        }

        $fromFile = $this->readFileIfExists($type, $subjectId);
        if ($fromFile !== null) {
            return $fromFile;
        }

        return '';
    }

    public function update(EntityMemorySubjectType $type, int $subjectId, string $content, User $actor): EntityMemory
    {
        $this->subjects->authorizeManage($actor, $type, $subjectId);

        $max = max(1000, (int) config('entity-memory.max_content_chars', 50000));
        $content = trim($content);
        if (mb_strlen($content) > $max) {
            throw ValidationException::withMessages([
                'content' => "Слишком длинный текст (максимум {$max} символов).",
            ]);
        }

        return DB::transaction(function () use ($type, $subjectId, $content, $actor): EntityMemory {
            $memory = $this->get($type, $subjectId, true);
            $previous = (string) $memory->content;

            if ($previous !== $content && trim($previous) !== '') {
                $this->storeBackup($memory, $previous, $actor);
            }

            $memory->forceFill([
                'content' => $content,
                'content_hash' => $this->hash($content),
                'updated_by_user_id' => $actor->id,
            ])->save();

            $this->syncFiles($memory);
            $this->pruneBackups($memory);

            return $memory->fresh(['updatedBy:id,name']);
        });
    }

    /**
     * @return Collection<int, EntityMemoryBackup>
     */
    public function backups(EntityMemorySubjectType $type, int $subjectId): Collection
    {
        $memory = EntityMemory::query()
            ->where('tenant_company_id', TenantCompany::id())
            ->where('subject_type', $type->value)
            ->where('subject_id', $subjectId)
            ->first();

        if ($memory === null) {
            return collect();
        }

        return $memory->backups()
            ->with('createdBy:id,name')
            ->limit((int) config('entity-memory.max_backups_kept', 50))
            ->get();
    }

    public function restoreBackup(EntityMemorySubjectType $type, int $subjectId, int $backupId, User $actor): EntityMemory
    {
        $memory = $this->get($type, $subjectId, false);
        $this->subjects->authorizeManage($actor, $type, $subjectId);

        $backup = EntityMemoryBackup::query()
            ->where('entity_memory_id', $memory->id)
            ->whereKey($backupId)
            ->firstOrFail();

        return $this->update($type, $subjectId, (string) $backup->content, $actor);
    }

    /**
     * @return list<string>
     */
    public function contextBlocksForChat(Chat $chat, User $responder): array
    {
        $blocks = [];
        $tenantContent = $this->content(EntityMemorySubjectType::Tenant, TenantCompany::id());
        if ($tenantContent !== '') {
            $blocks[] = "Память о нашей компании (memory.md):\n{$tenantContent}";
        }

        $employeeContent = $this->content(EntityMemorySubjectType::Employee, (int) $responder->id);
        if ($employeeContent !== '') {
            $blocks[] = "Память о сотруднике {$responder->name} (memory.md):\n{$employeeContent}";
        }

        if ($chat->contact_id !== null) {
            $contactContent = $this->content(EntityMemorySubjectType::Contact, (int) $chat->contact_id);
            if ($contactContent !== '') {
                $chat->loadMissing('contact:id,name,push_name,phone_number');
                $label = $chat->contact?->name ?: $chat->contact?->push_name ?: 'клиент';
                $blocks[] = "Память о клиенте {$label} (memory.md):\n{$contactContent}";
            }

            $contact = Contact::query()
                ->with(['companies:id,name'])
                ->find($chat->contact_id);

            foreach ($contact?->companies ?? [] as $company) {
                if ((int) $company->id === TenantCompany::id()) {
                    continue;
                }
                $companyContent = $this->content(EntityMemorySubjectType::ClientCompany, (int) $company->id);
                if ($companyContent !== '') {
                    $blocks[] = "Память о компании клиента «{$company->name}» (memory.md):\n{$companyContent}";
                }
            }
        }

        return $blocks;
    }

    public function syncAllToFiles(): int
    {
        $count = 0;
        EntityMemory::query()->orderBy('id')->chunk(100, function ($memories) use (&$count): void {
            foreach ($memories as $memory) {
                if ($memory instanceof EntityMemory) {
                    $this->syncFiles($memory);
                    $count++;
                }
            }
        });

        return $count;
    }

    private function storeBackup(EntityMemory $memory, string $content, User $actor): void
    {
        EntityMemoryBackup::query()->create([
            'entity_memory_id' => $memory->id,
            'content' => $content,
            'content_hash' => $this->hash($content),
            'created_by_user_id' => $actor->id,
            'created_at' => now(),
        ]);

        if (! config('entity-memory.sync_files', true)) {
            return;
        }

        $type = $memory->subjectTypeEnum();
        $backupPath = $this->backupFilePath($type, (int) $memory->subject_id, now()->format('Y-m-d_His'));
        $disk = (string) config('entity-memory.disk', 'local');
        Storage::disk($disk)->put($backupPath, $content);
    }

    private function pruneBackups(EntityMemory $memory): void
    {
        $max = max(5, (int) config('entity-memory.max_backups_kept', 50));
        $ids = EntityMemoryBackup::query()
            ->where('entity_memory_id', $memory->id)
            ->orderByDesc('created_at')
            ->get(['id'])
            ->slice($max)
            ->pluck('id');

        if ($ids->isNotEmpty()) {
            EntityMemoryBackup::query()->whereIn('id', $ids->all())->delete();
        }
    }

    private function syncFiles(EntityMemory $memory): void
    {
        if (! config('entity-memory.sync_files', true)) {
            return;
        }

        $type = $memory->subjectTypeEnum();
        $path = $this->memoryFilePath($type, (int) $memory->subject_id);
        $disk = (string) config('entity-memory.disk', 'local');
        Storage::disk($disk)->put($path, (string) $memory->content);
    }

    private function readFileIfExists(EntityMemorySubjectType $type, int $subjectId): ?string
    {
        if (! config('entity-memory.sync_files', true)) {
            return null;
        }

        $path = $this->memoryFilePath($type, $subjectId);
        $disk = (string) config('entity-memory.disk', 'local');
        if (! Storage::disk($disk)->exists($path)) {
            return null;
        }

        return trim((string) Storage::disk($disk)->get($path));
    }

    private function memoryFilePath(EntityMemorySubjectType $type, int $subjectId): string
    {
        $base = trim((string) config('entity-memory.base_path', 'entity-memory'), '/');

        return "{$base}/{$type->fileSlug()}/{$subjectId}/memory.md";
    }

    private function backupFilePath(EntityMemorySubjectType $type, int $subjectId, string $stamp): string
    {
        $base = trim((string) config('entity-memory.base_path', 'entity-memory'), '/');

        return "{$base}/{$type->fileSlug()}/{$subjectId}/backups/{$stamp}.md";
    }

    private function defaultTemplate(EntityMemorySubjectType $type): string
    {
        $path = resource_path('entity-memory-templates/'.$type->fileSlug().'.md');
        if (File::isFile($path)) {
            return trim((string) File::get($path));
        }

        return '# '.$type->label()."\n\n";
    }

    private function hash(string $content): string
    {
        return hash('sha256', $content);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(EntityMemory $memory): array
    {
        $type = $memory->subjectTypeEnum();
        $meta = $this->subjects->describe($type, (int) $memory->subject_id);

        return [
            'subject_type' => $type->value,
            'subject_id' => (int) $memory->subject_id,
            'subject_label' => $type->label(),
            'title' => $meta['title'],
            'subtitle' => $meta['subtitle'],
            'content' => (string) $memory->content,
            'content_hash' => $memory->content_hash,
            'updated_at' => $memory->updated_at?->toIso8601String(),
            'updated_by' => $memory->updatedBy ? [
                'id' => $memory->updatedBy->id,
                'name' => $memory->updatedBy->name,
            ] : null,
            'file_path' => $this->memoryFilePath($type, (int) $memory->subject_id),
        ];
    }
}
