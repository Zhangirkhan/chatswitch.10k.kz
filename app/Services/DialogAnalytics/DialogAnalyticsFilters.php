<?php

declare(strict_types=1);

namespace App\Services\DialogAnalytics;

use Carbon\Carbon;

final readonly class DialogAnalyticsFilters
{
    public function __construct(
        public Carbon $from,
        public Carbon $to,
        public ?int $employeeId,
        public ?int $departmentId,
        public string $status,
        public string $channel,
        public int $page,
        public int $perPage,
    ) {}

    /** @return array<string, scalar|null> */
    public function cacheKeyPayload(): array
    {
        return [
            'from' => $this->from->toIso8601String(),
            'to' => $this->to->toIso8601String(),
            'employee_id' => $this->employeeId,
            'department_id' => $this->departmentId,
            'status' => $this->status,
            'channel' => $this->channel,
            'page' => $this->page,
            'per_page' => $this->perPage,
        ];
    }
}
