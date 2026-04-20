<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Message;
use App\Models\MessageReaction;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class MessageController extends Controller
{
    public function react(Request $request, Message $message): JsonResponse
    {
        $data = $request->validate([
            'emoji' => 'required|string|max:16',
        ]);

        $user = $request->user();
        $emoji = $data['emoji'];
        $normalizedNew = $this->normalizeEmoji($emoji);

        $existing = MessageReaction::where('message_id', $message->id)
            ->where('user_id', $user->id)
            ->first();

        if ($existing && $this->normalizeEmoji($existing->emoji) === $normalizedNew) {
            $existing->delete();
        } else {
            MessageReaction::updateOrCreate(
                ['message_id' => $message->id, 'user_id' => $user->id],
                ['emoji' => $emoji],
            );
        }

        $reactions = MessageReaction::with('user:id,name')
            ->where('message_id', $message->id)
            ->get();

        return response()->json([
            'success' => true,
            'reactions' => $reactions,
        ]);
    }

    private function normalizeEmoji(string $emoji): string
    {
        return preg_replace('/[\x{FE0F}\x{200D}]/u', '', $emoji) ?? $emoji;
    }

    public function destroy(Request $request, Message $message): JsonResponse
    {
        $user = $request->user();

        if ($message->direction === 'outbound' && $message->sent_by_user_id !== $user->id && ! $user->hasRole('administrator')) {
            abort(403);
        }

        $message->reactions()->delete();
        $message->media()->delete();
        $message->delete();

        return response()->json(['success' => true]);
    }
}
