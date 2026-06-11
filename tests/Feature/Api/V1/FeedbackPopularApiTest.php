<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1;

use App\Enums\UserFeedbackSource;
use App\Enums\UserFeedbackStatus;
use App\Enums\UserFeedbackType;
use App\Models\Company;
use App\Models\User;
use App\Models\UserFeedback;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

final class FeedbackPopularApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['administrator', 'manager', 'employee'] as $roleName) {
            Role::findOrCreate($roleName);
        }
    }

    public function test_popular_returns_platform_wide_anonymous_entries(): void
    {
        $companyB = Company::query()->create([
            'name' => 'Tenant B Popular',
            'slug' => 'tenant-b-popular',
            'is_active' => true,
            'subscription_status' => 'active',
        ]);
        $userA = User::factory()->create();
        $userA->assignRole('employee');
        $userB = User::factory()->create(['company_id' => $companyB->id]);

        $popular = UserFeedback::query()->create([
            'company_id' => $companyB->id,
            'user_id' => $userB->id,
            'source' => UserFeedbackSource::Mobile,
            'type' => UserFeedbackType::Suggestion,
            'message' => 'Нужна диктовка в AI-чате для операторов.',
            'status' => UserFeedbackStatus::New,
            'likes_count' => 5,
            'is_diagnostic' => false,
        ]);

        UserFeedback::query()->create([
            'company_id' => $userA->company_id,
            'user_id' => $userA->id,
            'source' => UserFeedbackSource::Mobile,
            'type' => UserFeedbackType::Complaint,
            'message' => '[diagnostic] Crash report details here enough length.',
            'status' => UserFeedbackStatus::New,
            'likes_count' => 99,
            'is_diagnostic' => true,
        ]);

        Sanctum::actingAs($userA);

        $this->getJson('/api/v1/feedback/popular?limit=10')
            ->assertOk()
            ->assertJsonPath('data.entries.0.id', $popular->id)
            ->assertJsonPath('data.entries.0.likes_count', 5)
            ->assertJsonPath('data.entries.0.liked_by_me', false)
            ->assertJsonMissingPath('data.entries.0.user_id')
            ->assertJsonCount(1, 'data.entries');
    }

    public function test_user_can_like_and_unlike_feedback(): void
    {
        $user = User::factory()->create();
        $user->assignRole('employee');

        $feedback = UserFeedback::query()->create([
            'company_id' => $user->company_id,
            'user_id' => $user->id,
            'source' => UserFeedbackSource::Mobile,
            'type' => UserFeedbackType::Suggestion,
            'message' => 'Добавьте фильтр по непрочитанным чатам в mobile.',
            'status' => UserFeedbackStatus::New,
            'likes_count' => 0,
            'is_diagnostic' => false,
        ]);

        Sanctum::actingAs($user);

        $this->postJson("/api/v1/feedback/{$feedback->id}/like")
            ->assertOk()
            ->assertJsonPath('data.likes_count', 1)
            ->assertJsonPath('data.liked_by_me', true);

        $this->postJson("/api/v1/feedback/{$feedback->id}/like")
            ->assertOk()
            ->assertJsonPath('data.likes_count', 1)
            ->assertJsonPath('data.liked_by_me', true);

        $this->deleteJson("/api/v1/feedback/{$feedback->id}/like")
            ->assertOk()
            ->assertJsonPath('data.likes_count', 0)
            ->assertJsonPath('data.liked_by_me', false);

        $this->assertDatabaseMissing('user_feedback_likes', [
            'user_feedback_id' => $feedback->id,
            'user_id' => $user->id,
        ]);
    }

    public function test_diagnostic_feedback_cannot_be_liked(): void
    {
        $user = User::factory()->create();
        $user->assignRole('employee');

        $feedback = UserFeedback::query()->create([
            'company_id' => $user->company_id,
            'user_id' => $user->id,
            'source' => UserFeedbackSource::Mobile,
            'type' => UserFeedbackType::Complaint,
            'message' => '[diagnostic] Something went wrong on startup today.',
            'status' => UserFeedbackStatus::New,
            'likes_count' => 0,
            'is_diagnostic' => true,
        ]);

        Sanctum::actingAs($user);

        $this->postJson("/api/v1/feedback/{$feedback->id}/like")
            ->assertUnprocessable();
    }

    public function test_unlike_returns_404_when_not_liked(): void
    {
        $user = User::factory()->create();
        $user->assignRole('employee');

        $feedback = UserFeedback::query()->create([
            'company_id' => $user->company_id,
            'user_id' => $user->id,
            'source' => UserFeedbackSource::Mobile,
            'type' => UserFeedbackType::Suggestion,
            'message' => 'Хочу видеть статистику по воронкам в mobile.',
            'status' => UserFeedbackStatus::New,
            'likes_count' => 0,
            'is_diagnostic' => false,
        ]);

        Sanctum::actingAs($user);

        $this->deleteJson("/api/v1/feedback/{$feedback->id}/like")
            ->assertNotFound();
    }

    public function test_popular_requires_authentication(): void
    {
        $this->getJson('/api/v1/feedback/popular')->assertUnauthorized();
    }
}
