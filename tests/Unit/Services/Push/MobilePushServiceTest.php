<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Push;

use App\Jobs\SendMobilePushJob;
use App\Models\Chat;
use App\Models\Contact;
use App\Models\Message;
use App\Models\User;
use App\Models\WhatsappSession;
use App\Services\Push\MobilePushService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
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

    public function test_dispatch_to_users_queues_push_job_when_enabled(): void
    {
        Config::set('services.firebase.enabled', true);
        Queue::fake();

        $admin = User::factory()->create();
        $admin->assignRole('administrator');

        app(MobilePushService::class)->dispatchToUsers(
            [$admin->id],
            [
                'kind' => 'client_message',
                'chat_id' => '10',
                'title' => 'Иван Петров',
                'body' => 'Здравствуйте',
                'message_id' => '99',
            ],
        );

        Queue::assertPushed(SendMobilePushJob::class, function (SendMobilePushJob $job) use ($admin): bool {
            return in_array($admin->id, $job->userIds, true)
                && $job->data['kind'] === 'client_message'
                && $job->data['chat_id'] === '10';
        });
    }

    public function test_inbound_client_message_dispatches_push_job(): void
    {
        Config::set('services.firebase.enabled', true);
        Queue::fake();

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

        app(MobilePushService::class)->notifyClientMessage($message, $chat);

        Queue::assertPushed(SendMobilePushJob::class);
    }

    public function test_muted_chat_skips_push(): void
    {
        Config::set('services.firebase.enabled', true);
        Queue::fake();

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

        Queue::assertNothingPushed();
    }
}
