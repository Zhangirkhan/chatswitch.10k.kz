<?php

declare(strict_types=1);

namespace App\Support;

final class ClientProfileFieldHelper
{
    /**
     * @param  list<array{label: string, value: string, source: string}>  $fields
     * @param  array{label: string, value: string, source: string}  $candidate
     */
    public static function isDuplicate(array $fields, array $candidate): bool
    {
        $label = trim($candidate['label']);
        $value = self::normalizeValue($candidate['value']);

        if ($value === '') {
            return false;
        }

        foreach ($fields as $field) {
            $existingLabel = trim((string) ($field['label'] ?? ''));
            $existingValue = self::normalizeValue((string) ($field['value'] ?? ''));

            if ($existingValue === '') {
                continue;
            }

            if ($existingLabel === $label) {
                return true;
            }

            if ($existingValue === $value) {
                return true;
            }

            if (self::valuesOverlap($existingValue, $value)) {
                return true;
            }

            if (self::labelsAreSameConcept($existingLabel, $label)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<array{label: string, value: string, source: string}>  $fields
     * @param  list<array{label: string, value: string, source: string}>  $candidates
     * @return list<array{label: string, value: string, source: string}>
     */
    public static function mergeUnique(array $fields, array $candidates): array
    {
        foreach ($candidates as $candidate) {
            if (! is_array($candidate)) {
                continue;
            }

            $label = trim((string) ($candidate['label'] ?? ''));
            $value = trim((string) ($candidate['value'] ?? ''));
            if ($label === '' || $value === '') {
                continue;
            }

            $normalized = [
                'label' => $label,
                'value' => $value,
                'source' => (string) ($candidate['source'] ?? 'crm'),
            ];

            if (! self::isDuplicate($fields, $normalized)) {
                $fields[] = $normalized;
            }
        }

        return $fields;
    }

    private static function normalizeValue(string $value): string
    {
        $normalized = mb_strtolower(trim($value));
        $normalized = preg_replace('/\s+/u', ' ', $normalized) ?? $normalized;

        return $normalized;
    }

    private static function valuesOverlap(string $left, string $right): bool
    {
        if ($left === $right) {
            return true;
        }

        if (mb_strlen($left) >= 4 && mb_strlen($right) >= 4) {
            return str_contains($left, $right) || str_contains($right, $left);
        }

        return false;
    }

    private static function labelsAreSameConcept(string $left, string $right): bool
    {
        $groups = [
            ['имя', 'name', 'как обращаться', 'клиент'],
            ['адрес', 'address', 'локация', 'location'],
            ['город', 'city'],
            ['район', 'district'],
            ['этап', 'воронк', 'сделк', 'stage', 'funnel'],
        ];

        $leftLower = mb_strtolower($left);
        $rightLower = mb_strtolower($right);

        foreach ($groups as $keywords) {
            $leftHit = self::labelMatchesAny($leftLower, $keywords);
            $rightHit = self::labelMatchesAny($rightLower, $keywords);

            if ($leftHit && $rightHit) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<string>  $keywords
     */
    private static function labelMatchesAny(string $label, array $keywords): bool
    {
        foreach ($keywords as $keyword) {
            if (str_contains($label, $keyword)) {
                return true;
            }
        }

        return false;
    }
}
