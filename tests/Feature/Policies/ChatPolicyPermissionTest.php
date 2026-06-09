<?php

declare(strict_types=1);

namespace Tests\Feature\Policies;

use App\Models\Chat;
use App\Models\Department;
use App\Models\User;
use App\Services\TenantRoleService;
use App\Support\TenantRoles;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class ChatPolicyPermissionTest extends TestCase
{
    use RefreshDatabase;

    public function test_custom_role_with_department_view_can_see_department_chat(): void
    {
        $company = $this->createTenantCompany(['slug' => 'chat-policy']);
        $department = Department::query()->create([
            'company_id' => $company->id,
            'name' => 'Отдел продаж',
            'is_active' => true,
        ]);

        $service = app(TenantRoleService::class);
        $service->create($company->id, 'dept_viewer', ['chats.view_department', 'chats.send']);

        $user = User::factory()->create(['company_id' => $company->id]);
        $user->departments()->sync([$department->id]);
        TenantRoles::assign($user, 'dept_viewer');

        $chat = Chat::factory()->create(['company_id' => $company->id]);
        $chat->departments()->sync([$department->id]);

        $this->assertTrue($user->can('view', $chat));
    }

     public function test_custom_role_without_assign_cannot_assign_chat(): void
    {
        $company = $this->createTenantCompany(['slug' => 'chat-assign']);
        $department = Department::query()->create([
            'company_id' => $company->id,
            'name' => 'Отдел продаж',
            'is_active' => true,
        ]);

        app(TenantRoleService::class)->create($company->id, 'viewer_only', ['chats.view_department']);

        $user = User::factory()->create(['company_id' => $company->id]);
        $user->departments()->sync([$department->id]);
        TenantRoles::assign($user, 'viewer_only');

        $chat = Chat::factory()->create(['company_id' => $company->id]);
        $chat->departments()->sync([$department->id]);

        $this->assertTrue($user->can('view', $chat));
        $this->assertFalse($user->can('assign', $chat));
    }
}
