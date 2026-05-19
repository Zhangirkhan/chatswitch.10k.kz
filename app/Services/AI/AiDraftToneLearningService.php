<?php

declare(strict_types=1);

namespace App\Services\AI;

use App\Jobs\AnalyzeCompanyToneProfileJob;
use App\Jobs\AnalyzeEmployeeToneProfileJob;
use App\Models\AiResponseLog;
use App\Models\Chat;
use App\Models\User;
use App\Support\OperatorSignature;
use Illuminate\Support\Str;

final class AiDraftToneLearningService
{
    public const EDIT_PUNCTUATION = 'punctuation';

    public const EDIT_LIGHT = 'light';

    public const EDIT_HEAVY = 'heavy';

    private const MAX_DRAFT_AGE_HOURS = 4;

    /** Практически без изменений — обучение не запускаем. */
    private const UNCHANGED_SIMILARITY_THRESHOLD = 98.0;

    /** Лёгкая правка (в т.ч. только пунктуация) — обучение запускаем. */
    private const LIGHT_SIMILARITY_THRESHOLD = 88.0;

    /**
     * @return self::EDIT_*|null Тип правки, если нужно обновить профиль тона.
     */
    public function learnFromOutbound(User $user, Chat $chat, string $sentBody): ?string
    {
        $companyId = (int) ($chat->company_id ?? $user->company_id ?? 0);
        if ($companyId <= 0) {
            return null;
        }

        $log = AiResponseLog::query()
            ->where('chat_id', $chat->id)
            ->where('mode', 'draft')
            ->where('status', 'drafted')
            ->where('created_at', '>=', now()->subHours(self::MAX_DRAFT_AGE_HOURS))
            ->whereNull('metadata->draft_consumed_at')
            ->latest('id')
            ->first();

        if ($log === null) {
            return null;
        }

        $draft = data_get($log->metadata, 'draft_reply');
        if (! is_string($draft) || trim($draft) === '') {
            return null;
        }

        $sentNormalized = $this->normalizeForCompare($sentBody);
        $draftNormalized = $this->normalizeForCompare($draft);

        if ($sentNormalized === '' || $draftNormalized === '') {
            return null;
        }

        similar_text($sentNormalized, $draftNormalized, $similarity);
        $editKind = $this->classifyEdit(
            $sentNormalized,
            $draftNormalized,
            $similarity,
        );

        $metadata = is_array($log->metadata) ? $log->metadata : [];
        $metadata['draft_consumed_at'] = now()->toIso8601String();
        $metadata['draft_edit_similarity'] = round($similarity, 2);
        $metadata['draft_edit_kind'] = $editKind;
        $metadata['draft_was_edited'] = $editKind !== null;
        $log->forceFill(['metadata' => $metadata])->saveQuietly();

        if ($editKind === null) {
            return null;
        }

        AnalyzeEmployeeToneProfileJob::dispatch($user->id, $companyId, (int) $chat->id);
        AnalyzeCompanyToneProfileJob::dispatch($companyId);

        return $editKind;
    }

    private function classifyEdit(string $sentNormalized, string $draftNormalized, float &$similarity): ?string
    {
        if ($sentNormalized === $draftNormalized) {
            return null;
        }

        if ($this->normalizeCore($sentNormalized) === $this->normalizeCore($draftNormalized)) {
            return self::EDIT_PUNCTUATION;
        }

        if ($similarity >= self::UNCHANGED_SIMILARITY_THRESHOLD) {
            return null;
        }

        if ($similarity >= self::LIGHT_SIMILARITY_THRESHOLD) {
            return self::EDIT_LIGHT;
        }

        return self::EDIT_HEAVY;
    }

    private function normalizeForCompare(string $text): string
    {
        $text = OperatorSignature::strip($text);
        $text = Str::of($text)->lower()->squish()->toString();

        return $text;
    }

    private function normalizeCore(string $normalizedText): string
    {
        return (string) preg_replace('/[\p{P}\p{S}\s]+/u', '', $normalizedText);
    }
}
