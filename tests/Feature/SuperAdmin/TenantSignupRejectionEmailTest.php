<?php

declare(strict_types=1);

namespace Tests\Feature\SuperAdmin;

use App\Mail\TenantSignupRejectedMail;
use App\Models\TenantSignupRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

final class TenantSignupRejectionEmailTest extends TestCase
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

    public function test_rejecting_signup_request_sends_rejection_email(): void
    {
        Mail::fake();

        $admin = User::factory()->create(['is_super_admin' => true, 'company_id' => null]);

        $signup = TenantSignupRequest::query()->create([
            'company_name' => 'Rejected Co',
            'desired_slug' => 'rejected-co',
            'contact_name' => 'Дана',
            'email' => 'owner@rejected-co.test',
            'phone' => '+77001234567',
            'status' => 'pending',
        ]);

        $host = $this->adminHost();
        URL::forceRootUrl('https://'.$host);

        $response = $this->actingAs($admin)->post(
            "https://{$host}/signup-requests/{$signup->id}/reject",
        );

        $response->assertRedirect();
        $response->assertSessionHas('success');

        Mail::assertSent(TenantSignupRejectedMail::class, function (TenantSignupRejectedMail $mail) use ($signup): bool {
            return $mail->hasTo($signup->email)
                && $mail->signupRequest->company_name === 'Rejected Co';
        });

        $this->assertDatabaseHas('super_admin_audit_logs', [
            'action' => 'tenant.rejection_email_sent',
        ]);

        $this->assertDatabaseHas('tenant_signup_requests', [
            'id' => $signup->id,
            'status' => 'rejected',
        ]);
    }
}
