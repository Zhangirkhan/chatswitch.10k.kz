<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1;

use App\Jobs\GenerateMessageMediaThumbnailJob;
use App\Models\Chat;
use App\Models\ChatAssignment;
use App\Models\Message;
use App\Models\MessageMedia;
use App\Models\User;
use App\Models\WhatsappSession;
use App\Services\MessageMediaThumbnailService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

final class MediaThumbnailApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        foreach (['administrator', 'manager', 'employee'] as $roleName) {
            Role::findOrCreate($roleName);
        }
    }

    public function test_message_payload_includes_thumb_url_for_images(): void
    {
        Bus::fake([GenerateMessageMediaThumbnailJob::class]);

        [$chat, , $media, $user] = $this->seedImageMessage();

        $token = $this->loginToken($user);

        $response = $this->withToken($token)->getJson("/api/v1/chats/{$chat->id}/messages?after_id=0");

        $response->assertOk();
        $response->assertJsonPath('messages.0.media.0.id', $media->id);
        $response->assertJsonPath('messages.0.media.0.thumb_url', url('/api/v1/media/'.$media->id.'/thumb'));
        $response->assertJsonPath('messages.0.media.0.file_name', 'photo.jpg');
    }

    public function test_non_image_media_has_null_thumb_url(): void
    {
        Bus::fake([GenerateMessageMediaThumbnailJob::class]);

        $user = User::factory()->create();
        $user->assignRole('employee');

        $session = WhatsappSession::factory()->create();
        $chat = Chat::factory()->create(['whatsapp_session_id' => $session->id]);

        ChatAssignment::create([
            'chat_id' => $chat->id,
            'user_id' => $user->id,
            'assigned_by' => $user->id,
        ]);

        $token = $this->loginToken($user);

        $message = Message::create([
            'chat_id' => $chat->id,
            'whatsapp_session_id' => $session->id,
            'direction' => 'inbound',
            'type' => 'document',
            'body' => '',
            'ack' => 'delivered',
            'message_timestamp' => now(),
        ]);

        Storage::disk('local')->put('whatsapp-media/2026/06/doc.pdf', '%PDF-1.4');

        MessageMedia::create([
            'message_id' => $message->id,
            'mime_type' => 'application/pdf',
            'filename' => 'doc.pdf',
            'disk_path' => 'whatsapp-media/2026/06/doc.pdf',
            'file_size' => 100,
        ]);

        $response = $this->withToken($token)->getJson("/api/v1/chats/{$chat->id}/messages?after_id=0");

        $response->assertOk();
        $response->assertJsonPath('messages.0.media.0.thumb_url', null);
    }

    public function test_thumb_endpoint_returns_webp_image(): void
    {
        Bus::fake([GenerateMessageMediaThumbnailJob::class]);

        [, , $media, $user] = $this->seedImageMessage(width: 1200, height: 800);
        $token = $this->loginToken($user);

        app(MessageMediaThumbnailService::class)->generate($media->fresh());

        $response = $this->withToken($token)->get('/api/v1/media/'.$media->id.'/thumb');

        $response->assertOk();
        $response->assertHeader('Content-Type', 'image/webp');
        $this->assertLessThanOrEqual(50 * 1024, strlen((string) $response->getContent()));
    }

    public function test_thumb_endpoint_generates_on_demand_when_missing(): void
    {
        Bus::fake([GenerateMessageMediaThumbnailJob::class]);

        [, , $media, $user] = $this->seedImageMessage();
        $token = $this->loginToken($user);

        $this->assertNull($media->fresh()->thumb_disk_path);

        $response = $this->withToken($token)->get('/api/v1/media/'.$media->id.'/thumb');

        $response->assertOk();
        $response->assertHeader('Content-Type', 'image/webp');
        $this->assertNotNull($media->fresh()->thumb_disk_path);
    }

    /**
     * @return array{0: Chat, 1: Message, 2: MessageMedia, 3: User}
     */
    private function seedImageMessage(int $width = 800, int $height = 600): array
    {
        $user = User::factory()->create();
        $user->assignRole('employee');

        $session = WhatsappSession::factory()->create();
        $chat = Chat::factory()->create(['whatsapp_session_id' => $session->id]);

        ChatAssignment::create([
            'chat_id' => $chat->id,
            'user_id' => $user->id,
            'assigned_by' => $user->id,
        ]);

        $message = Message::create([
            'chat_id' => $chat->id,
            'whatsapp_session_id' => $session->id,
            'direction' => 'inbound',
            'type' => 'image',
            'body' => '',
            'ack' => 'delivered',
            'message_timestamp' => now(),
        ]);

        $image = imagecreatetruecolor($width, $height);
        $color = imagecolorallocate($image, 40, 120, 200);
        imagefilledrectangle($image, 0, 0, $width - 1, $height - 1, $color);

        ob_start();
        imagejpeg($image, null, 85);
        $jpeg = ob_get_clean();
        imagedestroy($image);

        $path = 'whatsapp-media/2026/06/photo.jpg';
        Storage::disk('local')->put($path, $jpeg);

        $media = MessageMedia::create([
            'message_id' => $message->id,
            'mime_type' => 'image/jpeg',
            'filename' => 'photo.jpg',
            'disk_path' => $path,
            'file_size' => strlen($jpeg),
        ]);

        return [$chat, $message, $media, $user];
    }

    private function loginToken(User $user): string
    {
        return (string) $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => 'password',
        ])->assertOk()->json('token');
    }
}
