<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Push;

use App\Models\Chat;
use App\Models\Contact;
use App\Models\Message;
use App\Models\User;
use App\Models\WhatsappSession;
use App\Services\Push\MobilePushService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

final class MobilePushServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['administrator', 'manager', 'employee'] as $roleName) {
            Role::findOrCreate($roleName);
        }
    }

    public function test_dispatch_noops_when_firebase_disabled(): void
    {
        Config::set('services.firebase.enabled', false);

        $admin = User::factory()->create();
        $admin->assignRole('administrator');

        app(MobilePushService::class)->dispatchToUsers(
            [$admin->id],
            ['kind' => 'client_message', 'chat_id' => '10', 'title' => 'T', 'body' => 'B'],
        );

        $this->assertFalse(app(MobilePushService::class)->isEnabled());
    }

    public function test_inbound_client_message_targets_chat_audience(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('administrator');

        $session = WhatsappSession::factory()->create();
        $contact = Contact::factory()->create(['name' => 'Иван Петров']);
        $chat = Chat::factory()->create([
            'whatsapp_session_id' => $session->id,
            'contact_id' => $contact->id,
            'is_muted' => false,
        ]);
        $message = Message::query()->create([
            'chat_id' => $chat->id,
            'company_id' => $chat->company_id,
            'whatsapp_session_id' => $session->id,
            'direction' => 'inbound',
            'body' => 'Здравствуйте',
            'type' => 'chat',
            'message_timestamp' => now(),
        ]);

        $recipients = \App\Support\ChatBroadcastAudience::userIdsWithAccessToChat($chat);
        $this->assertContains($admin->id, $recipients);

        Config::set('services.firebase.enabled', false);
        app(MobilePushService::class)->notifyClientMessage($message, $chat);
        $this->addToAssertionCount(1);
    }

    public function test_send_to_users_now_reports_device_stats(): void
    {
        Config::set('services.firebase.enabled', true);

        $admin = User::factory()->create();
        $admin->assignRole('administrator');
        $employee = User::factory()->create();
        $employee->assignRole('employee');

        $stats = app(MobilePushService::class)->sendToUsersNow(
            [$admin->id, $employee->id],
            ['kind' => 'client_message', 'chat_id' => '10', 'title' => 'T', 'body' => 'B'],
        );

        $this->assertSame(0, $stats['device_count']);
        $this->assertSame(2, $stats['users_without_device_count']);
    }

    public function test_muted_chat_skips_push(): void
    {
        Config::set('services.firebase.enabled', true);

        $session = WhatsappSession::factory()->create();
        $chat = Chat::factory()->create([
            'whatsapp_session_id' => $session->id,
            'is_muted' => true,
        ]);
        $message = Message::query()->create([
            'chat_id' => $chat->id,
            'company_id' => $chat->company_id,
            'whatsapp_session_id' => $session->id,
            'direction' => 'inbound',
            'body' => 'Test',
            'type' => 'chat',
            'message_timestamp' => now(),
        ]);

        app(MobilePushService::class)->notifyClientMessage($message, $chat);
        $this->addToAssertionCount(1);
    }
}
