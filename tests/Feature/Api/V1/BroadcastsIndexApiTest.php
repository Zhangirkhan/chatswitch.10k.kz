<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1;

use App\Models\BroadcastCampaign;
use App\Models\SystemSetting;
use App\Models\User;
use App\Models\WhatsappSession;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

final class BroadcastsIndexApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['administrator', 'manager', 'employee'] as $roleName) {
            Role::findOrCreate($roleName);
        }
        SystemSetting::setValue('module_broadcasts', 'on');
    }

    public function test_manager_lists_broadcast_campaigns(): void
    {
        $manager = User::factory()->create();
        $manager->assignRole('manager');
        $session = WhatsappSession::factory()->create(['is_active' => true]);

        BroadcastCampaign::query()->create([
            'created_by_user_id' => $manager->id,
            'sender_user_id' => $manager->id,
            'whatsapp_session_id' => $session->id,
            'source' => BroadcastCampaign::SOURCE_FILTERS,
            'status' => BroadcastCampaign::STATUS_RUNNING,
            'delay_seconds' => 5,
            'total_rows' => 10,
            'ready_count' => 10,
            'sent_count' => 3,
            'skipped_count' => 0,
            'failed_count' => 0,
        ]);

        Sanctum::actingAs($manager);

        $this->getJson('/api/v1/broadcasts')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.status', BroadcastCampaign::STATUS_RUNNING)
            ->assertJsonPath('data.0.sent_count', 3)
            ->assertJsonPath('meta.current_page', 1)
            ->assertJsonStructure(['data' => [['id', 'status', 'created_at', 'session', 'sender']]]);
    }

    public function test_employee_cannot_list_broadcasts(): void
    {
        $user = User::factory()->create();
        $user->assignRole('employee');
        Sanctum::actingAs($user);

        $this->getJson('/api/v1/broadcasts')->assertForbidden();
    }
}
