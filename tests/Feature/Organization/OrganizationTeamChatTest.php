<?php

declare(strict_types=1);

namespace Tests\Feature\Organization;

use App\Events\TeamUserTyping;
use App\Models\Company;
use App\Models\Department;
use App\Models\TeamConversation;
use App\Models\TeamMessage;
use App\Models\TeamMessageAttachment;
use App\Models\TeamMessageMention;
use App\Models\User;
use App\Services\TeamChatService;
use App\Services\TeamDepartmentChatSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

final class OrganizationTeamChatTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['administrator', 'manager', 'employee'] as $r) {
            Role::findOrCreate($r);
        }
    }

    public function test_direct_message_between_colleagues_same_company(): void
    {
        $company = Company::query()->create(['name' => 'Acme']);
        $alice = User::factory()->create(['company_id' => $company->id]);
        $bob = User::factory()->create(['company_id' => $company->id]);
        $alice->assignRole('employee');
        $bob->assignRole('employee');

        $conversation = app(TeamChatService::class)->findOrCreateDirect($alice, $bob);

        $this->actingAs($alice)
            ->postJson(route('organization.team-chat.api.messages.store', $conversation), ['body' => 'Привет'])
            ->assertOk()
            ->assertJsonPath('message.body', 'Привет')
            ->assertJsonPath('duplicate', false);

        $this->actingAs($bob)
            ->getJson(route('organization.team-chat.api.messages', $conversation))
            ->assertOk()
            ->assertJsonFragment(['body' => 'Привет']);
    }

    public function test_open_direct_rejects_different_company(): void
    {
        $c1 = Company::query()->create(['name' => 'A']);
        $c2 = Company::query()->create(['name' => 'B']);
        $alice = User::factory()->create(['company_id' => $c1->id]);
        $stranger = User::factory()->create(['company_id' => $c2->id]);
        $alice->assignRole('employee');
        $stranger->assignRole('employee');

        $this->actingAs($alice)
            ->postJson(route('organization.team-chat.api.direct'), ['user_id' => $stranger->id])
            ->assertUnprocessable();
    }

    public function test_store_message_deduplicates_by_client_message_id(): void
    {
        $company = Company::query()->create(['name' => 'Acme']);
        $alice = User::factory()->create(['company_id' => $company->id]);
        $bob = User::factory()->create(['company_id' => $company->id]);
        $alice->assignRole('employee');
        $bob->assignRole('employee');
        $conversation = app(TeamChatService::class)->findOrCreateDirect($alice, $bob);
        $clientId = 'a0eebc99-9c0b-4ef8-bb6d-6bb9bd380a11';

        $first = $this->actingAs($alice)->postJson(route('organization.team-chat.api.messages.store', $conversation), [
            'body' => 'Один раз',
            'client_message_id' => $clientId,
        ])->assertOk();

        $second = $this->actingAs($alice)->postJson(route('organization.team-chat.api.messages.store', $conversation), [
            'body' => 'Один раз',
            'client_message_id' => $clientId,
        ])->assertOk();

        $this->assertSame($first->json('message.id'), $second->json('message.id'));
        $this->assertFalse($first->json('duplicate'));
        $this->assertTrue($second->json('duplicate'));
        $this->assertSame(1, TeamMessage::query()->where('team_conversation_id', $conversation->id)->count());
    }

    public function test_conversations_filter_direct_and_invalid_filter(): void
    {
        $company = Company::query()->create(['name' => 'Acme']);
        $alice = User::factory()->create(['company_id' => $company->id]);
        $bob = User::factory()->create(['company_id' => $company->id]);
        $alice->assignRole('employee');
        $bob->assignRole('employee');
        app(TeamChatService::class)->findOrCreateDirect($alice, $bob);

        $list = $this->actingAs($alice)
            ->getJson(route('organization.team-chat.api.conversations', ['filter' => 'direct']))
            ->assertOk()
            ->assertJsonPath('filter', 'direct')
            ->json('conversations');

        $this->assertIsArray($list);
        foreach ($list as $row) {
            $this->assertSame('direct', $row['type']);
        }

        $this->actingAs($alice)
            ->getJson(route('organization.team-chat.api.conversations', ['filter' => 'invalid']))
            ->assertOk()
            ->assertJsonPath('filter', null);
    }

    public function test_store_message_rejects_mention_of_non_participant(): void
    {
        $company = Company::query()->create(['name' => 'Acme']);
        $alice = User::factory()->create(['company_id' => $company->id]);
        $bob = User::factory()->create(['company_id' => $company->id]);
        $carol = User::factory()->create(['company_id' => $company->id]);
        $alice->assignRole('employee');
        $bob->assignRole('employee');
        $carol->assignRole('employee');
        $conversation = app(TeamChatService::class)->findOrCreateDirect($alice, $bob);

        $this->actingAs($alice)
            ->postJson(route('organization.team-chat.api.messages.store', $conversation), [
                'body' => 'Привет',
                'mention_user_ids' => [$carol->id],
            ])
            ->assertUnprocessable();
    }

    public function test_store_message_stores_mentions_and_messages_list_resolves_names(): void
    {
        $company = Company::query()->create(['name' => 'Acme']);
        $alice = User::factory()->create(['company_id' => $company->id, 'name' => 'Алиса']);
        $bob = User::factory()->create(['company_id' => $company->id, 'name' => 'Боб']);
        $alice->assignRole('employee');
        $bob->assignRole('employee');
        $conversation = app(TeamChatService::class)->findOrCreateDirect($alice, $bob);

        $this->actingAs($alice)
            ->postJson(route('organization.team-chat.api.messages.store', $conversation), [
                'body' => 'Смотри',
                'mention_user_ids' => [$bob->id],
            ])
            ->assertOk()
            ->assertJsonPath('message.mentioned_user_ids.0', $bob->id)
            ->assertJsonPath('message.mentioned_users.0.name', 'Боб');

        $this->actingAs($alice)
            ->getJson(route('organization.team-chat.api.messages', $conversation))
            ->assertOk()
            ->assertJsonFragment(['name' => 'Боб']);
    }

    public function test_read_meta_and_participants_endpoints(): void
    {
        $company = Company::query()->create(['name' => 'Acme']);
        $alice = User::factory()->create(['company_id' => $company->id]);
        $bob = User::factory()->create(['company_id' => $company->id]);
        $alice->assignRole('employee');
        $bob->assignRole('employee');
        $conversation = app(TeamChatService::class)->findOrCreateDirect($alice, $bob);

        $this->actingAs($alice)
            ->getJson(route('organization.team-chat.api.participants', $conversation))
            ->assertOk()
            ->assertJsonCount(2, 'participants');

        $this->actingAs($alice)
            ->getJson(route('organization.team-chat.api.read-meta', $conversation))
            ->assertOk()
            ->assertJsonPath('read_meta.conversation_type', 'direct')
            ->assertJsonPath('read_meta.peer_last_read_message_id', null)
            ->assertJsonPath('read_meta.peer_last_delivered_message_id', null)
            ->assertJsonPath('read_meta.others_min_last_delivered_message_id', null);

        $this->actingAs($alice)
            ->postJson(route('organization.team-chat.api.messages.store', $conversation), ['body' => 'Раз'])
            ->assertOk();
        $messageId = (int) TeamMessage::query()->where('team_conversation_id', $conversation->id)->max('id');

        $this->actingAs($bob)
            ->postJson(route('organization.team-chat.api.read', $conversation), ['message_id' => $messageId])
            ->assertOk();

        $this->actingAs($alice)
            ->getJson(route('organization.team-chat.api.read-meta', $conversation))
            ->assertOk()
            ->assertJsonPath('read_meta.peer_last_read_message_id', $messageId)
            ->assertJsonPath('read_meta.peer_last_delivered_message_id', $messageId)
            ->assertJsonPath('read_meta.others_min_last_delivered_message_id', null);
    }

    public function test_messages_payload_includes_conversation_and_read_meta(): void
    {
        $company = Company::query()->create(['name' => 'Acme']);
        $alice = User::factory()->create(['company_id' => $company->id]);
        $bob = User::factory()->create(['company_id' => $company->id]);
        $alice->assignRole('employee');
        $bob->assignRole('employee');
        $conversation = app(TeamChatService::class)->findOrCreateDirect($alice, $bob);

        $this->actingAs($alice)
            ->getJson(route('organization.team-chat.api.messages', $conversation))
            ->assertOk()
            ->assertJsonPath('conversation.id', $conversation->id)
            ->assertJsonPath('conversation.type', 'direct')
            ->assertJsonStructure([
                'read_meta' => [
                    'conversation_type',
                    'peer_last_read_message_id',
                    'peer_last_delivered_message_id',
                    'others_min_last_delivered_message_id',
                ],
            ]);
    }

    public function test_forward_message_to_department_conversation(): void
    {
        $company = Company::query()->create(['name' => 'Acme']);
        $alice = User::factory()->create(['company_id' => $company->id, 'name' => 'Алиса']);
        $bob = User::factory()->create(['company_id' => $company->id, 'name' => 'Боб']);
        $alice->assignRole('employee');
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

        $direct = app(TeamChatService::class)->findOrCreateDirect($alice, $bob);
        $this->actingAs($bob)
            ->postJson(route('organization.team-chat.api.messages.store', $direct), ['body' => 'Секрет из ЛС'])
            ->assertOk();
        $sourceId = (int) TeamMessage::query()->where('team_conversation_id', $direct->id)->max('id');

        $resp = $this->actingAs($alice)
            ->postJson(route('organization.team-chat.api.messages.store', $deptConv), [
                'forwarded_from_team_message_id' => $sourceId,
                'body' => 'Смотрите',
            ])
            ->assertOk()
            ->assertJsonPath('message.body', 'Смотрите')
            ->assertJsonPath('message.forward.quote_body', 'Секрет из ЛС')
            ->assertJsonPath('message.forward.quote_sender_name', 'Боб');

        $this->assertStringContainsString('ЛС', (string) $resp->json('message.forward.source_title'));

        $this->actingAs($alice)
            ->getJson(route('organization.team-chat.api.messages', $deptConv))
            ->assertOk()
            ->assertJsonFragment(['quote_body' => 'Секрет из ЛС']);
    }

    public function test_forward_from_department_shows_department_name_as_source(): void
    {
        $company = Company::query()->create(['name' => 'Acme']);
        $alice = User::factory()->create(['company_id' => $company->id]);
        $bob = User::factory()->create(['company_id' => $company->id]);
        $alice->assignRole('employee');
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

        $this->actingAs($bob)
            ->postJson(route('organization.team-chat.api.messages.store', $deptConv), ['body' => 'В отделе'])
            ->assertOk();
        $sourceId = (int) TeamMessage::query()->where('team_conversation_id', $deptConv->id)->max('id');

        $direct = app(TeamChatService::class)->findOrCreateDirect($alice, $bob);

        $this->actingAs($alice)
            ->postJson(route('organization.team-chat.api.messages.store', $direct), [
                'forwarded_from_team_message_id' => $sourceId,
            ])
            ->assertOk()
            ->assertJsonPath('message.forward.source_title', 'Продажи');
    }

    public function test_forward_rejected_when_user_not_in_source_conversation(): void
    {
        $company = Company::query()->create(['name' => 'Acme']);
        $alice = User::factory()->create(['company_id' => $company->id]);
        $bob = User::factory()->create(['company_id' => $company->id]);
        $carol = User::factory()->create(['company_id' => $company->id]);
        $alice->assignRole('employee');
        $bob->assignRole('employee');
        $carol->assignRole('employee');

        $direct = app(TeamChatService::class)->findOrCreateDirect($alice, $bob);
        $this->actingAs($bob)
            ->postJson(route('organization.team-chat.api.messages.store', $direct), ['body' => 'Только для Алисы'])
            ->assertOk();
        $sourceId = (int) TeamMessage::query()->where('team_conversation_id', $direct->id)->max('id');

        $carolAlice = app(TeamChatService::class)->findOrCreateDirect($carol, $alice);

        $this->actingAs($carol)
            ->postJson(route('organization.team-chat.api.messages.store', $carolAlice), [
                'forwarded_from_team_message_id' => $sourceId,
            ])
            ->assertUnprocessable();
    }

    public function test_forward_rejects_mention_user_ids(): void
    {
        $company = Company::query()->create(['name' => 'Acme']);
        $alice = User::factory()->create(['company_id' => $company->id]);
        $bob = User::factory()->create(['company_id' => $company->id]);
        $alice->assignRole('employee');
        $bob->assignRole('employee');
        $direct = app(TeamChatService::class)->findOrCreateDirect($alice, $bob);
        $this->actingAs($bob)->postJson(route('organization.team-chat.api.messages.store', $direct), ['body' => 'X'])->assertOk();
        $sourceId = (int) TeamMessage::query()->where('team_conversation_id', $direct->id)->max('id');

        $this->actingAs($alice)
            ->postJson(route('organization.team-chat.api.messages.store', $direct), [
                'forwarded_from_team_message_id' => $sourceId,
                'mention_user_ids' => [$bob->id],
            ])
            ->assertUnprocessable();
    }

    public function test_team_chat_search_finds_message_and_conversation(): void
    {
        $company = Company::query()->create(['name' => 'Acme']);
        $alice = User::factory()->create(['company_id' => $company->id, 'name' => 'Алиса Поиск']);
        $bob = User::factory()->create(['company_id' => $company->id, 'name' => 'Менеджер Василий']);
        $alice->assignRole('employee');
        $bob->assignRole('employee');
        $direct = app(TeamChatService::class)->findOrCreateDirect($alice, $bob);

        $this->actingAs($alice)
            ->postJson(route('organization.team-chat.api.messages.store', $direct), ['body' => 'Уникальная строка для поиска xyz'])
            ->assertOk();

        $this->actingAs($alice)
            ->getJson(route('organization.team-chat.api.search', ['q' => 'xyz']))
            ->assertOk()
            ->assertJsonPath('query', 'xyz')
            ->assertJsonFragment(['body_snippet' => 'Уникальная строка для поиска xyz']);

        $this->actingAs($alice)
            ->getJson(route('organization.team-chat.api.search', ['q' => 'Васил']))
            ->assertOk()
            ->assertJsonFragment(['title' => 'Менеджер Василий']);
    }

    public function test_team_chat_search_includes_colleagues(): void
    {
        $company = Company::query()->create(['name' => 'Acme']);
        $alice = User::factory()->create(['company_id' => $company->id, 'name' => 'Алиса']);
        $bob = User::factory()->create(['company_id' => $company->id, 'name' => 'Борис Коллега', 'email' => 'boris@acme.test']);
        $alice->assignRole('employee');
        $bob->assignRole('employee');
        app(TeamChatService::class)->findOrCreateDirect($alice, $bob);

        $this->actingAs($alice)
            ->getJson(route('organization.team-chat.api.search', ['q' => 'Бор']))
            ->assertOk()
            ->assertJsonPath('colleagues.0.id', $bob->id)
            ->assertJsonPath('colleagues.0.name', 'Борис Коллега');
    }

    public function test_team_chat_search_short_query_returns_empty(): void
    {
        $company = Company::query()->create(['name' => 'Acme']);
        $alice = User::factory()->create(['company_id' => $company->id]);
        $alice->assignRole('employee');

        $this->actingAs($alice)
            ->getJson(route('organization.team-chat.api.search', ['q' => 'a']))
            ->assertOk()
            ->assertJsonPath('conversations', [])
            ->assertJsonPath('messages', [])
            ->assertJsonPath('colleagues', []);
    }

    public function test_mark_delivered_updates_read_meta_for_peer(): void
    {
        $company = Company::query()->create(['name' => 'Acme']);
        $alice = User::factory()->create(['company_id' => $company->id]);
        $bob = User::factory()->create(['company_id' => $company->id]);
        $alice->assignRole('employee');
        $bob->assignRole('employee');
        $conversation = app(TeamChatService::class)->findOrCreateDirect($alice, $bob);

        $this->actingAs($alice)
            ->postJson(route('organization.team-chat.api.messages.store', $conversation), ['body' => 'Ping'])
            ->assertOk();
        $messageId = (int) TeamMessage::query()->where('team_conversation_id', $conversation->id)->max('id');

        $this->actingAs($alice)
            ->getJson(route('organization.team-chat.api.read-meta', $conversation))
            ->assertOk()
            ->assertJsonPath('read_meta.peer_last_read_message_id', null)
            ->assertJsonPath('read_meta.peer_last_delivered_message_id', null)
            ->assertJsonPath('read_meta.others_min_last_delivered_message_id', null);

        $this->actingAs($bob)
            ->postJson(route('organization.team-chat.api.delivered', $conversation), ['message_id' => $messageId])
            ->assertOk();

        $this->actingAs($alice)
            ->getJson(route('organization.team-chat.api.read-meta', $conversation))
            ->assertOk()
            ->assertJsonPath('read_meta.peer_last_delivered_message_id', $messageId)
            ->assertJsonPath('read_meta.peer_last_read_message_id', null)
            ->assertJsonPath('read_meta.others_min_last_delivered_message_id', null);
    }

    public function test_store_message_creates_team_message_mention_rows(): void
    {
        $company = Company::query()->create(['name' => 'Acme']);
        $alice = User::factory()->create(['company_id' => $company->id]);
        $bob = User::factory()->create(['company_id' => $company->id]);
        $alice->assignRole('employee');
        $bob->assignRole('employee');
        $conversation = app(TeamChatService::class)->findOrCreateDirect($alice, $bob);

        $this->actingAs($alice)
            ->postJson(route('organization.team-chat.api.messages.store', $conversation), [
                'body' => 'Эй',
                'mention_user_ids' => [$bob->id],
            ])
            ->assertOk();

        $mid = (int) TeamMessage::query()->where('team_conversation_id', $conversation->id)->max('id');
        $this->assertTrue(
            TeamMessageMention::query()
                ->where('team_message_id', $mid)
                ->where('user_id', $bob->id)
                ->exists(),
        );
    }

    public function test_department_read_meta_others_min_delivered(): void
    {
        $company = Company::query()->create(['name' => 'Acme']);
        $alice = User::factory()->create(['company_id' => $company->id]);
        $bob = User::factory()->create(['company_id' => $company->id]);
        $carol = User::factory()->create(['company_id' => $company->id]);
        $alice->assignRole('employee');
        $bob->assignRole('employee');
        $carol->assignRole('employee');

        $dept = Department::query()->create([
            'name' => 'Продажи',
            'description' => null,
            'parent_id' => null,
            'is_active' => true,
        ]);
        $dept->users()->sync([$alice->id => [], $bob->id => [], $carol->id => []]);
        app(TeamDepartmentChatSyncService::class)->syncAllMembers($dept);
        $deptConv = TeamConversation::query()
            ->where('department_id', $dept->id)
            ->where('type', TeamConversation::TYPE_DEPARTMENT)
            ->firstOrFail();

        $this->actingAs($alice)
            ->postJson(route('organization.team-chat.api.messages.store', $deptConv), ['body' => 'Всем привет'])
            ->assertOk();
        $messageId = (int) TeamMessage::query()->where('team_conversation_id', $deptConv->id)->max('id');

        $this->actingAs($alice)
            ->getJson(route('organization.team-chat.api.read-meta', $deptConv))
            ->assertOk()
            ->assertJsonPath('read_meta.conversation_type', 'department')
            ->assertJsonPath('read_meta.others_min_last_delivered_message_id', 0);

        $this->actingAs($bob)
            ->postJson(route('organization.team-chat.api.delivered', $deptConv), ['message_id' => $messageId])
            ->assertOk();

        $this->actingAs($alice)
            ->getJson(route('organization.team-chat.api.read-meta', $deptConv))
            ->assertOk()
            ->assertJsonPath('read_meta.others_min_last_delivered_message_id', 0);

        $this->actingAs($carol)
            ->postJson(route('organization.team-chat.api.delivered', $deptConv), ['message_id' => $messageId])
            ->assertOk();

        $this->actingAs($alice)
            ->getJson(route('organization.team-chat.api.read-meta', $deptConv))
            ->assertOk()
            ->assertJsonPath('read_meta.others_min_last_delivered_message_id', $messageId);
    }

    public function test_department_read_meta_others_min_read(): void
    {
        $company = Company::query()->create(['name' => 'Acme']);
        $alice = User::factory()->create(['company_id' => $company->id]);
        $bob = User::factory()->create(['company_id' => $company->id]);
        $carol = User::factory()->create(['company_id' => $company->id]);
        $alice->assignRole('employee');
        $bob->assignRole('employee');
        $carol->assignRole('employee');

        $dept = Department::query()->create([
            'name' => 'Продажи',
            'description' => null,
            'parent_id' => null,
            'is_active' => true,
        ]);
        $dept->users()->sync([$alice->id => [], $bob->id => [], $carol->id => []]);
        app(TeamDepartmentChatSyncService::class)->syncAllMembers($dept);
        $deptConv = TeamConversation::query()
            ->where('department_id', $dept->id)
            ->where('type', TeamConversation::TYPE_DEPARTMENT)
            ->firstOrFail();

        $this->actingAs($alice)
            ->postJson(route('organization.team-chat.api.messages.store', $deptConv), ['body' => 'Всем привет'])
            ->assertOk();
        $messageId = (int) TeamMessage::query()->where('team_conversation_id', $deptConv->id)->max('id');

        $this->actingAs($alice)
            ->getJson(route('organization.team-chat.api.read-meta', $deptConv))
            ->assertOk()
            ->assertJsonPath('read_meta.others_min_last_read_message_id', 0);

        $this->actingAs($bob)
            ->postJson(route('organization.team-chat.api.read', $deptConv), ['message_id' => $messageId])
            ->assertOk();

        $this->actingAs($alice)
            ->getJson(route('organization.team-chat.api.read-meta', $deptConv))
            ->assertOk()
            ->assertJsonPath('read_meta.others_min_last_read_message_id', 0);

        $this->actingAs($carol)
            ->postJson(route('organization.team-chat.api.read', $deptConv), ['message_id' => $messageId])
            ->assertOk();

        $this->actingAs($alice)
            ->getJson(route('organization.team-chat.api.read-meta', $deptConv))
            ->assertOk()
            ->assertJsonPath('read_meta.others_min_last_read_message_id', $messageId);
    }

    public function test_department_room_pinned_message_manager_can_set_and_clear(): void
    {
        $company = Company::query()->create(['name' => 'Acme']);
        $manager = User::factory()->create(['company_id' => $company->id]);
        $employee = User::factory()->create(['company_id' => $company->id]);
        $manager->assignRole('manager');
        $employee->assignRole('employee');

        $dept = Department::query()->create([
            'name' => 'Продажи',
            'description' => null,
            'parent_id' => null,
            'is_active' => true,
        ]);
        $dept->users()->sync([$manager->id => [], $employee->id => []]);
        app(TeamDepartmentChatSyncService::class)->syncAllMembers($dept);
        $deptConv = TeamConversation::query()
            ->where('department_id', $dept->id)
            ->where('type', TeamConversation::TYPE_DEPARTMENT)
            ->firstOrFail();

        $this->actingAs($employee)
            ->postJson(route('organization.team-chat.api.messages.store', $deptConv), ['body' => 'Объявление'])
            ->assertOk();
        $messageId = (int) TeamMessage::query()->where('team_conversation_id', $deptConv->id)->max('id');

        $this->actingAs($manager)
            ->postJson(route('organization.team-chat.api.pinned-message', $deptConv), [
                'team_message_id' => $messageId,
            ])
            ->assertOk()
            ->assertJsonPath('room_pinned_message.id', $messageId);

        $deptConv->refresh();
        $this->assertSame($messageId, (int) ($deptConv->pinned_team_message_id ?? 0));

        $this->actingAs($manager)
            ->getJson(route('organization.team-chat.api.messages', $deptConv))
            ->assertOk()
            ->assertJsonPath('conversation.room_pinned_message.id', $messageId)
            ->assertJsonPath('conversation.can_pin_room_message', true);

        $this->actingAs($manager)
            ->postJson(route('organization.team-chat.api.pinned-message', $deptConv), [
                'team_message_id' => null,
            ])
            ->assertOk()
            ->assertJsonPath('room_pinned_message', null);

        $deptConv->refresh();
        $this->assertNull($deptConv->pinned_team_message_id);
    }

    public function test_department_room_pin_forbidden_for_employee(): void
    {
        $company = Company::query()->create(['name' => 'Acme']);
        $alice = User::factory()->create(['company_id' => $company->id]);
        $bob = User::factory()->create(['company_id' => $company->id]);
        $alice->assignRole('employee');
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

        $this->actingAs($alice)
            ->postJson(route('organization.team-chat.api.messages.store', $deptConv), ['body' => 'Текст'])
            ->assertOk();
        $messageId = (int) TeamMessage::query()->where('team_conversation_id', $deptConv->id)->max('id');

        $this->actingAs($alice)
            ->postJson(route('organization.team-chat.api.pinned-message', $deptConv), [
                'team_message_id' => $messageId,
            ])
            ->assertForbidden();
    }

    public function test_direct_room_pin_forbidden(): void
    {
        $company = Company::query()->create(['name' => 'Acme']);
        $alice = User::factory()->create(['company_id' => $company->id]);
        $bob = User::factory()->create(['company_id' => $company->id]);
        $alice->assignRole('manager');
        $bob->assignRole('employee');
        $direct = app(TeamChatService::class)->findOrCreateDirect($alice, $bob);

        $this->actingAs($alice)
            ->postJson(route('organization.team-chat.api.messages.store', $direct), ['body' => 'ЛС'])
            ->assertOk();
        $messageId = (int) TeamMessage::query()->where('team_conversation_id', $direct->id)->max('id');

        $this->actingAs($alice)
            ->postJson(route('organization.team-chat.api.pinned-message', $direct), [
                'team_message_id' => $messageId,
            ])
            ->assertForbidden();
    }

    public function test_store_message_reply_to_root_parent(): void
    {
        $company = Company::query()->create(['name' => 'Acme']);
        $alice = User::factory()->create(['company_id' => $company->id, 'name' => 'Алиса']);
        $bob = User::factory()->create(['company_id' => $company->id]);
        $alice->assignRole('employee');
        $bob->assignRole('employee');
        $conversation = app(TeamChatService::class)->findOrCreateDirect($alice, $bob);

        $root = $this->actingAs($alice)
            ->postJson(route('organization.team-chat.api.messages.store', $conversation), ['body' => 'Корень'])
            ->assertOk()
            ->json('message');
        $rootId = (int) $root['id'];

        $reply = $this->actingAs($bob)
            ->postJson(route('organization.team-chat.api.messages.store', $conversation), [
                'body' => 'Ответ',
                'parent_team_message_id' => $rootId,
            ])
            ->assertOk()
            ->json('message');

        $this->assertSame($rootId, (int) ($reply['parent_team_message_id'] ?? 0));
        $this->assertSame('Алиса', $reply['reply_to']['sender_name'] ?? '');
        $this->assertStringContainsString('Корень', (string) ($reply['reply_to']['body_preview'] ?? ''));
    }

    public function test_store_message_rejects_reply_to_non_root_parent(): void
    {
        $company = Company::query()->create(['name' => 'Acme']);
        $alice = User::factory()->create(['company_id' => $company->id]);
        $bob = User::factory()->create(['company_id' => $company->id]);
        $alice->assignRole('employee');
        $bob->assignRole('employee');
        $conversation = app(TeamChatService::class)->findOrCreateDirect($alice, $bob);

        $rootId = (int) $this->actingAs($alice)
            ->postJson(route('organization.team-chat.api.messages.store', $conversation), ['body' => 'Корень'])
            ->assertOk()
            ->json('message.id');

        $replyId = (int) $this->actingAs($bob)
            ->postJson(route('organization.team-chat.api.messages.store', $conversation), [
                'body' => 'Ответ',
                'parent_team_message_id' => $rootId,
            ])
            ->assertOk()
            ->json('message.id');

        $this->actingAs($alice)
            ->postJson(route('organization.team-chat.api.messages.store', $conversation), [
                'body' => 'Вложенный',
                'parent_team_message_id' => $replyId,
            ])
            ->assertUnprocessable();
    }

    public function test_store_forward_rejects_parent_team_message_id(): void
    {
        $company = Company::query()->create(['name' => 'Acme']);
        $alice = User::factory()->create(['company_id' => $company->id]);
        $bob = User::factory()->create(['company_id' => $company->id]);
        $alice->assignRole('employee');
        $bob->assignRole('employee');
        $direct = app(TeamChatService::class)->findOrCreateDirect($alice, $bob);
        $this->actingAs($bob)->postJson(route('organization.team-chat.api.messages.store', $direct), ['body' => 'X'])->assertOk();
        $sourceId = (int) TeamMessage::query()->where('team_conversation_id', $direct->id)->max('id');

        $this->actingAs($alice)
            ->postJson(route('organization.team-chat.api.messages.store', $direct), [
                'forwarded_from_team_message_id' => $sourceId,
                'parent_team_message_id' => $sourceId,
            ])
            ->assertUnprocessable();
    }

    public function test_store_message_with_attachment_returns_attachment_payload(): void
    {
        Storage::fake('public');
        $company = Company::query()->create(['name' => 'Acme']);
        $alice = User::factory()->create(['company_id' => $company->id]);
        $bob = User::factory()->create(['company_id' => $company->id]);
        $alice->assignRole('employee');
        $bob->assignRole('employee');
        $conversation = app(TeamChatService::class)->findOrCreateDirect($alice, $bob);
        $file = UploadedFile::fake()->create('notes.txt', 12, 'text/plain');

        $this->actingAs($alice)
            ->post(route('organization.team-chat.api.messages.store', $conversation), [
                'body' => 'Файл во вложении',
                'attachments' => [$file],
            ])
            ->assertOk()
            ->assertJsonPath('message.body', 'Файл во вложении')
            ->assertJsonPath('message.attachments.0.original_name', 'notes.txt');

        $this->assertSame(1, TeamMessageAttachment::query()->count());
    }

    public function test_store_message_allows_empty_body_when_attachment_present(): void
    {
        Storage::fake('public');
        $company = Company::query()->create(['name' => 'Acme']);
        $alice = User::factory()->create(['company_id' => $company->id]);
        $bob = User::factory()->create(['company_id' => $company->id]);
        $alice->assignRole('employee');
        $bob->assignRole('employee');
        $conversation = app(TeamChatService::class)->findOrCreateDirect($alice, $bob);
        $file = UploadedFile::fake()->image('pic.jpg', 10, 10);

        $this->actingAs($alice)
            ->post(route('organization.team-chat.api.messages.store', $conversation), [
                'attachments' => [$file],
            ])
            ->assertOk()
            ->assertJsonPath('message.body', '');

        $this->assertSame(1, TeamMessageAttachment::query()->count());
    }

    public function test_store_forward_rejects_attachments(): void
    {
        Storage::fake('public');
        $company = Company::query()->create(['name' => 'Acme']);
        $alice = User::factory()->create(['company_id' => $company->id]);
        $bob = User::factory()->create(['company_id' => $company->id]);
        $carol = User::factory()->create(['company_id' => $company->id]);
        $alice->assignRole('employee');
        $bob->assignRole('employee');
        $carol->assignRole('employee');
        $convAliceBob = app(TeamChatService::class)->findOrCreateDirect($alice, $bob);
        $convAliceCarol = app(TeamChatService::class)->findOrCreateDirect($alice, $carol);

        $this->actingAs($bob)
            ->postJson(route('organization.team-chat.api.messages.store', $convAliceBob), ['body' => 'Источник'])
            ->assertOk();
        $sourceId = (int) TeamMessage::query()->where('team_conversation_id', $convAliceBob->id)->max('id');
        $file = UploadedFile::fake()->create('x.bin', 4, 'application/octet-stream');

        $this->actingAs($alice)
            ->post(
                route('organization.team-chat.api.messages.store', $convAliceCarol),
                [
                    'forwarded_from_team_message_id' => $sourceId,
                    'attachments' => [$file],
                ],
                ['Accept' => 'application/json'],
            )
            ->assertUnprocessable();
    }

    public function test_store_message_persists_link_preview_for_url_in_body(): void
    {
        Http::fake([
            'https://example.com/*' => Http::response(
                '<!DOCTYPE html><html><head><meta property="og:title" content="Example OG" /></head><body></body></html>',
                200,
                ['Content-Type' => 'text/html; charset=utf-8'],
            ),
        ]);
        $company = Company::query()->create(['name' => 'Acme']);
        $alice = User::factory()->create(['company_id' => $company->id]);
        $bob = User::factory()->create(['company_id' => $company->id]);
        $alice->assignRole('employee');
        $bob->assignRole('employee');
        $conversation = app(TeamChatService::class)->findOrCreateDirect($alice, $bob);

        $this->actingAs($alice)
            ->postJson(route('organization.team-chat.api.messages.store', $conversation), [
                'body' => 'Ссылка https://example.com/page',
            ])
            ->assertOk()
            ->assertJsonPath('message.link_preview.title', 'Example OG')
            ->assertJsonPath('message.link_preview.url', 'https://example.com/page');
    }

    public function test_team_chat_pin_moves_conversation_to_top(): void
    {
        $company = Company::query()->create(['name' => 'Acme']);
        $alice = User::factory()->create(['company_id' => $company->id]);
        $bob = User::factory()->create(['company_id' => $company->id]);
        $carol = User::factory()->create(['company_id' => $company->id]);
        $alice->assignRole('employee');
        $bob->assignRole('employee');
        $carol->assignRole('employee');

        $convBob = app(TeamChatService::class)->findOrCreateDirect($alice, $bob);
        $convCarol = app(TeamChatService::class)->findOrCreateDirect($alice, $carol);

        $this->actingAs($alice)
            ->postJson(route('organization.team-chat.api.messages.store', $convBob), ['body' => 'Старое'])
            ->assertOk();
        $this->actingAs($alice)
            ->postJson(route('organization.team-chat.api.messages.store', $convCarol), ['body' => 'Новее'])
            ->assertOk();

        $listBefore = $this->actingAs($alice)
            ->getJson(route('organization.team-chat.api.conversations'))
            ->assertOk()
            ->json('conversations');
        $this->assertSame($convCarol->id, $listBefore[0]['id']);

        $this->actingAs($alice)
            ->postJson(route('organization.team-chat.api.pin', $convBob), ['pinned' => true])
            ->assertOk()
            ->assertJsonPath('pinned', true);

        $listAfter = $this->actingAs($alice)
            ->getJson(route('organization.team-chat.api.conversations'))
            ->assertOk()
            ->json('conversations');
        $this->assertSame($convBob->id, $listAfter[0]['id']);
        $this->assertTrue($listAfter[0]['is_pinned']);

        $this->actingAs($alice)
            ->postJson(route('organization.team-chat.api.pin', $convBob), ['pinned' => false])
            ->assertOk();

        $listUnpin = $this->actingAs($alice)
            ->getJson(route('organization.team-chat.api.conversations'))
            ->assertOk()
            ->json('conversations');
        $this->assertFalse($listUnpin[0]['is_pinned'] ?? false);
        $this->assertSame($convCarol->id, $listUnpin[0]['id']);
    }

    public function test_team_typing_endpoint_dispatches_event(): void
    {
        Event::fake([TeamUserTyping::class]);
        $company = Company::query()->create(['name' => 'Acme']);
        $alice = User::factory()->create(['company_id' => $company->id, 'name' => 'Алиса']);
        $bob = User::factory()->create(['company_id' => $company->id]);
        $alice->assignRole('employee');
        $bob->assignRole('employee');
        $conversation = app(TeamChatService::class)->findOrCreateDirect($alice, $bob);

        $this->actingAs($alice)
            ->postJson(route('organization.team-chat.api.typing', $conversation))
            ->assertOk()
            ->assertJsonPath('success', true);

        Event::assertDispatched(TeamUserTyping::class, function (TeamUserTyping $e) use ($conversation, $alice): bool {
            return $e->conversationId === $conversation->id
                && $e->userId === $alice->id
                && $e->userName === 'Алиса';
        });
    }

    public function test_team_typing_throttled_returns_ok_without_second_dispatch(): void
    {
        Event::fake([TeamUserTyping::class]);
        $company = Company::query()->create(['name' => 'Acme']);
        $alice = User::factory()->create(['company_id' => $company->id]);
        $bob = User::factory()->create(['company_id' => $company->id]);
        $alice->assignRole('employee');
        $bob->assignRole('employee');
        $conversation = app(TeamChatService::class)->findOrCreateDirect($alice, $bob);

        $this->actingAs($alice)
            ->postJson(route('organization.team-chat.api.typing', $conversation))
            ->assertOk();
        $this->actingAs($alice)
            ->postJson(route('organization.team-chat.api.typing', $conversation))
            ->assertOk();

        Event::assertDispatchedTimes(TeamUserTyping::class, 1);
    }

    public function test_team_message_react_toggle_and_list_includes_reactions(): void
    {
        $company = Company::query()->create(['name' => 'Acme']);
        $alice = User::factory()->create(['company_id' => $company->id, 'name' => 'Алиса']);
        $bob = User::factory()->create(['company_id' => $company->id, 'name' => 'Боб']);
        $alice->assignRole('employee');
        $bob->assignRole('employee');
        $conversation = app(TeamChatService::class)->findOrCreateDirect($alice, $bob);

        $msgId = (int) $this->actingAs($bob)
            ->postJson(route('organization.team-chat.api.messages.store', $conversation), ['body' => 'Привет'])
            ->assertOk()
            ->json('message.id');

        $this->actingAs($alice)
            ->postJson(route('organization.team-chat.api.messages.react', ['team_conversation' => $conversation, 'team_message' => $msgId]), ['emoji' => '👍'])
            ->assertOk()
            ->assertJsonPath('reactions.0.emoji', '👍')
            ->assertJsonPath('reactions.0.user_id', $alice->id);

        $list = $this->actingAs($alice)
            ->getJson(route('organization.team-chat.api.messages', $conversation))
            ->assertOk()
            ->json('messages');
        $this->assertTrue(
            collect($list)->contains(function (array $m) use ($msgId): bool {
                return (int) ($m['id'] ?? 0) === $msgId
                    && isset($m['reactions'][0]['emoji'])
                    && $m['reactions'][0]['emoji'] === '👍';
            }),
        );

        $this->actingAs($alice)
            ->postJson(route('organization.team-chat.api.messages.react', ['team_conversation' => $conversation, 'team_message' => $msgId]), ['emoji' => '👍'])
            ->assertOk()
            ->assertJsonPath('reactions', []);

        $this->actingAs($alice)
            ->postJson(route('organization.team-chat.api.messages.react', ['team_conversation' => $conversation, 'team_message' => $msgId]), ['emoji' => '❤️'])
            ->assertOk()
            ->assertJsonPath('reactions.0.emoji', '❤️');
    }

    public function test_team_message_react_rejects_wrong_conversation(): void
    {
        $company = Company::query()->create(['name' => 'Acme']);
        $alice = User::factory()->create(['company_id' => $company->id]);
        $bob = User::factory()->create(['company_id' => $company->id]);
        $carol = User::factory()->create(['company_id' => $company->id]);
        $alice->assignRole('employee');
        $bob->assignRole('employee');
        $carol->assignRole('employee');
        $convAb = app(TeamChatService::class)->findOrCreateDirect($alice, $bob);
        $convAc = app(TeamChatService::class)->findOrCreateDirect($alice, $carol);

        $msgId = (int) $this->actingAs($bob)
            ->postJson(route('organization.team-chat.api.messages.store', $convAb), ['body' => 'X'])
            ->assertOk()
            ->json('message.id');

        $this->actingAs($alice)
            ->postJson(route('organization.team-chat.api.messages.react', ['team_conversation' => $convAc, 'team_message' => $msgId]), ['emoji' => '👍'])
            ->assertNotFound();
    }

    public function test_administrator_sees_all_department_chats_in_conversations_list(): void
    {
        $company = Company::query()->create(['name' => 'Acme']);
        $admin = User::factory()->create(['company_id' => $company->id]);
        $employee = User::factory()->create(['company_id' => $company->id, 'name' => 'Сотрудник']);
        $admin->assignRole('administrator');
        $employee->assignRole('employee');

        $deptSales = Department::query()->create([
            'name' => 'Продажи',
            'description' => null,
            'parent_id' => null,
            'is_active' => true,
        ]);
        $deptSupport = Department::query()->create([
            'name' => 'Поддержка',
            'description' => null,
            'parent_id' => null,
            'is_active' => true,
        ]);
        $deptSales->users()->sync([$employee->id => []]);
        app(TeamDepartmentChatSyncService::class)->syncAllMembers($deptSales);
        app(TeamDepartmentChatSyncService::class)->syncAllMembers($deptSupport);

        $list = $this->actingAs($admin)
            ->getJson(route('organization.team-chat.api.conversations', ['filter' => 'department']))
            ->assertOk()
            ->json('conversations');

        $deptIds = collect($list)->pluck('department_id')->filter()->map(fn ($id) => (int) $id)->all();
        $this->assertContains($deptSales->id, $deptIds);
        $this->assertContains($deptSupport->id, $deptIds);
    }

    public function test_employee_sees_only_own_department_chats(): void
    {
        $company = Company::query()->create(['name' => 'Acme']);
        $employee = User::factory()->create(['company_id' => $company->id]);
        $other = User::factory()->create(['company_id' => $company->id]);
        $employee->assignRole('employee');
        $other->assignRole('employee');

        $deptSales = Department::query()->create([
            'name' => 'Продажи',
            'description' => null,
            'parent_id' => null,
            'is_active' => true,
        ]);
        $deptSupport = Department::query()->create([
            'name' => 'Поддержка',
            'description' => null,
            'parent_id' => null,
            'is_active' => true,
        ]);
        $deptSales->users()->sync([$employee->id => []]);
        $deptSupport->users()->sync([$other->id => []]);
        app(TeamDepartmentChatSyncService::class)->syncAllMembers($deptSales);
        app(TeamDepartmentChatSyncService::class)->syncAllMembers($deptSupport);

        $list = $this->actingAs($employee)
            ->getJson(route('organization.team-chat.api.conversations', ['filter' => 'department']))
            ->assertOk()
            ->json('conversations');

        $deptIds = collect($list)->pluck('department_id')->filter()->map(fn ($id) => (int) $id)->all();
        $this->assertSame([$deptSales->id], $deptIds);
        $this->assertNotContains($deptSupport->id, $deptIds);
    }

    public function test_employee_cannot_access_other_department_chat(): void
    {
        $company = Company::query()->create(['name' => 'Acme']);
        $employee = User::factory()->create(['company_id' => $company->id]);
        $other = User::factory()->create(['company_id' => $company->id]);
        $employee->assignRole('employee');
        $other->assignRole('employee');

        $deptSales = Department::query()->create([
            'name' => 'Продажи',
            'description' => null,
            'parent_id' => null,
            'is_active' => true,
        ]);
        $deptSupport = Department::query()->create([
            'name' => 'Поддержка',
            'description' => null,
            'parent_id' => null,
            'is_active' => true,
        ]);
        $deptSales->users()->sync([$employee->id => []]);
        $deptSupport->users()->sync([$other->id => []]);
        app(TeamDepartmentChatSyncService::class)->syncAllMembers($deptSales);
        app(TeamDepartmentChatSyncService::class)->syncAllMembers($deptSupport);

        $supportConv = TeamConversation::query()
            ->where('department_id', $deptSupport->id)
            ->where('type', TeamConversation::TYPE_DEPARTMENT)
            ->firstOrFail();

        $this->actingAs($employee)
            ->getJson(route('organization.team-chat.api.messages', $supportConv))
            ->assertForbidden();

        $this->actingAs($employee)
            ->get(route('organization.team-chat.show', $supportConv))
            ->assertForbidden();
    }
}
