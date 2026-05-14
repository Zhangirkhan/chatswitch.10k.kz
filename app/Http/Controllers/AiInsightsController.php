<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\AiMessageRating;
use App\Models\AiResponseLog;
use App\Models\SystemSetting;
use Illuminate\Support\Facades\Schema;
use Inertia\Inertia;
use Inertia\Response;

final class AiInsightsController extends Controller
{
    public function index(): Response
    {
        abort_unless(
            SystemSetting::getValue('module_ai_quality', 'on') === 'on',
            403,
            'Модуль «AI и качество» отключён администратором.',
        );

        $failedLogs = [];
        if (Schema::hasTable('ai_response_logs')) {
            $failedLogs = AiResponseLog::query()
                ->whereIn('status', ['failed', 'blocked'])
                ->with(['chat:id,chat_name', 'company:id,name'])
                ->orderByDesc('created_at')
                ->limit(50)
                ->get()
                ->map(static fn (AiResponseLog $log): array => [
                    'id' => $log->id,
                    'created_at' => $log->created_at?->toIso8601String(),
                    'status' => $log->status,
                    'mode' => $log->mode,
                    'error' => $log->error,
                    'chat' => $log->chat?->chat_name ?? 'Чат #'.$log->chat_id,
                    'company' => $log->company?->name,
                ])
                ->values()
                ->all();
        }

        $problemRatings = [];
        if (Schema::hasTable('ai_message_ratings')) {
            $problemRatings = AiMessageRating::query()
                ->whereIn('rating', ['style', 'facts', 'long', 'context'])
                ->with([
                    'user:id,name',
                    'message' => static function ($query): void {
                        $query->select('id', 'chat_id', 'body')
                            ->with(['chat:id,chat_name']);
                    },
                ])
                ->orderByDesc('created_at')
                ->limit(50)
                ->get()
                ->map(static function (AiMessageRating $row): array {
                    $body = (string) ($row->message?->body ?? '');
                    $preview = mb_strlen($body) > 160 ? mb_substr($body, 0, 160).'…' : $body;

                    return [
                        'id' => $row->id,
                        'rating' => $row->rating,
                        'created_at' => $row->created_at?->toIso8601String(),
                        'user' => $row->user?->name,
                        'chat' => $row->message?->chat?->chat_name ?? 'Чат',
                        'body_preview' => $preview,
                    ];
                })
                ->values()
                ->all();
        }

        return Inertia::render('Settings/AiQuality', [
            'failed_logs' => $failedLogs,
            'problem_ratings' => $problemRatings,
        ]);
    }
}
