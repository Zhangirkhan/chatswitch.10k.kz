<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Chat;
use App\Services\AI\ChatClientLanguageService;
use App\Services\AI\MessageTranslationService;
use App\Support\MessageLanguageHeuristics;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class ChatDraftTranslationController extends Controller
{
    public function __construct(
        private readonly ChatClientLanguageService $clientLanguage,
        private readonly MessageTranslationService $translation,
    ) {}

    public function translate(Request $request, Chat $chat): JsonResponse
    {
        $this->authorize('view', $chat);

        $validated = $request->validate([
            'text' => ['required', 'string', 'max:4000'],
            'lang' => ['nullable', 'string', 'in:'.implode(',', MessageLanguageHeuristics::SUPPORTED)],
        ]);

        $text = trim($validated['text']);
        if ($text === '') {
            return response()->json(['translation' => '', 'target_lang' => null, 'target_label' => null]);
        }

        $targetLang = $validated['lang'] ?? $this->clientLanguage->resolveOutgoingTarget($chat, $text);
        if ($targetLang === null || MessageLanguageHeuristics::matches($targetLang, $text)) {
            return response()->json([
                'translation' => $text,
                'target_lang' => $targetLang,
                'target_label' => $targetLang !== null ? MessageLanguageHeuristics::LABELS[$targetLang] : null,
                'unchanged' => true,
            ]);
        }

        try {
            $translation = $this->translation->translate($text, $targetLang, $chat->company_id);
        } catch (\Throwable) {
            return response()->json(['error' => 'Сервис перевода недоступен.'], 503);
        }

        return response()->json([
            'translation' => $translation,
            'target_lang' => $targetLang,
            'target_label' => MessageLanguageHeuristics::LABELS[$targetLang],
            'unchanged' => trim($translation) === $text,
        ]);
    }
}
