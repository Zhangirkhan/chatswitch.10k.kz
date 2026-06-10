<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1;

use App\Models\Department;
use App\Models\DepartmentPost;
use App\Models\DepartmentPostComment;
use App\Models\SystemSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
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

    public function test_department_member_can_manage_comments(): void
    {
        [$department, $user] = $this->departmentMember();

        $post = DepartmentPost::query()->create([
            'department_id' => $department->id,
            'author_id' => $user->id,
            'title' => 'Task with comments',
            'status' => DepartmentPost::STATUS_OPEN,
        ]);

        Sanctum::actingAs($user);

        $this->getJson("/api/v1/posts/{$post->id}/comments")
            ->assertOk()
            ->assertJsonCount(0, 'data');

        $create = $this->postJson("/api/v1/posts/{$post->id}/comments", [
            'body' => 'Нужно уточнить сроки',
        ]);

        $create->assertCreated()
            ->assertJsonPath('data.body', 'Нужно уточнить сроки')
            ->assertJsonPath('data.author.id', $user->id);

        $commentId = (int) $create->json('data.id');

        $this->getJson("/api/v1/posts/{$post->id}/comments")
            ->assertOk()
            ->assertJsonCount(1, 'data');

        $this->deleteJson("/api/v1/posts/{$post->id}/comments/{$commentId}")
            ->assertOk()
            ->assertJson(['success' => true]);

        $this->assertDatabaseMissing('department_post_comments', ['id' => $commentId]);
    }

    public function test_employee_cannot_delete_foreign_comment(): void
    {
        [$department, $author] = $this->departmentMember();

        $post = DepartmentPost::query()->create([
            'department_id' => $department->id,
            'author_id' => $author->id,
            'title' => 'Task',
            'status' => DepartmentPost::STATUS_OPEN,
        ]);

        $comment = DepartmentPostComment::query()->create([
            'department_post_id' => $post->id,
            'author_id' => $author->id,
            'body' => 'Author comment',
        ]);

        $otherMember = User::factory()->create(['company_id' => $author->company_id]);
        $otherMember->assignRole('employee');
        $otherMember->departments()->attach($department->id);

        Sanctum::actingAs($otherMember);

        $this->deleteJson("/api/v1/posts/{$post->id}/comments/{$comment->id}")
            ->assertForbidden();
    }

    public function test_department_member_can_upload_and_delete_attachment(): void
    {
        Storage::fake('public');

        [$department, $user] = $this->departmentMember();

        $post = DepartmentPost::query()->create([
            'department_id' => $department->id,
            'author_id' => $user->id,
            'title' => 'Task with files',
            'status' => DepartmentPost::STATUS_OPEN,
        ]);

        Sanctum::actingAs($user);

        $upload = $this->postJson("/api/v1/posts/{$post->id}/attachments", [
            'file' => UploadedFile::fake()->create('brief.pdf', 120, 'application/pdf'),
        ]);

        $upload->assertCreated()
            ->assertJsonPath('data.original_name', 'brief.pdf')
            ->assertJsonPath('data.uploaded_by', $user->id);

        $attachmentId = (int) $upload->json('data.id');

        $this->deleteJson("/api/v1/posts/{$post->id}/attachments/{$attachmentId}")
            ->assertOk()
            ->assertJson(['success' => true]);

        $this->assertDatabaseMissing('department_post_attachments', ['id' => $attachmentId]);
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
