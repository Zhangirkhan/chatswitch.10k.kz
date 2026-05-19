<?php

declare(strict_types=1);

namespace Tests\Feature\Settings;

use App\Models\CompanyToneProfile;
use App\Models\User;
use App\Models\WhatsappSession;
use App\Services\Company\CompanyOnboardingService;
use App\Support\TenantCompany;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

final class ToneProfilePageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['administrator', 'manager', 'employee'] as $role) {
            Role::findOrCreate($role);
        }
    }

    public function test_administrator_can_open_tone_profile_page(): void
    {
        $this->withoutVite();

        $admin = User::factory()->create();
        $admin->assignRole('administrator');

        $company = TenantCompany::ensureExists();
        $admin->forceFill(['company_id' => $company->id])->save();

        app(CompanyOnboardingService::class)->bootstrap($company, $admin);
        WhatsappSession::factory()->create(['status' => 'connected']);

        $this->actingAs($admin->fresh())
            ->get(route('settings.tone-profile'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Settings/ToneProfile')
                ->has('profile')
                ->has('employee_profiles'));
    }

    public function test_administrator_can_save_manual_tone_profile(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('administrator');

        $companyId = TenantCompany::id();

        $this->actingAs($admin)
            ->put(route('settings.tone-profile.update'), [
                'use_manual_override' => true,
                'manual_summary' => 'Коротко и по делу, на «вы».',
                'manual_phrases' => ['Добрый день', 'Будем рады помочь'],
            ])
            ->assertRedirect(route('settings.tone-profile'));

        $profile = CompanyToneProfile::query()->where('company_id', $companyId)->first();
        $this->assertNotNull($profile);
        $this->assertTrue($profile->use_manual_override);
        $this->assertSame('Коротко и по делу, на «вы».', $profile->manual_summary);
        $this->assertSame(['Добрый день', 'Будем рады помочь'], $profile->manual_phrases);
        $this->assertSame('Коротко и по делу, на «вы».', $profile->effectiveSummary());
    }

    public function test_manager_cannot_open_tone_profile_page(): void
    {
        $manager = User::factory()->create();
        $manager->assignRole('manager');

        $this->actingAs($manager)
            ->get(route('settings.tone-profile'))
            ->assertForbidden();
    }
}
