<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\AiUsageEvent;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class AiUsageController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        if ($user === null || ! $user->hasRole('administrator')) {
            abort(403);
        }

        $days = max(1, min(365, (int) $request->query('period', 30)));
        $since = now()->subDays($days);

        $rows = AiUsageEvent::query()
            ->select([
                'scenario',
                'kind',
                DB::raw('COUNT(*) as events_count'),
                DB::raw('COALESCE(SUM(tokens_input), 0) as tokens_input'),
                DB::raw('COALESCE(SUM(tokens_output), 0) as tokens_output'),
                DB::raw('COALESCE(SUM(audio_seconds), 0) as audio_seconds'),
            ])
            ->when($user->company_id, fn ($q) => $q->where('company_id', $user->company_id))
            ->where('created_at', '>=', $since)
            ->groupBy('scenario', 'kind')
            ->orderByDesc('events_count')
            ->get()
            ->map(static fn ($row): array => [
                'scenario' => (string) $row->scenario,
                'kind' => (string) $row->kind,
                'events_count' => (int) $row->events_count,
                'tokens_input' => (int) $row->tokens_input,
                'tokens_output' => (int) $row->tokens_output,
                'audio_seconds' => (int) $row->audio_seconds,
            ])
            ->values()
            ->all();

        $dictationSeconds = AiUsageEvent::query()
            ->when($user->company_id, fn ($q) => $q->where('company_id', $user->company_id))
            ->where('scenario', 'operator_dictation')
            ->where('kind', 'whisper')
            ->where('created_at', '>=', $since)
            ->sum('audio_seconds');

        return response()->json([
            'period_days' => $days,
            'scenarios' => $rows,
            'operator_dictation_seconds' => (int) $dictationSeconds,
        ]);
    }
}
