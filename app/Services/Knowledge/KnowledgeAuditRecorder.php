<?php

declare(strict_types=1);

namespace App\Services\Knowledge;

use App\Models\KnowledgeAuditLog;
use App\Models\KnowledgeRule;
use App\Models\Product;
use App\Models\Service;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;

final class KnowledgeAuditRecorder
{
    public function record(
        int $companyId,
        ?int $userId,
        string $entityType,
        int $entityId,
        string $action,
        ?string $entityLabel,
        ?array $changes,
    ): void {
        if (! Schema::hasTable('knowledge_audit_logs')) {
            return;
        }

        KnowledgeAuditLog::query()->create([
            'company_id' => $companyId,
            'user_id' => $userId,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'action' => $action,
            'entity_label' => $entityLabel,
            'changes' => $changes,
        ]);
    }

    public function recordCreated(Model $model, string $entityType, ?Authenticatable $user): void
    {
        $this->record(
            (int) $model->getAttribute('company_id'),
            $user !== null ? (int) $user->getAuthIdentifier() : null,
            $entityType,
            (int) $model->getKey(),
            'created',
            $this->entityLabel($model, $entityType),
            ['after' => $this->snapshot($model, $entityType)],
        );
    }

    public function recordUpdated(Model $model, string $entityType, ?Authenticatable $user, array $before, array $after): void
    {
        $diff = $this->diffSnapshots($before, $after);
        if ($diff === []) {
            return;
        }

        $this->record(
            (int) $model->getAttribute('company_id'),
            $user !== null ? (int) $user->getAuthIdentifier() : null,
            $entityType,
            (int) $model->getKey(),
            'updated',
            $this->entityLabel($model, $entityType),
            ['diff' => $diff],
        );
    }

    public function recordDeleted(Model $model, string $entityType, ?Authenticatable $user): void
    {
        $this->record(
            (int) $model->getAttribute('company_id'),
            $user !== null ? (int) $user->getAuthIdentifier() : null,
            $entityType,
            (int) $model->getKey(),
            'deleted',
            $this->entityLabel($model, $entityType),
            ['before' => $this->snapshot($model, $entityType)],
        );
    }

    /**
     * @param  list<array{model: Model, before: array<string, mixed>, after: array<string, mixed>}>  $rows
     */
    public function recordBulkPromptFlag(string $entityType, ?Authenticatable $user, array $rows): void
    {
        foreach ($rows as $row) {
            $model = $row['model'];
            $before = $row['before'];
            $after = $row['after'];
            if (($before['include_in_prompt'] ?? null) === ($after['include_in_prompt'] ?? null)) {
                continue;
            }

            $this->record(
                (int) $model->getAttribute('company_id'),
                $user !== null ? (int) $user->getAuthIdentifier() : null,
                $entityType,
                (int) $model->getKey(),
                'bulk_prompt',
                $this->entityLabel($model, $entityType),
                [
                    'field' => 'include_in_prompt',
                    'before' => $before['include_in_prompt'] ?? null,
                    'after' => $after['include_in_prompt'] ?? null,
                ],
            );
        }
    }

    /** @return array<string, mixed> */
    public function snapshot(Model $model, string $entityType): array
    {
        return match ($entityType) {
            'product' => $this->productSnapshot($model instanceof Product ? $model : throw new \InvalidArgumentException),
            'service' => $this->serviceSnapshot($model instanceof Service ? $model : throw new \InvalidArgumentException),
            'rule' => $this->ruleSnapshot($model instanceof KnowledgeRule ? $model : throw new \InvalidArgumentException),
            default => throw new \InvalidArgumentException('Unknown entity type: '.$entityType),
        };
    }

    private function entityLabel(Model $model, string $entityType): string
    {
        if ($entityType === 'rule') {
            return (string) ($model->getAttribute('title') ?? '');
        }

        return (string) ($model->getAttribute('name') ?? '');
    }

    /** @return array<string, mixed> */
    private function productSnapshot(Product $product): array
    {
        return [
            'company_id' => $product->company_id,
            'name' => $product->name,
            'sku' => $product->sku,
            'description' => $product->description,
            'price' => $product->price !== null ? (string) $product->price : null,
            'attributes' => $product->attributes,
            'is_active' => $product->is_active,
            'include_in_prompt' => $product->include_in_prompt,
            'sort_order' => $product->sort_order,
        ];
    }

    /** @return array<string, mixed> */
    private function serviceSnapshot(Service $service): array
    {
        return [
            'company_id' => $service->company_id,
            'name' => $service->name,
            'description' => $service->description,
            'duration_minutes' => $service->duration_minutes,
            'price' => $service->price !== null ? (string) $service->price : null,
            'conditions' => $service->conditions,
            'is_active' => $service->is_active,
            'include_in_prompt' => $service->include_in_prompt,
            'sort_order' => $service->sort_order,
        ];
    }

    /** @return array<string, mixed> */
    private function ruleSnapshot(KnowledgeRule $rule): array
    {
        return [
            'company_id' => $rule->company_id,
            'title' => $rule->title,
            'type' => $rule->type,
            'content' => $rule->content,
            'priority' => $rule->priority,
            'is_active' => $rule->is_active,
            'include_in_prompt' => $rule->include_in_prompt,
        ];
    }

    /**
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $after
     * @return array<string, array{0: mixed, 1: mixed}>
     */
    private function diffSnapshots(array $before, array $after): array
    {
        $keys = array_unique([...array_keys($before), ...array_keys($after)]);
        sort($keys);
        $diff = [];
        foreach ($keys as $key) {
            $a = $before[$key] ?? null;
            $b = $after[$key] ?? null;
            if (json_encode($a, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE) !== json_encode($b, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)) {
                $diff[$key] = [$a, $b];
            }
        }

        return $diff;
    }
}
