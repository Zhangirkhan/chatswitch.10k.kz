<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Http\Request;

final class ContactListFilters
{
    /**
     * @param  array<string, string>  $values  field code => trimmed filter value
     */
    public function __construct(
        public readonly array $values = [],
    ) {}

    public static function fromRequest(Request $request): self
    {
        $raw = $request->input('filters', []);
        if (! is_array($raw)) {
            return new self([]);
        }

        $values = [];
        foreach ($raw as $code => $value) {
            if (! is_string($code) || $code === '') {
                continue;
            }
            $normalized = trim(is_scalar($value) ? (string) $value : '');
            if ($normalized === '') {
                continue;
            }
            $values[$code] = $normalized;
        }

        return new self($values);
    }

    public function isEmpty(): bool
    {
        return $this->values === [];
    }

    public function get(string $code): ?string
    {
        return $this->values[$code] ?? null;
    }

    /**
     * @return array<string, string>
     */
    public function toQueryParams(): array
    {
        if ($this->values === []) {
            return [];
        }

        return ['filters' => $this->values];
    }
}
