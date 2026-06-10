<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1;

use App\Models\Department;
use App\Models\DepartmentPost;
use App\Models\SystemSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

final class DepartmentPostApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['accel.organization_department_tasks' => true]);
        SystemSetting::setValue('module_tasks', 'on');

        foreach (['administrator', 'manager', 'employee'] as $roleName) {
            Role::findOrCreate($roleName);
        }
    }

    public function test_department_member_can_list_active_posts(): void
    {
        [$department, $user] = $this->departmentMember();

        DepartmentPost::query()->create([
            'department_id' => $department->id,
            'author_id' => $user->id,
            'title' => 'Active task',
            'status' => DepartmentPost::STATUS_OPEN,
        ]);
        DepartmentPost::query()->create([
            'department_id' => $department->id,
            'author_id' => $user->id,
            'title' => 'Done task',
            'status' => DepartmentPost::STATUS_DONE,
        ]);

        Sanctum::actingAs($user);

        $this->getJson("/api/v1/departments/{$department->id}/posts")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.title', 'Active task')
            ->assertJsonPath('data.0.status', DepartmentPost::STATUS_OPEN);
    }

    public function test_department_member_can_create_update_complete_and_delete_post(): void
    {
        [$department, $user] = $this->departmentMember();
        Sanctum::actingAs($user);

        $create = $this->postJson("/api/v1/departments/{$department->id}/posts", [
            'title' => 'Подготовить КП',
            'body' => 'Описание задачи',
            'status' => DepartmentPost::STATUS_OPEN,
        ]);

        $create->assertCreated()
            ->assertJsonPath('data.title', 'Подготовить КП')
            ->assertJsonPath('data.author.id', $user->id);

        $postId = (int) $create->json('data.id');

        $this->getJson("/api/v1/posts/{$postId}")
            ->assertOk()
            ->assertJsonPath('data.id', $postId);

        $this->patchJson("/api/v1/posts/{$postId}", [
            'title' => 'Подготовить КП v2',
            'status' => DepartmentPost::STATUS_IN_PROGRESS,
        ])->assertOk()
            ->assertJsonPath('data.title', 'Подготовить КП v2')
            ->assertJsonPath('data.status', DepartmentPost::STATUS_IN_PROGRESS);

        $this->postJson("/api/v1/posts/{$postId}/complete")
            ->assertOk()
            ->assertJsonPath('data.status', DepartmentPost::STATUS_DONE);

        $this->deleteJson("/api/v1/posts/{$postId}")
            ->assertOk()
            ->assertJson(['success' => true]);

        $this->assertDatabaseMissing('department_posts', ['id' => $postId]);
    }

    public function test_non_member_cannot_access_department_posts(): void
    {
        $department = Department::query()->create([
            'name' => 'Sales',
            'description' => null,
            'is_active' => true,
        ]);

        $outsider = User::factory()->create();
        $outsider->assignRole('employee');

        Sanctum::actingAs($outsider);

        $this->getJson("/api/v1/departments/{$department->id}/posts")->assertForbidden();
        $this->postJson("/api/v1/departments/{$department->id}/posts", [
            'title' => 'Forbidden task',
        ])->assertForbidden();
    }

    public function test_employee_cannot_update_someone_else_post(): void
    {
        [$department, $author] = $this->departmentMember();

        $post = DepartmentPost::query()->create([
            'department_id' => $department->id,
            'author_id' => $author->id,
            'title' => 'Foreign task',
            'status' => DepartmentPost::STATUS_OPEN,
        ]);

        $otherMember = User::factory()->create(['company_id' => $author->company_id]);
        $otherMember->assignRole('employee');
        $otherMember->departments()->attach($department->id);

        Sanctum::actingAs($otherMember);

        $this->patchJson("/api/v1/posts/{$post->id}", [
            'title' => 'Hack',
        ])->assertForbidden();
    }

    public function test_administrator_can_update_foreign_post(): void
    {
        [$department, $author] = $this->departmentMember();

        $post = DepartmentPost::query()->create([
            'department_id' => $department->id,
            'author_id' => $author->id,
            'title' => 'Team task',
            'status' => DepartmentPost::STATUS_OPEN,
        ]);

        $admin = User::factory()->create(['company_id' => $author->company_id]);
        $admin->assignRole('administrator');

        Sanctum::actingAs($admin);

        $this->patchJson("/api/v1/posts/{$post->id}", [
            'title' => 'Updated by admin',
        ])->assertOk()
            ->assertJsonPath('data.title', 'Updated by admin');
    }

    public function test_unauthenticated_requests_are_rejected(): void
    {
        [$department] = $this->departmentMember();

        $this->getJson("/api/v1/departments/{$department->id}/posts")->assertUnauthorized();
    }

    /**
     * @return array{0: Department, 1: User}
     */
    private function departmentMember(): array
    {
        $department = Department::query()->create([
            'name' => 'Operations',
            'description' => null,
            'is_active' => true,
        ]);

        $user = User::factory()->create();
        $user->assignRole('employee');
        $user->departments()->attach($department->id);

        return [$department, $user];
    }
}
