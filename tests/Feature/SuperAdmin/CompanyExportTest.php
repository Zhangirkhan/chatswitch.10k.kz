<?php

declare(strict_types=1);

namespace Tests\Feature\SuperAdmin;

use App\Models\Company;
use App\Models\Plan;
use App\Models\TenantSignupRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;
use OpenSpout\Reader\XLSX\Reader as XlsxReader;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

final class CompanyExportTest extends TestCase
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
        ]);
    }

    private function plan(): Plan
    {
        return Plan::query()->firstOrCreate(
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
    }

    public function test_global_super_admin_can_export_companies_as_xlsx(): void
    {
        $plan = $this->plan();

        Company::query()->create([
            'name' => 'Export Alpha',
            'slug' => 'export-alpha',
            'bin' => '123456789012',
            'legal_address' => 'Алматы, ул. Примерная 1',
            'business_activity' => 'IT услуги',
            'is_active' => true,
            'plan_id' => $plan->id,
            'subscription_status' => 'trial',
        ]);

        Company::query()->create([
            'name' => 'Export Beta',
            'slug' => 'export-beta',
            'is_active' => true,
            'plan_id' => $plan->id,
            'subscription_status' => 'active',
        ]);

        $host = $this->adminHost();
        URL::forceRootUrl('https://'.$host);

        $response = $this->actingAs($this->globalSuperAdmin())->get("https://{$host}/companies/export");

        $response->assertOk();
        $response->assertHeader(
            'content-type',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        );

        $content = $response->streamedContent();
        $this->assertStringStartsWith('PK', $content);

        $rows = $this->readXlsxRows($content);
        $this->assertGreaterThanOrEqual(3, count($rows));
        $this->assertSame('ID', $rows[0][0]);
        $this->assertSame('Export Alpha', $rows[1][1]);
        $this->assertSame('123456789012', $rows[1][4]);
        $this->assertSame('IT услуги', $rows[1][6]);
    }

    public function test_export_respects_search_filter(): void
    {
        $plan = $this->plan();

        Company::query()->create([
            'name' => 'Filtered Co',
            'slug' => 'filtered-co',
            'is_active' => true,
            'plan_id' => $plan->id,
            'subscription_status' => 'trial',
        ]);

        Company::query()->create([
            'name' => 'Other Co',
            'slug' => 'other-co',
            'is_active' => true,
            'plan_id' => $plan->id,
            'subscription_status' => 'trial',
        ]);

        $host = $this->adminHost();
        URL::forceRootUrl('https://'.$host);

        $response = $this->actingAs($this->globalSuperAdmin())->get(
            "https://{$host}/companies/export?q=Filtered",
        );

        $response->assertOk();
        $rows = $this->readXlsxRows($response->streamedContent());
        $this->assertCount(2, $rows);
        $this->assertSame('Filtered Co', $rows[1][1]);
    }

    public function test_sandbox_super_admin_exports_only_provisioned_companies(): void
    {
        $plan = $this->plan();

        $sandboxAdmin = User::factory()->create([
            'is_super_admin' => true,
            'company_id' => null,
            'super_admin_scope' => 'sandbox',
        ]);

        Company::query()->create([
            'name' => 'Mine Co',
            'slug' => 'mine-co-export',
            'is_active' => true,
            'plan_id' => $plan->id,
            'subscription_status' => 'trial',
            'provisioned_by_user_id' => $sandboxAdmin->id,
        ]);

        Company::query()->create([
            'name' => 'Foreign Co',
            'slug' => 'foreign-co-export',
            'is_active' => true,
            'plan_id' => $plan->id,
            'subscription_status' => 'trial',
        ]);

        $host = $this->adminHost();
        URL::forceRootUrl('https://'.$host);

        $response = $this->actingAs($sandboxAdmin)->get("https://{$host}/companies/export");
        $response->assertOk();

        $rows = $this->readXlsxRows($response->streamedContent());
        $this->assertCount(2, $rows);
        $this->assertSame('Mine Co', $rows[1][1]);
    }

    public function test_non_super_admin_cannot_export_companies(): void
    {
        $user = User::factory()->create(['is_super_admin' => false]);
        $host = $this->adminHost();
        URL::forceRootUrl('https://'.$host);

        $this->actingAs($user)->get("https://{$host}/companies/export")->assertRedirect(route('super.login'));
    }

    public function test_approving_signup_request_copies_bin_to_company_and_export(): void
    {
        Mail::fake();

        $admin = $this->globalSuperAdmin();
        $host = $this->adminHost();
        URL::forceRootUrl('https://'.$host);

        $signup = TenantSignupRequest::query()->create([
            'company_name' => 'Bin Co',
            'bin' => '987654321098',
            'desired_slug' => 'bin-co',
            'contact_name' => 'Owner',
            'email' => 'owner@bin-co.test',
            'phone' => '+77001234567',
            'status' => 'pending',
        ]);

        $this->actingAs($admin)->post(
            "https://{$host}/signup-requests/{$signup->id}/approve",
            ['create_company' => '1'],
        )->assertRedirect();

        $company = Company::query()->where('slug', 'bin-co')->firstOrFail();
        $this->assertSame('987654321098', $company->bin);

        $response = $this->actingAs($admin)->get("https://{$host}/companies/export?q=Bin+Co");
        $response->assertOk();

        $rows = $this->readXlsxRows($response->streamedContent());
        $this->assertSame('987654321098', $rows[1][4]);
    }

    /**
     * @return list<list<string|null>>
     */
    private function readXlsxRows(string $binary): array
    {
        $path = tempnam(sys_get_temp_dir(), 'company-export-');
        $this->assertNotFalse($path);
        file_put_contents($path, $binary);

        $reader = new XlsxReader;
        $reader->open($path);

        $rows = [];
        foreach ($reader->getSheetIterator() as $sheet) {
            foreach ($sheet->getRowIterator() as $row) {
                $rows[] = array_map(
                    static fn ($cell) => $cell->getValue(),
                    $row->getCells(),
                );
            }
        }

        $reader->close();
        @unlink($path);

        return $rows;
    }
}
