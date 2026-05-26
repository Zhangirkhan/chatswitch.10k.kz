<?php

declare(strict_types=1);

namespace Tests\Feature\Organization;

use App\Models\Chat;
use App\Models\Company;
use App\Models\Contact;
use App\Models\Department;
use App\Models\Message;
use App\Models\TeamConversation;
use App\Models\TeamMessage;
use App\Models\User;
use App\Models\WhatsappSession;
use App\Services\TeamChatService;
use App\Services\TeamDepartmentChatSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

final class CrossChannelMessageShareTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['administrator', 'manager', 'employee'] as $r) {
            Role::findOrCreate($r);
        }
    }

    public function test_share_team_message_to_clients_as_text(): void
    {
        Queue::fake();

        $company = Company::query()->create(['name' => 'Acme']);
        $user = User::factory()->create(['company_id' => $company->id, 'name' => 'Оператор']);
        $peer = User::factory()->create(['company_id' => $company->id, 'name' => 'Коллега']);
        $user->assignRole('administrator');
        $peer->assignRole('employee');

        $session = WhatsappSession::factory()->create([
            'is_active' => true,
            'status' => 'connected',
        ]);
        $user->whatsappSessions()->attach($session->id);

        $contact = Contact::factory()->create();
        Chat::factory()->create([
            'contact_id' => $contact->id,
            'whatsapp_session_id' => $session->id,
            'whatsapp_chat_id' => '77001112233@c.us',
            'is_group' => false,
        ]);

        $direct = app(TeamChatService::class)->findOrCreateDirect($user, $peer);
        $this->actingAs($peer)
            ->postJson(route('organization.team-chat.api.messages.store', $direct), ['body' => 'Из тим-чата'])
            ->assertOk();
        $teamMessageId = (int) TeamMessage::query()->max('id');

        $this->actingAs($user)
            ->postJson(route('organization.team-chat.api.messages.share-to-clients', $teamMessageId), [
                'contact_ids' => [$contact->id],
                'whatsapp_session_id' => $session->id,
                'body' => 'Смотрите',
            ])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('sent', 1);

        $outbound = Message::query()->where('direction', 'outbound')->latest('id')->first();
        $this->assertNotNull($outbound);
        $body = (string) $outbound->body;
        $this->assertStringNotContainsString('Переслано', $body);
        $this->assertStringNotContainsString('[Переслано', $body);
        $this->assertStringNotContainsString('Коллега:', $body);
        $this->assertStringContainsString('Из тим-чата', $body);
        $this->assertStringContainsString('Смотрите', $body);
    }

    public function test_share_whatsapp_message_to_team_conversation(): void
    {
        $company = Company::query()->create(['name' => 'Acme']);
        $alice = User::factory()->create(['company_id' => $company->id, 'name' => 'Алиса']);
        $bob = User::factory()->create(['company_id' => $company->id, 'name' => 'Боб']);
        $alice->assignRole('administrator');
        $bob->assignRole('employee');

        $dept = Department::query()->create([
            'name' => 'Продажи',
            'description' => null,
            'parent_id' => null,
            'is_active' => true,
        ]);
        $dept->users()->sync([$alice->id => [], $bob->id => []]);
        app(TeamDepartmentChatSyncService::class)->syncAllMembers($dept);
        $deptConv = TeamConversation::query()
            ->where('department_id', $dept->id)
            ->where('type', TeamConversation::TYPE_DEPARTMENT)
            ->firstOrFail();

        $session = WhatsappSession::factory()->create(['is_active' => true, 'status' => 'connected']);
        $contact = Contact::factory()->create(['name' => 'Клиент Иван']);
        $chat = Chat::factory()->create([
            'contact_id' => $contact->id,
            'whatsapp_session_id' => $session->id,
            'chat_name' => 'Клиент Иван',
        ]);
        $alice->whatsappSessions()->attach($session->id);

        $waMessage = Message::create([
            'chat_id' => $chat->id,
            'whatsapp_session_id' => $session->id,
            'direction' => 'inbound',
            'type' => 'chat',
            'body' => 'Нужна скидка',
            'sender_name' => 'Клиент Иван',
            'ack' => 'read',
            'message_timestamp' => now(),
        ]);

        $this->actingAs($alice)
            ->postJson(route('messages.share-to-team', $waMessage), [
                'team_conversation_ids' => [$deptConv->id],
                'body' => 'Помогите',
            ])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('sent', 1);

        $teamMsg = TeamMessage::query()->where('team_conversation_id', $deptConv->id)->latest('id')->first();
        $this->assertNotNull($teamMsg);
        $this->assertSame('Помогите', $teamMsg->body);
        $this->assertSame($waMessage->id, (int) $teamMsg->forwarded_from_message_id);
        $this->assertStringContainsString('WhatsApp', (string) $teamMsg->forward_source_title);
        $this->assertStringContainsString('Нужна скидка', (string) $teamMsg->forward_quote_body);
    }
}
