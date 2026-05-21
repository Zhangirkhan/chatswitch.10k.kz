<?php

declare(strict_types=1);

namespace Tests\Feature\Broadcast;

use App\Jobs\SendBroadcastCampaignItemJob;
use App\Models\BroadcastCampaign;
use App\Models\Chat;
use App\Models\Contact;
use App\Models\Message;
use App\Models\User;
use App\Models\WhatsappSession;
use App\Services\Broadcast\BroadcastSendRateLimiter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

final class BroadcastCampaignTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['administrator', 'manager', 'employee'] as $role) {
            Role::findOrCreate($role);
        }
    }

    public function test_employee_cannot_access_broadcasts(): void
    {
        $user = User::factory()->create();
        $user->assignRole('employee');

        $this->actingAs($user)->get(route('broadcasts.index'))->assertForbidden();
    }

    public function test_preview_skips_non_archived_and_starts_jobs_for_archived(): void
    {
        Queue::fake();

        $admin = User::factory()->create();
        $admin->assignRole('administrator');
        $session = WhatsappSession::factory()->create(['is_active' => true]);

        $contact = Contact::factory()->create(['phone_number' => '77001234567']);
        Chat::factory()->create([
            'contact_id' => $contact->id,
            'whatsapp_session_id' => $session->id,
            'is_group' => false,
            'is_archived' => true,
            'whatsapp_chat_id' => '77001234567@c.us',
        ]);

        $openContact = Contact::factory()->create(['phone_number' => '77007654321']);
        Chat::factory()->create([
            'contact_id' => $openContact->id,
            'whatsapp_session_id' => $session->id,
            'is_group' => false,
            'is_archived' => false,
            'whatsapp_chat_id' => '77007654321@c.us',
        ]);

        $csv = "phone,message\n77001234567,Привет архив\n77007654321,Не должно уйти\n";
        $file = UploadedFile::fake()->createWithContent('broadcast.csv', $csv);

        $preview = $this->actingAs($admin)->post(route('broadcasts.preview'), [
            'source' => 'excel',
            'whatsapp_session_id' => $session->id,
            'file' => $file,
        ]);

        $preview->assertOk();
        $preview->assertJsonPath('summary.ready', 1);
        $preview->assertJsonPath('summary.skipped', 1);

        $store = $this->actingAs($admin)->post(route('broadcasts.store'), [
            'source' => 'excel',
            'whatsapp_session_id' => $session->id,
            'sender_user_id' => $admin->id,
            'file' => UploadedFile::fake()->createWithContent('broadcast.csv', $csv),
        ]);

        $store->assertOk();
        $this->assertDatabaseCount('broadcast_campaigns', 1);
        $campaign = BroadcastCampaign::query()->first();
        $this->assertNotNull($campaign);
        $this->assertSame(1, $campaign->ready_count);
        $this->assertSame(app(BroadcastSendRateLimiter::class)->delayBetweenMessages(), $campaign->delay_seconds);

        Queue::assertPushed(SendBroadcastCampaignItemJob::class, 1);
    }

    public function test_filter_preview_finds_archived_chat_by_name(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('administrator');
        $session = WhatsappSession::factory()->create(['is_active' => true]);

        $contact = Contact::factory()->create([
            'name' => 'Нургалиев Жангирхан',
            'phone_number' => '77001234567',
        ]);
        Chat::factory()->create([
            'contact_id' => $contact->id,
            'whatsapp_session_id' => $session->id,
            'is_group' => false,
            'is_archived' => true,
            'whatsapp_chat_id' => '77001234567@c.us',
        ]);

        $preview = $this->actingAs($admin)->post(route('broadcasts.preview'), [
            'source' => 'filters',
            'whatsapp_session_id' => $session->id,
            'filter_message' => 'Привет',
            'filters' => ['search' => 'Жангирхан'],
        ]);

        $preview->assertOk();
        $preview->assertJsonPath('summary.ready', 1);
        $preview->assertJsonPath('rows.0.contact_name', 'Нургалиев Жангирхан');
    }

    public function test_rejects_broadcast_when_hourly_quota_exceeded(): void
    {
        Queue::fake();

        $admin = User::factory()->create();
        $admin->assignRole('administrator');
        $session = WhatsappSession::factory()->create(['is_active' => true]);

        $contact = Contact::factory()->create(['phone_number' => '77001234567']);
        $chat = Chat::factory()->create([
            'contact_id' => $contact->id,
            'whatsapp_session_id' => $session->id,
            'is_group' => false,
            'is_archived' => true,
            'whatsapp_chat_id' => '77001234567@c.us',
        ]);

        for ($i = 0; $i < BroadcastSendRateLimiter::MAX_MESSAGES_PER_HOUR; $i++) {
            Message::create([
                'chat_id' => $chat->id,
                'whatsapp_session_id' => $session->id,
                'direction' => 'outbound',
                'type' => 'chat',
                'body' => 'x',
                'ack' => 'sent',
                'message_timestamp' => now(),
            ]);
        }

        $response = $this->actingAs($admin)->postJson(route('broadcasts.store'), [
            'source' => 'excel',
            'whatsapp_session_id' => $session->id,
            'sender_user_id' => $admin->id,
            'file' => UploadedFile::fake()->createWithContent('broadcast.csv', "phone,message\n77001234567,Лимит\n"),
        ]);

        $response->assertUnprocessable();
        Queue::assertNothingPushed();
    }
}
