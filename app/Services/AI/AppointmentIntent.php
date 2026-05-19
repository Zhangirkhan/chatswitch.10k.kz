<?php

declare(strict_types=1);

namespace App\Services\AI;

final readonly class AppointmentIntent
{
    /**
     * @param  list<string>  $missingFields
     */
    public function __construct(
        public bool $isAppointmentRequest,
        public bool $hasExplicitConfirmation,
        public ?string $serviceName,
        public ?string $startsAt,
        public ?int $durationMinutes,
        public string $clientReply,
        public array $missingFields = [],
        public ?string $clientNote = null,
        public ?int $reminderLeadMinutes = null,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $missing = $data['missing_fields'] ?? [];
        if (! is_array($missing)) {
            $missing = [];
        }

        return new self(
            isAppointmentRequest: (bool) ($data['is_appointment_request'] ?? false),
            hasExplicitConfirmation: (bool) ($data['has_explicit_confirmation'] ?? false),
            serviceName: self::nullableString($data['service_name'] ?? null),
            startsAt: self::nullableString($data['starts_at'] ?? null),
            durationMinutes: self::positiveInt($data['duration_minutes'] ?? null),
            clientReply: trim((string) ($data['client_reply'] ?? '')),
            missingFields: array_values(array_filter(array_map(
                static fn (mixed $field): ?string => is_string($field) && trim($field) !== '' ? trim($field) : null,
                $missing,
            ))),
            clientNote: self::nullableString($data['client_note'] ?? null),
            reminderLeadMinutes: self::positiveInt($data['reminder_lead_minutes'] ?? null),
        );
    }

    public function isComplete(): bool
    {
        return $this->isAppointmentRequest
            && $this->hasExplicitConfirmation
            && $this->serviceName !== null
            && $this->startsAt !== null;
    }

    private static function nullableString(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value !== '' ? $value : null;
    }

    private static function positiveInt(mixed $value): ?int
    {
        if (! is_numeric($value)) {
            return null;
        }

        $value = (int) $value;

        return $value > 0 ? $value : null;
    }
}
