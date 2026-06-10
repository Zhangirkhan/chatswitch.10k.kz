<?php

declare(strict_types=1);

namespace App\Support;

final class ClientMessageHeuristics
{
    public static function usedGreeting(string $body): bool
    {
        $body = mb_strtolower(trim($body));

        return str_contains($body, 'здравств')
            || str_contains($body, 'добрый')
            || str_contains($body, 'доброе')
            || str_contains($body, 'привет')
            || str_contains($body, 'салам')
            || str_contains($body, 'сәлем')
            || str_contains($body, 'салем')
            || str_contains($body, 'саламат')
            || str_contains($body, 'сәлемет')
            || str_contains($body, 'салемет')
            || str_contains($body, 'ассалаума')
            || str_contains($body, 'assalaum')
            || str_contains($body, 'assalamu')
            || str_contains($body, 'assalauma')
            || str_contains($body, 'hello')
            || str_contains($body, 'hi ');
    }

    public static function isShortGreetingOnly(string $body): bool
    {
        $trimmed = trim($body);

        return self::usedGreeting($trimmed) && mb_strlen($trimmed) <= 40;
    }

    public static function isGenericStubReply(?string $reply): bool
    {
        if ($reply === null || trim($reply) === '') {
            return true;
        }

        $text = mb_strtolower(trim($reply));

        return str_contains($text, 'уточню информацию и вернусь')
            || str_contains($text, 'понял вас. уточню')
            || str_contains($text, 'вернусь с ответом')
            || str_contains($text, 'спасибо за интерес')
            || str_contains($text, 'как я могу вам помочь');
    }
}
