<?php

declare(strict_types=1);

namespace Tests\Feature\Analytics;

use App\Models\Department;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

final class DialogAnalyticsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['administrator', 'manager', 'employee'] as $r) {
            Role::findOrCreate($r);
        }
    }

    private function queryParams(): array
    {
        return [
            'from' => now()->subDays(7)->format('Y-m-d'),
            'to' => now()->format('Y-m-d'),
            'status' => 'all',
            'channel' => 'all',
            'page' => 1,
            'per_page' => 15,
        ];
    }

    public function test_guest_redirected_from_analytics_page(): void
    {
        $this->get('/analytics/dialogs')->assertRedirect();
    }

    public function test_guest_cannot_access_analytics_api(): void
    {
        $this->getJson('/api/analytics/dialogs?'.http_build_query($this->queryParams()))
            ->assertUnauthorized();
    }

    public function test_administrator_receives_json_payload(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('administrator');

        $response = $this->actingAs($admin)->getJson('/api/analytics/dialogs?'.http_build_query($this->queryParams()));

        $response->assertOk();
        $response->assertJsonStructure([
            'sla_seconds',
            'summary' => [
                'total_dialogs',
                'active_dialogs',
            ],
            'employee_stats',
            'department_stats',
            'rankings' => [
                'fastest_avg_response',
                'slowest_avg_response',
                'most_unanswered',
                'most_dialogs',
                'best_sla',
                'worst_sla',
            ],
            'chart_data',
            'problematic_chats' => [
                'data',
                'meta',
            ],
        ]);
    }

    public function test_employee_cannot_filter_by_other_user(): void
    {
        $emp = User::factory()->create();
        $emp->assignRole('employee');
        $other = User::factory()->create();

        $params = $this->queryParams();
        $params['employee_id'] = $other->id;

        $this->actingAs($emp)->getJson('/api/analytics/dialogs?'.http_build_query($params))
            ->assertForbidden();
    }

    public function test_manager_cannot_filter_by_foreign_department(): void
    {
        $deptA = Department::create(['name' => 'A', 'is_active' => true]);
        $deptB = Department::create(['name' => 'B', 'is_active' => true]);

        $manager = User::factory()->create(['department_id' => $deptA->id]);
        $manager->assignRole('manager');

        $params = $this->queryParams();
        $params['department_id'] = $deptB->id;

        $this->actingAs($manager)->getJson('/api/analytics/dialogs?'.http_build_query($params))
            ->assertForbidden();
    }

    public function test_analytics_page_loads_for_employee(): void
    {
        $emp = User::factory()->create();
        $emp->assignRole('employee');

        $this->actingAs($emp)->get('/analytics/dialogs')
            ->assertOk();
    }
}
