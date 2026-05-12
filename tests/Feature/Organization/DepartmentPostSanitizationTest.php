<?php

declare(strict_types=1);

namespace Tests\Feature\Organization;

use App\Models\Department;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

final class DepartmentPostSanitizationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['administrator', 'manager', 'employee'] as $r) {
            Role::findOrCreate($r);
        }
    }

    public function test_post_body_is_sanitized_on_create(): void
    {
        $department = Department::query()->create([
            'name' => 'QA Dept',
            'description' => null,
            'is_active' => true,
        ]);

        $user = User::factory()->create();
        $user->assignRole('employee');
        $user->departments()->attach($department->id);

        $malicious = '<p>Text</p><script>document.cookie</script>';

        $response = $this->actingAs($user)
            ->postJson(route('organization.posts.store', $department), [
                'title' => 'Task',
                'body' => $malicious,
                'status' => 'open',
            ]);

        $response->assertOk();
        $body = (string) ($response->json('post.body') ?? '');
        $this->assertStringNotContainsString('<script', strtolower($body));
        $this->assertStringContainsString('Text', $body);
    }
}
