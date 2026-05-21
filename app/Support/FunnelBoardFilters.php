<?php

declare(strict_types=1);

namespace App\Support;

final class FunnelBoardFilters
{
    public function __construct(
        public readonly string $scope = 'all',
        public readonly ?int $assigneeId = null,
        public readonly ?int $departmentId = null,
        public readonly ?int $whatsappSessionId = null,
        public readonly ?string $search = null,
    ) {}

    /**
     * @param  array<string, mixed>  $input
     */
    public static function fromRequest(array $input): self
    {
        $scope = (string) ($input['scope'] ?? 'all');
        if (! in_array($scope, ['all', 'mine', 'department'], true)) {
            $scope = 'all';
        }

        $search = isset($input['search']) ? trim((string) $input['search']) : null;
        if ($search === '') {
            $search = null;
        }

        return new self(
            scope: $scope,
            assigneeId: isset($input['assignee_id']) && $input['assignee_id'] !== ''
                ? (int) $input['assignee_id']
                : null,
            departmentId: isset($input['department_id']) && $input['department_id'] !== ''
                ? (int) $input['department_id']
                : null,
            whatsappSessionId: isset($input['whatsapp_session_id']) && $input['whatsapp_session_id'] !== ''
                ? (int) $input['whatsapp_session_id']
                : null,
            search: $search,
        );
    }

    /**
     * @return array<string, int|string|null>
     */
    public function toQueryParams(): array
    {
        return array_filter([
            'scope' => $this->scope,
            'assignee_id' => $this->assigneeId,
            'department_id' => $this->departmentId,
            'whatsapp_session_id' => $this->whatsappSessionId,
            'search' => $this->search,
        ], static fn ($value) => $value !== null && $value !== '');
    }
}
