<?php

declare(strict_types=1);

namespace Tests\Feature\Organization;

use App\Models\Company;
use App\Models\Department;
use App\Models\DepartmentPost;
use App\Models\TeamConversation;
use App\Models\TeamMessage;
use App\Models\User;
use App\Services\TeamDepartmentChatSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

final class OrganizationSidebarBadgeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['accel.organization_department_tasks' => true]);
        foreach (['administrator', 'manager', 'employee'] as $role) {
            Role::findOrCreate($role);
        }
    }

    public function test_org_open_tasks_badge_counts_only_current_tenant_for_administrator(): void
    {
        $companyA = $this->createTenantCompany(['name' => 'Company A']);
        $deptA = Department::query()->create([
            'name' => 'Sales A',
            'description' => null,
            'is_active' => true,
            'company_id' => $companyA->id,
        ]);
        $adminA = User::factory()->create(['company_id' => $companyA->id]);
        $adminA->assignRole('administrator');

        DepartmentPost::query()->create([
            'department_id' => $deptA->id,
            'author_id' => $adminA->id,
            'title' => 'Task A',
            'body' => 'Body',
            'status' => DepartmentPost::STATUS_OPEN,
        ]);

        $companyB = Company::query()->create([
            'name' => 'Company B',
            'slug' => 'company-b',
            'is_active' => true,
            'subscription_status' => 'active',
        ]);
        $deptB = Department::query()->withoutGlobalScope('tenant')->create([
            'name' => 'Sales B',
            'description' => null,
            'is_active' => true,
            'company_id' => $companyB->id,
        ]);
        $adminB = User::factory()->create(['company_id' => $companyB->id]);
        $adminB->assignRole('administrator');

        for ($i = 0; $i < 3; $i++) {
            DepartmentPost::query()->create([
                'department_id' => $deptB->id,
                'author_id' => $adminB->id,
                'title' => "Task B {$i}",
                'body' => 'Body',
                'status' => DepartmentPost::STATUS_IN_PROGRESS,
            ]);
        }

        $this->switchTenant($companyA);

        $this->actingAs($adminA)
            ->get(route('organization.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('orgOpenTasksCount', 1));
    }

    public function test_team_chat_unread_badge_counts_only_current_tenant(): void
    {
        $companyA = $this->createTenantCompany(['name' => 'Company A']);
        $deptA = Department::query()->create([
            'name' => 'Ops A',
            'description' => null,
            'is_active' => true,
            'company_id' => $companyA->id,
        ]);
        $adminA = User::factory()->create(['company_id' => $companyA->id]);
        $memberA = User::factory()->create(['company_id' => $companyA->id]);
        $adminA->assignRole('administrator');
        $memberA->assignRole('employee');
        $deptA->users()->attach($memberA->id);

        $conversationA = app(TeamDepartmentChatSyncService::class)->ensureDepartmentConversation($deptA);
        app(TeamDepartmentChatSyncService::class)->syncAllMembers($deptA);

        TeamMessage::query()->create([
            'team_conversation_id' => $conversationA->id,
            'sender_id' => $memberA->id,
            'body' => 'Unread in A',
        ]);

        $companyB = Company::query()->create([
            'name' => 'Company B',
            'slug' => 'company-b-chat',
            'is_active' => true,
            'subscription_status' => 'active',
        ]);
        $deptB = Department::query()->withoutGlobalScope('tenant')->create([
            'name' => 'Ops B',
            'description' => null,
            'is_active' => true,
            'company_id' => $companyB->id,
        ]);
        $memberB = User::factory()->create(['company_id' => $companyB->id]);
        $memberB->assignRole('employee');
        $deptB->users()->attach($memberB->id);

        $this->switchTenant($companyB);
        $conversationB = app(TeamDepartmentChatSyncService::class)->ensureDepartmentConversation($deptB);
        $this->switchTenant($companyA);

        $conversationB->participants()->syncWithoutDetaching([
            $adminA->id => [
                'can_leave' => false,
            ],
        ]);

        for ($i = 0; $i < 5; $i++) {
            TeamMessage::query()->create([
                'team_conversation_id' => $conversationB->id,
                'sender_id' => $memberB->id,
                'body' => "Unread in B {$i}",
            ]);
        }

        $this->switchTenant($companyA);

        $this->actingAs($adminA)
            ->get(route('organization.team-chat.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('teamChatUnreadCount', 1));
    }
}
