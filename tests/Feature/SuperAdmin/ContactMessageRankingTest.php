<?php

declare(strict_types=1);

namespace Tests\Feature\SuperAdmin;

use App\Enums\UserFeedbackSource;
use App\Enums\UserFeedbackStatus;
use App\Enums\UserFeedbackType;
use App\Models\Company;
use App\Models\User;
use App\Models\UserFeedback;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\URL;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

final class ContactMessageRankingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Role::findOrCreate('administrator', 'web');
    }

    private function adminHost(): string
    {
        return config('tenancy.admin_subdomain', 'app').'.'.config('tenancy.root_domain', 'accel.kz');
    }

    private function globalSuperAdmin(): User
    {
        return User::factory()->create([
            'is_super_admin' => true,
            'company_id' => null,
            'super_admin_scope' => 'global',
        ]);
    }

    public function test_super_admin_can_view_feedback_ranking(): void
    {
        URL::forceRootUrl('https://'.$this->adminHost());

        $company = Company::query()->create([
            'name' => 'Ranking Tenant',
            'slug' => 'ranking-tenant',
            'is_active' => true,
            'subscription_status' => 'active',
        ]);
        $author = User::factory()->create(['company_id' => $company->id]);

        UserFeedback::query()->create([
            'company_id' => $company->id,
            'user_id' => $author->id,
            'source' => UserFeedbackSource::Mobile,
            'type' => UserFeedbackType::Suggestion,
            'message' => 'Popular suggestion with enough text length.',
            'status' => UserFeedbackStatus::New,
            'likes_count' => 12,
            'is_diagnostic' => false,
        ]);

        $admin = $this->globalSuperAdmin();

        $this->actingAs($admin)
            ->get('https://'.$this->adminHost().'/contact-messages/ranking')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('SuperAdmin/ContactMessages/Ranking')
                ->has('messages.data', 1)
                ->where('messages.data.0.likes_count', 12));
    }
}
