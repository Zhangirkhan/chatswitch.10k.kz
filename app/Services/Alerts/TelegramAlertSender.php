<?php

declare(strict_types=1);

namespace App\Services\Alerts;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

final class TelegramAlertSender
{
    public function configured(): bool
    {
        return $this->botToken() !== '' && $this->chatId() !== '';
    }

    public function send(string $message): bool
    {
        if (! $this->configured()) {
            return false;
        }

        try {
            $response = Http::timeout(8)->post(
                'https://api.telegram.org/bot'.$this->botToken().'/sendMessage',
                [
                    'chat_id' => $this->chatId(),
                    'text' => $message,
                    'parse_mode' => 'HTML',
                    'disable_web_page_preview' => true,
                ],
            );

            if (! $response->successful()) {
                Log::warning('[telegram-alert] send failed', [
                    'status' => $response->status(),
                    'body' => mb_substr((string) $response->body(), 0, 500),
                ]);

                return false;
            }

            return true;
        } catch (\Throwable $e) {
            Log::warning('[telegram-alert] send exception', ['error' => $e->getMessage()]);

            return false;
        }
    }

    private function botToken(): string
    {
        return trim((string) config('accel.whatsapp_alerts.telegram_bot_token', ''));
    }

    private function chatId(): string
    {
        return trim((string) config('accel.whatsapp_alerts.telegram_chat_id', ''));
    }
}
