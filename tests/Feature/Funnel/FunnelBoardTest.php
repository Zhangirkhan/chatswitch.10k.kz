<?php

declare(strict_types=1);

namespace Tests\Feature\Funnel;

use App\Services\Funnel\FunnelBoardService;
use App\Models\Chat;
use App\Models\ChatAssignment;
use App\Models\Company;
use App\Models\Contact;
use App\Models\Department;
use App\Models\Funnel;
use App\Models\FunnelStage;
use App\Models\SystemSetting;
use App\Models\User;
use App\Models\WhatsappSession;
use App\Support\TenantCompany;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

final class FunnelBoardTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['administrator', 'manager', 'employee'] as $role) {
            Role::findOrCreate($role);
        }

        SystemSetting::setValue('module_funnels', 'on');
        TenantCompany::ensureExists();
    }

    public function test_employee_can_open_funnel_board_page(): void
    {
        $company = Company::query()->findOrFail(TenantCompany::id());
        $user = User::factory()->create(['company_id' => $company->id]);
        $user->assignRole('employee');

        $funnel = $this->createFunnelWithStages($company->id);

        $this->actingAs($user)
            ->get(route('funnels.board', ['funnel_id' => $funnel->id]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Funnels/Board')
                ->has('funnels', 1)
                ->where('selectedFunnelId', $funnel->id));
    }

    public function test_board_data_returns_visible_chats_grouped_by_stage(): void
    {
        $company = Company::query()->findOrFail(TenantCompany::id());
        $user = User::factory()->create(['company_id' => $company->id]);
        $user->assignRole('employee');

        $funnel = $this->createFunnelWithStages($company->id);
        $stageLead = $funnel->stages()->orderBy('position')->firstOrFail();
        $stageOffer = $funnel->stages()->orderByDesc('position')->firstOrFail();

        $dept = Department::query()->create([
            'name' => 'Продажи',
            'description' => null,
            'is_active' => true,
        ]);
        $dept->funnels()->sync([$funnel->id]);
        $dept->funnelStages()->sync([$stageLead->id, $stageOffer->id]);
        $user->departments()->sync([$dept->id]);

        $session = WhatsappSession::factory()->create();
        $contact = Contact::factory()->create([
            'name' => 'Иван Клиент',
            'phone_number' => '77001234567',
        ]);

        $mineChat = Chat::factory()->create([
            'whatsapp_session_id' => $session->id,
            'company_id' => $company->id,
            'contact_id' => $contact->id,
            'is_group' => false,
            'is_archived' => false,
            'funnel_id' => $funnel->id,
            'funnel_stage_id' => $stageLead->id,
            'last_message_text' => 'Здравствуйте',
            'last_message_at' => now(),
        ]);
        $mineChat->departments()->sync([$dept->id]);
        ChatAssignment::query()->create([
            'chat_id' => $mineChat->id,
            'user_id' => $user->id,
            'assigned_by' => $user->id,
        ]);

        $otherUser = User::factory()->create(['company_id' => $company->id]);
        $otherUser->assignRole('employee');
        $otherUser->departments()->sync([$dept->id]);

        $otherChat = Chat::factory()->create([
            'whatsapp_session_id' => $session->id,
            'company_id' => $company->id,
            'is_group' => false,
            'is_archived' => false,
            'funnel_id' => $funnel->id,
            'funnel_stage_id' => $stageOffer->id,
            'last_message_at' => now(),
        ]);
        $otherChat->departments()->sync([$dept->id]);
        ChatAssignment::query()->create([
            'chat_id' => $otherChat->id,
            'user_id' => $otherUser->id,
            'assigned_by' => $otherUser->id,
        ]);

        $mineResponse = $this->actingAs($user)
            ->getJson(route('funnels.board.data', [
                'funnel_id' => $funnel->id,
                'scope' => 'mine',
            ]))
            ->assertOk();

        $mineCards = collect($mineResponse->json('stages'))
            ->flatMap(static fn (array $stage): array => $stage['cards'])
            ->pluck('id')
            ->all();

        $this->assertSame([$mineChat->id], $mineCards);

        $manager = User::factory()->create(['company_id' => $company->id]);
        $manager->assignRole('manager');
        $manager->departments()->sync([$dept->id]);

        $allResponse = $this->actingAs($manager)
            ->getJson(route('funnels.board.data', [
                'funnel_id' => $funnel->id,
                'scope' => 'all',
            ]))
            ->assertOk();

        $allCards = collect($allResponse->json('stages'))
            ->flatMap(static fn (array $stage): array => $stage['cards'])
            ->pluck('id')
            ->sort()
            ->values()
            ->all();

        $this->assertSame(
            collect([$mineChat->id, $otherChat->id])->sort()->values()->all(),
            $allCards,
        );
    }

    public function test_board_includes_inbox_column(): void
    {
        $company = Company::query()->findOrFail(TenantCompany::id());
        $user = User::factory()->create(['company_id' => $company->id]);
        $user->assignRole('employee');

        $funnel = $this->createFunnelWithStages($company->id);
        $stageLead = $funnel->stages()->orderBy('position')->firstOrFail();

        $dept = Department::query()->create([
            'name' => 'Продажи',
            'description' => null,
            'is_active' => true,
        ]);
        $dept->funnels()->sync([$funnel->id]);
        $dept->funnelStages()->sync([$stageLead->id]);
        $user->departments()->sync([$dept->id]);

        $session = WhatsappSession::factory()->create();

        $inboxChat = Chat::factory()->create([
            'whatsapp_session_id' => $session->id,
            'company_id' => $company->id,
            'is_group' => false,
            'is_archived' => false,
            'funnel_id' => null,
            'funnel_stage_id' => null,
            'last_message_at' => now(),
        ]);
        $inboxChat->departments()->sync([$dept->id]);

        $response = $this->actingAs($user)
            ->getJson(route('funnels.board.data', ['funnel_id' => $funnel->id]))
            ->assertOk();

        $inboxStage = collect($response->json('stages'))->firstWhere('id', FunnelBoardService::INBOX_STAGE_ID);
        $this->assertNotNull($inboxStage);
        $this->assertTrue($inboxStage['is_inbox']);
        $this->assertSame([$inboxChat->id], collect($inboxStage['cards'])->pluck('id')->all());
    }

    public function test_bulk_move_moves_selected_chats(): void
    {
        $company = Company::query()->findOrFail(TenantCompany::id());
        $user = User::factory()->create(['company_id' => $company->id]);
        $user->assignRole('administrator');

        $funnel = $this->createFunnelWithStages($company->id);
        $stageLead = $funnel->stages()->orderBy('position')->firstOrFail();
        $stageOffer = $funnel->stages()->orderByDesc('position')->firstOrFail();

        $dept = Department::query()->create([
            'name' => 'Продажи',
            'description' => null,
            'is_active' => true,
        ]);
        $dept->funnels()->sync([$funnel->id]);
        $dept->funnelStages()->sync([$stageLead->id, $stageOffer->id]);

        $session = WhatsappSession::factory()->create();

        $chatOne = Chat::factory()->create([
            'whatsapp_session_id' => $session->id,
            'company_id' => $company->id,
            'is_group' => false,
            'funnel_id' => $funnel->id,
            'funnel_stage_id' => $stageLead->id,
        ]);
        $chatOne->departments()->sync([$dept->id]);

        $chatTwo = Chat::factory()->create([
            'whatsapp_session_id' => $session->id,
            'company_id' => $company->id,
            'is_group' => false,
            'funnel_id' => $funnel->id,
            'funnel_stage_id' => $stageLead->id,
        ]);
        $chatTwo->departments()->sync([$dept->id]);

        $this->actingAs($user)
            ->postJson(route('funnels.board.bulk-move'), [
                'funnel_id' => $funnel->id,
                'stage_id' => $stageOffer->id,
                'chat_ids' => [$chatOne->id, $chatTwo->id],
            ])
            ->assertOk()
            ->assertJsonPath('moved', 2);

        $this->assertSame($stageOffer->id, $chatOne->fresh()->funnel_stage_id);
        $this->assertSame($stageOffer->id, $chatTwo->fresh()->funnel_stage_id);
    }

    public function test_manager_can_open_board_with_whatsapp_filter_options(): void
    {
        $company = Company::query()->findOrFail(TenantCompany::id());
        $manager = User::factory()->create(['company_id' => $company->id]);
        $manager->assignRole('manager');

        $session = WhatsappSession::factory()->create(['display_name' => 'Основной WA']);
        $manager->whatsappSessions()->sync([$session->id]);

        $funnel = $this->createFunnelWithStages($company->id);

        $this->actingAs($manager)
            ->get(route('funnels.board', ['funnel_id' => $funnel->id]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Funnels/Board')
                ->has('filterWhatsappSessions', 1));
    }

    public function test_move_blocked_when_wip_limit_reached(): void
    {
        $company = Company::query()->findOrFail(TenantCompany::id());
        $user = User::factory()->create(['company_id' => $company->id]);
        $user->assignRole('administrator');

        $funnel = $this->createFunnelWithStages($company->id);
        $stageLead = FunnelStage::query()
            ->where('funnel_id', $funnel->id)
            ->orderBy('position')
            ->firstOrFail();
        $stageOffer = FunnelStage::query()
            ->where('funnel_id', $funnel->id)
            ->orderByDesc('position')
            ->firstOrFail();
        $stageLead->update(['wip_limit' => 1]);
        $this->assertNotSame($stageLead->id, $stageOffer->id);

        $dept = Department::query()->create([
            'name' => 'Продажи WIP',
            'description' => null,
            'is_active' => true,
        ]);
        $dept->funnels()->sync([$funnel->id]);
        $dept->funnelStages()->sync([$stageLead->id, $stageOffer->id]);

        $session = WhatsappSession::factory()->create();

        $occupying = Chat::factory()->create([
            'whatsapp_session_id' => $session->id,
            'company_id' => $company->id,
            'is_group' => false,
            'funnel_id' => $funnel->id,
            'funnel_stage_id' => $stageLead->id,
        ]);
        $occupying->departments()->sync([$dept->id]);

        $incoming = Chat::factory()->create([
            'whatsapp_session_id' => $session->id,
            'company_id' => $company->id,
            'is_group' => false,
            'funnel_id' => $funnel->id,
            'funnel_stage_id' => $stageOffer->id,
        ]);
        $incoming->departments()->sync([$dept->id]);

        $this->assertSame($stageOffer->id, (int) $incoming->funnel_stage_id);
        $this->assertSame(1, (int) $stageLead->fresh()->wip_limit);
        $this->assertSame(1, Chat::query()->where('funnel_stage_id', $stageLead->id)->count());

        $this->actingAs($user)
            ->patchJson(route('chats.funnel.update', $incoming->id), [
                'funnel_id' => $funnel->id,
                'funnel_stage_id' => $stageLead->id,
            ])
            ->assertStatus(422);

        $this->assertSame($stageOffer->id, $incoming->fresh()->funnel_stage_id);
    }

    public function test_board_is_forbidden_when_module_disabled(): void
    {
        SystemSetting::setValue('module_funnels', 'off');

        $admin = User::factory()->create();
        $admin->assignRole('administrator');

        $this->actingAs($admin)
            ->get(route('funnels.board'))
            ->assertForbidden();
    }

    private function createFunnelWithStages(int $companyId): Funnel
    {
        $funnel = Funnel::query()->create([
            'company_id' => $companyId,
            'name' => 'Основная',
            'description' => null,
            'color' => '#25d366',
            'is_active' => true,
            'position' => 0,
        ]);

        FunnelStage::query()->create([
            'funnel_id' => $funnel->id,
            'name' => 'Лид',
            'color' => '#3b82f6',
            'position' => 0,
            'is_active' => true,
        ]);
        FunnelStage::query()->create([
            'funnel_id' => $funnel->id,
            'name' => 'КП',
            'color' => '#6366f1',
            'position' => 1,
            'is_active' => true,
        ]);

        return $funnel->fresh(['stages']);
    }
}
