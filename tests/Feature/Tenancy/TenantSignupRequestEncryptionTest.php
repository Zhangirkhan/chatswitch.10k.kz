<?php

declare(strict_types=1);

namespace Tests\Feature\Tenancy;

use App\Models\TenantSignupRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class TenantSignupRequestEncryptionTest extends TestCase
{
    use RefreshDatabase;

    public function test_signup_request_pii_is_encrypted_at_rest_and_readable_via_model(): void
    {
        $request = TenantSignupRequest::query()->create([
            'company_name' => 'ТОО Тест',
            'bin' => '123456789012',
            'desired_slug' => 'test-co',
            'contact_name' => 'Иван Иванов',
            'email' => 'owner@example.com',
            'phone' => '77001234567',
            'message' => 'Комментарий',
            'terms_accepted_at' => now(),
            'status' => 'pending',
        ]);

        $raw = DB::table('tenant_signup_requests')->where('id', $request->id)->first();

        $this->assertNotSame('ТОО Тест', $raw->company_name);
        $this->assertNotSame('owner@example.com', $raw->email);

        $fresh = TenantSignupRequest::query()->findOrFail($request->id);

        $this->assertSame('ТОО Тест', $fresh->company_name);
        $this->assertSame('123456789012', $fresh->bin);
        $this->assertSame('test-co', $fresh->desired_slug);
        $this->assertSame('owner@example.com', $fresh->email);
    }
}
