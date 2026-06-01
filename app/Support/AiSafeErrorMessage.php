<?php

declare(strict_types=1);

namespace App\Support;

final class AiSafeErrorMessage
{
    public static function forUser(?string $error, bool $isAdministrator = false): string
    {
        $error = trim((string) $error);
        if ($error === '') {
            return 'AI не смог подготовить ответ. Попробуйте ещё раз или ответьте вручную.';
        }

        $lower = mb_strtolower($error);

        if (self::isQuotaExceeded($lower)) {
            return $isAdministrator
                ? 'Исчерпана квота OpenAI (billing). Пополните баланс на platform.openai.com и проверьте OPENAI_API_KEY.'
                : 'AI временно недоступен из‑за лимита сервиса. Сообщите администратору или ответьте клиенту вручную.';
        }

        if (str_contains($lower, 'sqlstate') || str_contains($lower, 'base table') || str_contains($lower, 'table')) {
            return 'AI временно недоступен. Администратору нужно проверить настройки базы данных и миграции.';
        }

        if (str_contains($lower, 'openai_api_key') || str_contains($lower, 'api_key не задан')) {
            return $isAdministrator
                ? 'Не задан OPENAI_API_KEY в .env (services.openai.api_key).'
                : 'AI-сервис не настроен. Сообщите администратору.';
        }

        if (str_contains($lower, 'openai') || str_contains($lower, 'api') || str_contains($lower, 'timeout')) {
            return 'AI-сервис временно недоступен. Попробуйте ещё раз позже или ответьте вручную.';
        }

        if (str_contains($lower, 'safety')) {
            return 'AI остановил ответ из-за проверки безопасности. Ответьте клиенту вручную.';
        }

        return 'AI не смог подготовить ответ. Попробуйте ещё раз или ответьте вручную.';
    }

    public static function isQuotaExceeded(string $lowerError): bool
    {
        return str_contains($lowerError, 'insufficient_quota')
            || str_contains($lowerError, 'exceeded your current quota')
            || str_contains($lowerError, 'billing details');
    }
}
