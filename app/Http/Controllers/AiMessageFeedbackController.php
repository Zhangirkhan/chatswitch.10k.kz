<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\AiMessageRating;
use App\Models\Message;
use App\Models\SystemSetting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;

final class AiMessageFeedbackController extends Controller
{
    public function store(Request $request, Message $message): JsonResponse
    {
        abort_unless(
            SystemSetting::getValue('module_ai_quality', 'on') === 'on',
            403,
            'Модуль «AI и качество» отключён администратором.',
        );

        if (! Schema::hasTable('ai_message_ratings')) {
            abort(503, 'Оценка AI временно недоступна.');
        }

        $message->loadMissing('chat');
        if ($message->chat === null) {
            abort(404);
        }

        $this->authorize('view', $message->chat);

        $data = $request->validate([
            'rating' => ['required', 'string', 'in:good,style,facts,long,context'],
        ]);

        $metadata = $message->metadata;
        if (! is_array($metadata) || ($metadata['ai']['generated'] ?? false) !== true) {
            abort(422, 'Оценка доступна только для сообщений, созданных AI.');
        }

        $user = $request->user();
        AiMessageRating::query()->updateOrCreate(
            [
                'message_id' => $message->id,
                'user_id' => $user->id,
            ],
            [
                'rating' => $data['rating'],
            ],
        );

        return response()->json(['success' => true]);
    }
}
