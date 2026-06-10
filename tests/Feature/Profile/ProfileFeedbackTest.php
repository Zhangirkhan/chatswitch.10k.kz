<?php

declare(strict_types=1);

namespace Tests\Feature\Profile;

use App\Enums\UserFeedbackSource;
use App\Enums\UserFeedbackStatus;
use App\Enums\UserFeedbackType;
use App\Models\User;
use App\Models\UserFeedback;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

final class ProfileFeedbackTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['administrator', 'manager', 'employee'] as $roleName) {
            Role::findOrCreate($roleName);
        }
    }

    /**
     * @dataProvider tenantRoleProvider
     */
    public function test_user_can_submit_feedback_from_profile(string $roleName): void
    {
        $user = User::factory()->create();
        $this->assignTenantRole($user, $roleName);

        $response = $this->actingAs($user)->post('/profile/feedback', [
            'type' => 'suggestion',
            'message' => 'Хотелось бы видеть тёмную тему в мобильном приложении.',
        ]);

        $response
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('profile.edit', ['section' => 'contact']));

        $this->assertDatabaseHas('user_feedback', [
            'user_id' => $user->id,
            'company_id' => $user->company_id,
            'source' => UserFeedbackSource::Web->value,
            'type' => UserFeedbackType::Suggestion->value,
            'status' => UserFeedbackStatus::New->value,
        ]);
    }

    public function test_feedback_requires_valid_message_length(): void
    {
        $user = User::factory()->create();
        $this->assignTenantRole($user, 'employee');

        $this->actingAs($user)->post('/profile/feedback', [
            'type' => 'complaint',
            'message' => 'коротко',
        ])->assertSessionHasErrors('message');

        $this->assertDatabaseCount('user_feedback', 0);
    }

    public function test_feedback_requires_valid_type(): void
    {
        $user = User::factory()->create();
        $this->assignTenantRole($user, 'employee');

        $this->actingAs($user)->post('/profile/feedback', [
            'type' => 'invalid',
            'message' => 'Сообщение достаточной длины для проверки.',
        ])->assertSessionHasErrors('type');
    }

    public function test_guest_cannot_submit_feedback(): void
    {
        $this->post('/profile/feedback', [
            'type' => 'complaint',
            'message' => 'Сообщение достаточной длины для проверки.',
        ])->assertRedirect('/login');
    }

    public function test_profile_contact_section_shows_recent_feedback(): void
    {
        $user = User::factory()->create();
        $this->assignTenantRole($user, 'manager');

        UserFeedback::query()->create([
            'company_id' => $user->company_id,
            'user_id' => $user->id,
            'source' => UserFeedbackSource::Web,
            'type' => UserFeedbackType::Complaint,
            'message' => 'Проблема с синхронизацией чатов в приложении.',
            'status' => UserFeedbackStatus::New,
        ]);

        $this->actingAs($user)
            ->withoutVite()
            ->get('/profile?section=contact')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Profile/Edit')
                ->has('feedbackItems', 1)
                ->where('feedbackItems.0.type', 'complaint'));
    }

    /** @return array<string, array{0: string}> */
    public static function tenantRoleProvider(): array
    {
        return [
            'administrator' => ['administrator'],
            'manager' => ['manager'],
            'employee' => ['employee'],
        ];
    }
}
