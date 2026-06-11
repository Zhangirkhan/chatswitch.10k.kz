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

    public function clear(EntityMemorySubjectType $type, int $subjectId, User $actor): EntityMemory
    {
        return $this->update($type, $subjectId, $this->defaultTemplate($type), $actor);
    }

    /**
     * Return the parsed AI-facts from the managed section, or [] if none exist yet.
     *
     * @return array<string, string>
     */
    public function readAiFacts(EntityMemorySubjectType $type, int $subjectId): array
    {
        try {
            $memory = $this->get($type, $subjectId, false);

            return $this->parseAiFactsSection((string) $memory->content);
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * AI-safe merge: update only the managed "## AI-факты (авто)" section inside memory.md
     * without touching any manually-written content.  No authorization check — this is a
     * system-level write path.  Still creates a backup before overwriting.
     *
     * Field-level merge semantics: fields absent from $facts but present in the existing
     * AI section are preserved.  This prevents a narrow extraction window (last 20 msgs)
     * from silently wiping confirmed facts (e.g. budget stated a month ago).
     *
     * @param  array<string, mixed>  $facts  Structured facts extracted by ConversationMemoryExtractor
     */
    public function mergeAiFacts(EntityMemorySubjectType $type, int $subjectId, array $facts): EntityMemory
    {
        if ($facts === []) {
            return $this->get($type, $subjectId, true);
        }

        $tenantId = TenantCompany::id();

        return DB::transaction(function () use ($type, $subjectId, $facts, $tenantId): EntityMemory {
            $memory = $this->get($type, $subjectId, true);
            $previous = (string) $memory->content;

            // Field-level merge: new extraction wins for present fields;
            // fields absent from this run but existing in the AI section are kept.
            $existingFacts = $this->parseAiFactsSection($previous);
            $nonEmpty = array_filter($facts, static fn (mixed $v): bool => $v !== null && $v !== '' && $v !== []);

            // Per-fact timestamps: when a TIMESTAMPED_KEY fact actually changes value,
            // update its _at timestamp so the LLM can see budget/timeline progression.
            $now = now()->format('d.m H:i');
            foreach (self::TIMESTAMPED_KEYS as $key) {
                $newVal = $nonEmpty[$key] ?? null;
                if ($newVal === null) {
                    continue;
                }
                $oldVal = $existingFacts[$key] ?? null;
                if ((string) $newVal !== (string) $oldVal) {
                    // Value changed or newly extracted — stamp it.
                    $nonEmpty[$key.'_at'] = $now;
                } elseif (isset($existingFacts[$key.'_at'])) {
                    // Value unchanged — preserve the existing timestamp.
                    $nonEmpty[$key.'_at'] = $existingFacts[$key.'_at'];
                }
            }

            $merged = array_merge($existingFacts, $nonEmpty);

            $newSection = $this->renderAiFactsSection($merged);
            $newContent = $this->injectAiFactsSection($previous, $newSection);

            if (trim($newContent) === trim($previous)) {
                return $memory;
            }

            $max = max(1000, (int) config('entity-memory.max_content_chars', 50000));
            if (mb_strlen($newContent) > $max) {
                // Trim the AI section to fit, preserving manual content
                $available = $max - mb_strlen($this->stripAiFactsSection($previous)) - 50;
                if ($available > 200) {
                    $newSection = mb_substr($newSection, 0, $available).'…';
                    $newContent = $this->injectAiFactsSection($previous, $newSection);
                } else {
                    // Manual content already too long; skip AI write silently
                    return $memory;
                }
            }

            if (trim($previous) !== '') {
                $this->storeBackupSystem($memory, $previous);
            }

            $memory->forceFill([
                'content' => $newContent,
                'content_hash' => $this->hash($newContent),
            ])->save();

            $this->syncFiles($memory);

            return $memory->fresh() ?? $memory;
        });
    }

    private const AI_FACTS_SECTION_START = '## AI-факты (авто)';

    private const AI_FACTS_SECTION_END = '<!-- /ai-facts -->';

    /**
     * Facts that get a per-fact timestamp displayed for budget/timeline progression auditing.
     * The LLM can see "Бюджет: 1 000 000 тг (обн. 12.06 14:30)" and reason about recency.
     */
    private const TIMESTAMPED_KEYS = ['budget', 'requirements', 'agreements', 'objections'];

    /**
     * Render the managed AI-facts section from structured facts.
     *
     * For TIMESTAMPED_KEYS, if `facts[$key.'_at']` is present, appends
     * "(обн. DATE)" after the value so the LLM can reason about recency.
     *
     * @param  array<string, mixed>  $facts
     */
    private function renderAiFactsSection(array $facts): string
    {
        $lines = [self::AI_FACTS_SECTION_START];
        $lines[] = '_Автоматически обновлено AI. Не редактируй эту секцию вручную._';
        $lines[] = '';

        $labels = [
            'budget'       => 'Бюджет',
            'requirements' => 'Требования',
            'objections'   => 'Возражения',
            'agreements'   => 'Договорённости',
            'preferences'  => 'Предпочтения',
            'source'       => 'Источник лида',
            'contact_info' => 'Контактные данные',
            'other'        => 'Прочее',
        ];

        foreach ($labels as $key => $label) {
            $value = $facts[$key] ?? null;
            if ($value === null || $value === '' || $value === []) {
                continue;
            }
            if (is_array($value)) {
                $value = implode(', ', array_filter(array_map('strval', $value)));
            }
            $value = trim((string) $value);
            if ($value === '') {
                continue;
            }

            // Append per-fact timestamp for auditable progression facts.
            $at = $facts[$key.'_at'] ?? null;
            if (in_array($key, self::TIMESTAMPED_KEYS, true) && is_string($at) && $at !== '') {
                $value .= " (обн. {$at})";
            }

            $lines[] = "**{$label}:** {$value}";
        }

        $lines[] = '';
        $lines[] = '_Обновлено: '.now()->format('Y-m-d H:i').'_';
        $lines[] = self::AI_FACTS_SECTION_END;

        return implode("\n", $lines);
    }

    /**
     * Parse the structured key→value pairs from the managed AI-facts section.
     * Returns an associative array suitable for re-passing to renderAiFactsSection.
     * Per-fact timestamps "(обн. …)" are extracted into separate "$key_at" keys.
     *
     * @return array<string, string>
     */
    private function parseAiFactsSection(string $content): array
    {
        $pattern = '/'.preg_quote(self::AI_FACTS_SECTION_START, '/').'(.*?)'.preg_quote(self::AI_FACTS_SECTION_END, '/').'/s';
        if (! preg_match($pattern, $content, $m)) {
            return [];
        }

        $labels = [
            'Бюджет'             => 'budget',
            'Требования'         => 'requirements',
            'Возражения'         => 'objections',
            'Договорённости'     => 'agreements',
            'Предпочтения'       => 'preferences',
            'Источник лида'      => 'source',
            'Контактные данные'  => 'contact_info',
            'Прочее'             => 'other',
        ];

        $result = [];
        foreach ($labels as $label => $key) {
            // renderAiFactsSection produces "**Label:** value" (colon inside bold markers).
            if (preg_match('/\*\*'.preg_quote($label, '/').':\*\*\s*(.+)/u', $m[1], $lm)) {
                $rawValue = trim($lm[1]);
                if ($rawValue === '') {
                    continue;
                }

                // Extract embedded per-fact timestamp: "(обн. DD.MM HH:ii)" suffix.
                if (in_array($key, self::TIMESTAMPED_KEYS, true)
                    && preg_match('/^(.*?)\s*\(обн\.\s*([^)]+)\)\s*$/u', $rawValue, $tm)
                ) {
                    $result[$key] = trim($tm[1]);
                    $result[$key.'_at'] = trim($tm[2]);
                } else {
                    $result[$key] = $rawValue;
                }
            }
        }

        return $result;
    }

    /**
     * Replace or append the AI-facts section in the full memory content.
     */
    private function injectAiFactsSection(string $content, string $section): string
    {
        $pattern = '/'.preg_quote(self::AI_FACTS_SECTION_START, '/').'.*?'.preg_quote(self::AI_FACTS_SECTION_END, '/').'/s';

        if (preg_match($pattern, $content)) {
            return (string) preg_replace($pattern, $section, $content);
        }

        $trimmed = rtrim($content);

        return ($trimmed !== '' ? $trimmed."\n\n" : '').$section;
    }

    /**
     * Remove the AI-facts section from content (to calculate remaining space).
     */
    private function stripAiFactsSection(string $content): string
    {
        $pattern = '/\n*'.preg_quote(self::AI_FACTS_SECTION_START, '/').'.*?'.preg_quote(self::AI_FACTS_SECTION_END, '/').'\n*/s';

        return (string) preg_replace($pattern, '', $content);
    }

    /**
     * Store a backup without requiring a User actor (system writes).
     */
    private function storeBackupSystem(EntityMemory $memory, string $content): void
    {
        try {
            EntityMemoryBackup::query()->create([
                'entity_memory_id' => $memory->id,
                'content' => $content,
                'content_hash' => $this->hash($content),
                'created_by_user_id' => null,
                'created_at' => now(),
            ]);
        } catch (\Throwable $e) {
            report($e);
        }
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

        try {
            $type = $memory->subjectTypeEnum();
            $backupPath = $this->backupFilePath($type, (int) $memory->subject_id, now()->format('Y-m-d_His'));
            $disk = (string) config('entity-memory.disk', 'local');
            Storage::disk($disk)->put($backupPath, $content);
        } catch (\Throwable $e) {
            report($e);
        }
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

        try {
            $type = $memory->subjectTypeEnum();
            $path = $this->memoryFilePath($type, (int) $memory->subject_id);
            $disk = (string) config('entity-memory.disk', 'local');
            Storage::disk($disk)->put($path, (string) $memory->content);
        } catch (\Throwable $e) {
            report($e);
        }
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
