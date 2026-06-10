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

final class FeedbackApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['administrator', 'manager', 'employee'] as $roleName) {
            Role::findOrCreate($roleName);
        }
    }

    public function test_authenticated_user_can_submit_feedback(): void
    {
        $user = User::factory()->create();
        $user->assignRole('employee');
        Sanctum::actingAs($user);

        $response = $this->postJson('/api/v1/feedback', [
            'type' => 'complaint',
            'message' => 'Не приходят push-уведомления на Android.',
            'app_version' => '1.2.0',
            'device_platform' => 'android',
            'device_model' => 'Pixel 8',
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.type', 'complaint')
            ->assertJsonPath('data.source', 'mobile')
            ->assertJsonPath('data.status', 'new')
            ->assertJsonPath('data.app_version', '1.2.0');

        $this->assertDatabaseHas('user_feedback', [
            'user_id' => $user->id,
            'company_id' => $user->company_id,
            'source' => UserFeedbackSource::Mobile->value,
            'type' => UserFeedbackType::Complaint->value,
        ]);
    }

    public function test_user_can_list_own_feedback(): void
    {
        $user = User::factory()->create();
        $user->assignRole('manager');
        Sanctum::actingAs($user);

        UserFeedback::query()->create([
            'company_id' => $user->company_id,
            'user_id' => $user->id,
            'source' => UserFeedbackSource::Mobile,
            'type' => UserFeedbackType::Suggestion,
            'message' => 'Добавьте фильтр по непрочитанным чатам.',
            'status' => UserFeedbackStatus::Read,
        ]);

        $this->getJson('/api/v1/feedback')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.type', 'suggestion')
            ->assertJsonPath('data.0.status', 'read');
    }

    public function test_feedback_list_does_not_include_other_users_messages(): void
    {
        $user = User::factory()->create();
        $user->assignRole('employee');
        $other = User::factory()->create(['company_id' => $user->company_id]);
        $other->assignRole('employee');

        UserFeedback::query()->create([
            'company_id' => $other->company_id,
            'user_id' => $other->id,
            'source' => UserFeedbackSource::Mobile,
            'type' => UserFeedbackType::Complaint,
            'message' => 'Чужое обращение не должно попадать в список.',
            'status' => UserFeedbackStatus::New,
        ]);

        Sanctum::actingAs($user);

        $this->getJson('/api/v1/feedback')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_unauthenticated_feedback_requests_are_rejected(): void
    {
        $this->getJson('/api/v1/feedback')->assertUnauthorized();
        $this->postJson('/api/v1/feedback', [
            'type' => 'complaint',
            'message' => 'Сообщение достаточной длины для проверки.',
        ])->assertUnauthorized();
    }

    public function test_feedback_validation_errors(): void
    {
        $user = User::factory()->create();
        $user->assignRole('employee');
        Sanctum::actingAs($user);

        $this->postJson('/api/v1/feedback', [
            'type' => 'complaint',
            'message' => 'мало',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['message']);
    }

    public function test_sanctum_token_from_other_tenant_cannot_access_feedback(): void
    {
        $homeCompany = Company::query()
            ->withoutGlobalScope('tenant')
            ->where('slug', config('tenancy.fallback_slug', 'demo'))
            ->firstOrFail();

        $otherCompany = Company::query()->create([
            'name' => 'Other Tenant Feedback',
            'slug' => 'other-feedback-tenant',
            'is_active' => true,
            'subscription_status' => 'active',
        ]);

        $user = User::factory()->create(['company_id' => $homeCompany->id]);
        $user->assignRole('employee');
        Sanctum::actingAs($user);

        $this->postJson('/api/v1/feedback', [
            'type' => 'suggestion',
            'message' => 'Первое обращение на домашнем тенанте.',
        ])->assertCreated();

        $this->switchTenant($otherCompany);

        $this->getJson('/api/v1/feedback')->assertForbidden();
        $this->postJson('/api/v1/feedback', [
            'type' => 'suggestion',
            'message' => 'Попытка отправить с чужого поддомена.',
        ])->assertForbidden();
    }
}
