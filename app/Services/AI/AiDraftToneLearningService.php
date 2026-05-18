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
    private const MAX_DRAFT_AGE_HOURS = 4;

    /** Ниже порога считаем, что черновик существенно отредактирован. */
    private const EDITED_SIMILARITY_THRESHOLD = 0.88;

    public function learnFromOutbound(User $user, Chat $chat, string $sentBody): bool
    {
        $companyId = (int) ($chat->company_id ?? $user->company_id ?? 0);
        if ($companyId <= 0) {
            return false;
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
            return false;
        }

        $draft = data_get($log->metadata, 'draft_reply');
        if (! is_string($draft) || trim($draft) === '') {
            return false;
        }

        $sentNormalized = $this->normalizeForCompare($sentBody);
        $draftNormalized = $this->normalizeForCompare($draft);

        if ($sentNormalized === '' || $draftNormalized === '') {
            return false;
        }

        similar_text($sentNormalized, $draftNormalized, $similarity);
        $edited = $similarity < (self::EDITED_SIMILARITY_THRESHOLD * 100);

        $metadata = is_array($log->metadata) ? $log->metadata : [];
        $metadata['draft_consumed_at'] = now()->toIso8601String();
        $metadata['draft_edit_similarity'] = round($similarity, 2);
        $metadata['draft_was_edited'] = $edited;
        $log->forceFill(['metadata' => $metadata])->saveQuietly();

        if (! $edited) {
            return false;
        }

        AnalyzeEmployeeToneProfileJob::dispatch($user->id, $companyId, (int) $chat->id);
        AnalyzeCompanyToneProfileJob::dispatch($companyId);

        return true;
    }

    private function normalizeForCompare(string $text): string
    {
        $text = OperatorSignature::strip($text);
        $text = Str::of($text)->lower()->squish()->toString();

        return $text;
    }
}
