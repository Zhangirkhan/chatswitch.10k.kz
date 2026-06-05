<?php

declare(strict_types=1);

namespace Tests\Feature\SuperAdmin;

use App\Models\Chat;
use App\Models\Company;
use App\Models\Contact;
use App\Models\Message;
use App\Models\Plan;
use App\Models\User;
use App\Models\WhatsappSession;
use App\Services\Company\DemoChatsFactory;
use App\Services\SuperAdmin\DemoTenantPopulationService;
use App\Services\SuperAdmin\TenantSandboxCleanupService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

final class TenantSandboxCleanupTest extends TestCase
{
    use RefreshDatabase;

    private function adminHost(): string
    {
        return config('tenancy.admin_subdomain', 'app').'.'.config('tenancy.root_domain', 'accel.kz');
    }

    private function sandboxAdmin(): User
    {
        $admin = User::factory()->create([
            'is_super_admin' => true,
            'company_id' => null,
            'super_admin_scope' => 'sandbox',
        ]);

        return $admin;
    }

    private function createSandboxCompany(User $admin): Company
    {
        $plan = Plan::query()->firstOrCreate(
            ['code' => 'standard'],
            [
                'name' => 'Стандарт',
                'price_cents' => 4_000_000,
                'currency' => 'KZT',
                'interval' => 'month',
                'trial_days' => 14,
                'is_active' => true,
            ],
        );

        return Company::query()->create([
            'name' => 'Sandbox Co',
            'slug' => 'sandbox-co-'.uniqid(),
            'is_active' => true,
            'plan_id' => $plan->id,
            'subscription_status' => 'trial',
            'provisioned_by_user_id' => $admin->id,
        ]);
    }

    public function test_clear_sandbox_data_removes_only_marked_records(): void
    {
        $admin = $this->sandboxAdmin();
        $company = $this->createSandboxCompany($admin);

        app(DemoTenantPopulationService::class)->populateCompany($company, $admin);

        $session = WhatsappSession::query()
            ->withoutGlobalScope('tenant')
            ->where('company_id', $company->id)
            ->first();
        $this->assertNotNull($session);

        $realContact = Contact::query()->create([
            'company_id' => $company->id,
            'whatsapp_id' => '77009998877@c.us',
            'phone_number' => '77009998877',
            'name' => 'Реальный клиент',
            'is_sandbox' => false,
        ]);

        $realChat = Chat::query()->create([
            'company_id' => $company->id,
            'whatsapp_session_id' => $session->id,
            'whatsapp_chat_id' => '77009998877@c.us',
            'contact_id' => $realContact->id,
            'chat_name' => 'Реальный клиент',
            'is_sandbox' => false,
        ]);

        Message::query()->create([
            'chat_id' => $realChat->id,
            'whatsapp_session_id' => $session->id,
            'whatsapp_message_id' => 'real-msg-1',
            'direction' => 'inbound',
            'type' => 'chat',
            'body' => 'Живое сообщение',
            'message_timestamp' => now(),
        ]);

        $sandboxBefore = Chat::query()
            ->withoutGlobalScope('tenant')
            ->where('company_id', $company->id)
            ->where('is_sandbox', true)
            ->count();
        $this->assertGreaterThan(0, $sandboxBefore);

        $stats = app(TenantSandboxCleanupService::class)->clear($company, $admin);

        $this->assertGreaterThan(0, $stats['chats']);
        $this->assertSame(0, Chat::query()->withoutGlobalScope('tenant')->where('company_id', $company->id)->where('is_sandbox', true)->count());
        $this->assertDatabaseHas('chats', ['id' => $realChat->id]);
        $this->assertDatabaseHas('contacts', ['id' => $realContact->id]);
        $this->assertDatabaseHas('messages', ['whatsapp_message_id' => 'real-msg-1']);
    }

    public function test_clear_sandbox_data_route_requires_sandbox_super_admin(): void
    {
        $admin = $this->sandboxAdmin();
        $company = $this->createSandboxCompany($admin);
        $host = $this->adminHost();
        URL::forceRootUrl('https://'.$host);

        $this->actingAs($admin)->delete("https://{$host}/companies/{$company->id}/sandbox-data")
            ->assertRedirect()
            ->assertSessionHas('success');
    }

    public function test_demo_message_marker_matches_factory_ids(): void
    {
        $this->assertTrue(DemoChatsFactory::isDemoWhatsappMessageId('demo_11_22_0'));
        $this->assertFalse(DemoChatsFactory::isDemoWhatsappMessageId('false_33724234223783@lid_abc'));
    }
}
