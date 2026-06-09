<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1;

use App\Jobs\SendOutboundMessageJob;
use App\Models\Chat;
use App\Models\ChatAssignment;
use App\Models\User;
use App\Models\WhatsappSession;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

final class ChatVoiceUploadApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['administrator', 'manager', 'employee'] as $roleName) {
            Role::findOrCreate($roleName);
        }
    }

    public function test_upload_voice_m4a_from_ios_is_accepted(): void
    {
        Bus::fake([SendOutboundMessageJob::class]);

        $user = User::factory()->create();
        $user->assignRole('employee');

        $session = WhatsappSession::factory()->create();
        $chat = Chat::factory()->create(['whatsapp_session_id' => $session->id]);

        ChatAssignment::create([
            'chat_id' => $chat->id,
            'user_id' => $user->id,
            'assigned_by' => $user->id,
        ]);

        $token = $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => 'password',
        ])->assertOk()->json('token');

        $response = $this->withToken($token)->call(
            'POST',
            "/api/v1/chats/{$chat->id}/upload",
            ['type' => 'voice'],
            [],
            ['file' => UploadedFile::fake()->create('voice.m4a', 120, 'audio/x-m4a')],
            ['HTTP_ACCEPT' => 'application/json'],
        );

        $response->assertOk();
        $response->assertJsonPath('success', true);
        Bus::assertDispatched(SendOutboundMessageJob::class);
    }

    public function test_upload_voice_rejects_unsupported_format_with_friendly_message(): void
    {
        Bus::fake([SendOutboundMessageJob::class]);

        $user = User::factory()->create();
        $user->assignRole('employee');

        $session = WhatsappSession::factory()->create();
        $chat = Chat::factory()->create(['whatsapp_session_id' => $session->id]);

        ChatAssignment::create([
            'chat_id' => $chat->id,
            'user_id' => $user->id,
            'assigned_by' => $user->id,
        ]);

        $token = $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => 'password',
        ])->assertOk()->json('token');

        $response = $this->withToken($token)->call(
            'POST',
            "/api/v1/chats/{$chat->id}/upload",
            ['type' => 'voice'],
            [],
            ['file' => UploadedFile::fake()->create('bad.exe', 10, 'application/x-msdownload')],
            ['HTTP_ACCEPT' => 'application/json'],
        );

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors(['file']);
        $this->assertStringContainsString(
            'Сервер не принимает этот формат файла',
            (string) $response->json('errors.file.0'),
        );
    }
}
