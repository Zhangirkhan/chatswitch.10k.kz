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

final class ContactMessageTest extends TestCase
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

    private function sandboxSuperAdmin(): User
    {
        return User::factory()->create([
            'is_super_admin' => true,
            'company_id' => null,
            'super_admin_scope' => 'sandbox',
        ]);
    }

    private function createFeedback(Company $company, User $author, array $overrides = []): UserFeedback
    {
        return UserFeedback::query()->create(array_merge([
            'company_id' => $company->id,
            'user_id' => $author->id,
            'source' => UserFeedbackSource::Web,
            'type' => UserFeedbackType::Complaint,
            'message' => 'Пользователь сообщает о проблеме в интерфейсе чатов.',
            'status' => UserFeedbackStatus::New,
        ], $overrides));
    }

    public function test_global_super_admin_can_list_contact_messages(): void
    {
        $admin = $this->globalSuperAdmin();
        $company = Company::query()->withoutGlobalScope('tenant')->firstOrFail();
        $author = User::factory()->create(['company_id' => $company->id]);
        $this->createFeedback($company, $author);

        $host = $this->adminHost();
        URL::forceRootUrl('https://'.$host);

        $this->withoutVite()
            ->actingAs($admin)
            ->get("https://{$host}/contact-messages")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('SuperAdmin/ContactMessages/Index')
                ->has('messages.data', 1));
    }

    public function test_sandbox_super_admin_cannot_access_contact_messages(): void
    {
        $admin = $this->sandboxSuperAdmin();
        $host = $this->adminHost();
        URL::forceRootUrl('https://'.$host);

        $this->actingAs($admin)
            ->get("https://{$host}/contact-messages")
            ->assertForbidden();
    }

    public function test_global_super_admin_can_filter_by_status(): void
    {
        $admin = $this->globalSuperAdmin();
        $company = Company::query()->withoutGlobalScope('tenant')->firstOrFail();
        $author = User::factory()->create(['company_id' => $company->id]);

        $this->createFeedback($company, $author, ['status' => UserFeedbackStatus::New]);
        $this->createFeedback($company, $author, [
            'status' => UserFeedbackStatus::Resolved,
            'message' => 'Второе обращение уже закрыто администратором.',
        ]);

        $host = $this->adminHost();
        URL::forceRootUrl('https://'.$host);

        $this->withoutVite()
            ->actingAs($admin)
            ->get("https://{$host}/contact-messages?status=new")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('messages.data', 1)
                ->where('messages.data.0.status', 'new'));
    }

    public function test_global_super_admin_can_mark_message_as_read(): void
    {
        $admin = $this->globalSuperAdmin();
        $company = Company::query()->withoutGlobalScope('tenant')->firstOrFail();
        $author = User::factory()->create(['company_id' => $company->id]);
        $feedback = $this->createFeedback($company, $author);

        $host = $this->adminHost();
        URL::forceRootUrl('https://'.$host);

        $this->actingAs($admin)
            ->patch("https://{$host}/contact-messages/{$feedback->id}/read", [
                'admin_note' => 'Передано в поддержку.',
            ])
            ->assertRedirect();

        $feedback->refresh();
        $this->assertSame(UserFeedbackStatus::Read, $feedback->status);
        $this->assertSame('Передано в поддержку.', $feedback->admin_note);
    }

    public function test_global_super_admin_can_resolve_message(): void
    {
        $admin = $this->globalSuperAdmin();
        $company = Company::query()->withoutGlobalScope('tenant')->firstOrFail();
        $author = User::factory()->create(['company_id' => $company->id]);
        $feedback = $this->createFeedback($company, $author);

        $host = $this->adminHost();
        URL::forceRootUrl('https://'.$host);

        $this->actingAs($admin)
            ->patch("https://{$host}/contact-messages/{$feedback->id}/resolve", [
                'admin_note' => 'Исправлено в релизе 1.2.1.',
            ])
            ->assertRedirect();

        $feedback->refresh();
        $this->assertSame(UserFeedbackStatus::Resolved, $feedback->status);
        $this->assertSame($admin->id, $feedback->resolved_by_user_id);
        $this->assertNotNull($feedback->resolved_at);
    }
}
