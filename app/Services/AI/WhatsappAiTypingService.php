<?php

declare(strict_types=1);

namespace App\Services\AI;

use App\Models\Chat;
use App\Services\WhatsappService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Показывает «печатает…» в WhatsApp на время генерации AI (с периодическим обновлением).
 */
final class WhatsappAiTypingService
{
    public function __construct(
        private readonly WhatsappService $whatsapp,
    ) {}

    public function refresh(Chat $chat): void
    {
        if ($chat->is_group) {
            return;
        }

        $chat->loadMissing('whatsappSession');
        $session = $chat->whatsappSession;
        $chatId = trim((string) $chat->whatsapp_chat_id);
        if ($session === null || $chatId === '') {
            return;
        }

        $throttleKey = 'whatsapp-ai-typing:'.$chat->id;
        $refreshSeconds = max(5, (int) config('ai.typing_refresh_seconds', 12));
        $lastRefresh = Cache::get($throttleKey);
        if (is_int($lastRefresh) && (time() - $lastRefresh) < $refreshSeconds) {
            return;
        }

        Cache::put($throttleKey, time(), $refreshSeconds * 3);

        try {
            $this->whatsapp->setTyping($session->session_name, $chatId, true);
        } catch (Throwable $e) {
            Log::debug('[ai-typing] setTyping failed', [
                'chat_id' => $chat->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    public function stop(Chat $chat): void
    {
        if ($chat->is_group) {
            return;
        }

        Cache::forget('whatsapp-ai-typing:'.$chat->id);

        $chat->loadMissing('whatsappSession');
        $session = $chat->whatsappSession;
        $chatId = trim((string) $chat->whatsapp_chat_id);
        if ($session === null || $chatId === '') {
            return;
        }

        try {
            $this->whatsapp->setTyping($session->session_name, $chatId, false);
        } catch (Throwable $e) {
            Log::debug('[ai-typing] clearState failed', [
                'chat_id' => $chat->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public function whileGenerating(Chat $chat, callable $callback): mixed
    {
        $this->refresh($chat);

        try {
            return $callback();
        } finally {
            $this->stop($chat);
        }
    }
}
