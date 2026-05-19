<?php

declare(strict_types=1);

namespace Tests\Unit\AI;

use App\Models\Chat;
use App\Models\WhatsappSession;
use App\Services\AI\WhatsappAiTypingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class WhatsappAiTypingServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.whatsapp.url' => 'http://whatsapp.test',
            'services.whatsapp.token' => 'test-token',
        ]);
    }

    public function test_while_generating_sets_and_clears_typing(): void
    {
        Cache::flush();
        Http::fake(['http://whatsapp.test/*' => Http::response(['success' => true])]);

        $session = WhatsappSession::factory()->create();
        $chat = Chat::factory()->create([
            'whatsapp_session_id' => $session->id,
            'whatsapp_chat_id' => '77010000001@c.us',
            'is_group' => false,
        ]);

        $service = app(WhatsappAiTypingService::class);
        $result = $service->whileGenerating($chat, static fn (): string => 'ok');

        $this->assertSame('ok', $result);
        Http::assertSentCount(2);
        Http::assertSent(fn ($request): bool => $request->url() === 'http://whatsapp.test/api/set-typing'
            && $request['isTyping'] === true);
        Http::assertSent(fn ($request): bool => $request->url() === 'http://whatsapp.test/api/set-typing'
            && $request['isTyping'] === false);
    }

    public function test_skips_typing_for_group_chats(): void
    {
        Http::fake();
        $session = WhatsappSession::factory()->create();
        $chat = Chat::factory()->create([
            'whatsapp_session_id' => $session->id,
            'is_group' => true,
        ]);

        app(WhatsappAiTypingService::class)->refresh($chat);

        Http::assertNothingSent();
    }
}
