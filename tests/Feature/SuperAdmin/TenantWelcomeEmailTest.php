<?php

declare(strict_types=1);

namespace Tests\Feature\SuperAdmin;

use App\Mail\TenantWelcomeMail;
use App\Models\TenantSignupRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

final class TenantWelcomeEmailTest extends TestCase
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

    public function test_approving_signup_request_sends_welcome_email(): void
    {
        Mail::fake();

        $admin = User::factory()->create(['is_super_admin' => true, 'company_id' => null]);

        $signup = TenantSignupRequest::query()->create([
            'company_name' => 'Welcome Co',
            'desired_slug' => 'welcome-co',
            'contact_name' => 'Алия',
            'email' => 'owner@welcome-co.test',
            'phone' => '+77001234567',
            'status' => 'pending',
        ]);

        $host = $this->adminHost();
        URL::forceRootUrl('https://'.$host);

        $response = $this->actingAs($admin)->post(
            "https://{$host}/signup-requests/{$signup->id}/approve",
            ['create_company' => '1'],
        );

        $response->assertRedirect();
        $response->assertSessionHas('success');

        Mail::assertSent(TenantWelcomeMail::class, function (TenantWelcomeMail $mail) use ($signup): bool {
            return $mail->hasTo($signup->email)
                && $mail->company->slug === 'welcome-co'
                && str_contains($mail->loginUrl, 'welcome-co.');
        });

        $this->assertDatabaseHas('super_admin_audit_logs', [
            'action' => 'tenant.welcome_email_sent',
        ]);
    }
}
