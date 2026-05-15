<?php

declare(strict_types=1);

namespace Tests\Feature\Settings;

use App\Models\Company;
use App\Models\Funnel;
use App\Models\SystemSetting;
use App\Models\User;
use App\Support\TenantCompany;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

final class FunnelAiOnboardingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['administrator', 'manager', 'employee'] as $role) {
            Role::findOrCreate($role);
        }

        SystemSetting::setValue('module_funnels', 'on');
        TenantCompany::ensureExists();
    }

    public function test_admin_can_request_onboarding_suggestions(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('administrator');

        Http::fake([
            'https://api.openai.com/v1/chat/completions' => Http::response([
                'choices' => [[
                    'message' => [
                        'content' => json_encode([
                            'suggestions' => [
                                [
                                    'name' => 'Быстрые продажи',
                                    'description' => 'Короткий цикл для розницы.',
                                    'color' => '#25d366',
                                    'rationale' => 'Подходит для импульсных покупок.',
                                    'stages' => [
                                        ['name' => 'Заявка', 'color' => '#3b82f6'],
                                        ['name' => 'Консультация', 'color' => '#6366f1'],
                                        ['name' => 'Оплата', 'color' => '#22d3ee'],
                                    ],
                                ],
                                [
                                    'name' => 'B2B цикл',
                                    'description' => 'Длинные переговоры с юрлицами.',
                                    'color' => '#3b82f6',
                                    'rationale' => 'Для корпоративных клиентов.',
                                    'stages' => [
                                        ['name' => 'Лид', 'color' => '#9ca3af'],
                                        ['name' => 'КП', 'color' => '#6366f1'],
                                        ['name' => 'Договор', 'color' => '#25d366'],
                                    ],
                                ],
                            ],
                        ], JSON_UNESCAPED_UNICODE),
                    ],
                ]],
            ], 200),
        ]);

        $response = $this->actingAs($admin)->postJson('/settings/funnels/ai-onboarding-suggest', [
            'target_audience' => 'B2C — частные клиенты в Алматы',
            'industry' => 'Установка пластиковых окон',
            'business_description' => 'Производим и монтируем окна под ключ',
            'clients_description' => 'Владельцы квартир и домов 30-55 лет',
            'products_description' => 'Пластиковые окна, балконное остекление',
            'sales_process' => 'Заявка, замер, расчёт, договор, монтаж за 1-2 недели',
        ]);

        $response->assertOk();
        $response->assertJsonPath('success', true);
        $response->assertJsonCount(2, 'suggestions');
        $response->assertJsonPath('suggestions.0.name', 'Быстрые продажи');
        $response->assertJsonPath('suggestions.0.rationale', 'Подходит для импульсных покупок.');
        $response->assertJsonCount(3, 'suggestions.0.stages');
    }

    public function test_onboarding_validation_requires_all_fields(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('administrator');

        $this->actingAs($admin)
            ->postJson('/settings/funnels/ai-onboarding-suggest', [
                'target_audience' => 'short',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'industry',
                'business_description',
                'clients_description',
                'products_description',
                'sales_process',
            ]);
    }

    public function test_store_assigns_tenant_company_id(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('administrator');

        $response = $this->actingAs($admin)->postJson('/settings/funnels', [
            'name' => 'Основная воронка',
            'description' => 'Тест',
            'color' => '#25d366',
            'is_active' => true,
            'stages' => [
                ['name' => 'Новый лид', 'color' => '#3b82f6', 'is_active' => true],
                ['name' => 'Сделка', 'color' => '#25d366', 'is_active' => true],
            ],
        ]);

        $response->assertOk();
        $response->assertJsonPath('success', true);

        $funnel = Funnel::query()->first();
        $this->assertNotNull($funnel);
        $this->assertSame(TenantCompany::ID, $funnel->company_id);
        $this->assertSame('Основная воронка', $funnel->name);
        $this->assertCount(2, $funnel->stages);
    }

    public function test_module_disabled_returns_forbidden(): void
    {
        SystemSetting::setValue('module_funnels', 'off');
        $admin = User::factory()->create();
        $admin->assignRole('administrator');

        $this->actingAs($admin)
            ->postJson('/settings/funnels/ai-onboarding-suggest', [
                'target_audience' => 'B2C — частные клиенты',
                'industry' => 'Ритейл',
                'business_description' => 'Интернет-магазин одежды',
                'clients_description' => 'Молодые покупатели 20-35',
                'products_description' => 'Одежда и аксессуары',
                'sales_process' => 'Корзина, оплата, доставка за 2-3 дня',
            ])
            ->assertForbidden();
    }

    public function test_funnels_index_scoped_to_tenant_company(): void
    {
        $otherCompany = Company::query()->create(['name' => 'Другая компания']);
        Funnel::query()->create([
            'company_id' => $otherCompany->id,
            'name' => 'Чужая воронка',
            'color' => '#ef4444',
            'position' => 0,
            'is_active' => true,
        ]);
        Funnel::query()->create([
            'company_id' => TenantCompany::id(),
            'name' => 'Наша воронка',
            'color' => '#25d366',
            'position' => 0,
            'is_active' => true,
        ]);

        $admin = User::factory()->create();
        $admin->assignRole('administrator');

        $this->actingAs($admin)
            ->get('/settings/funnels')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Settings/Funnels')
                ->has('funnels', 1)
                ->where('funnels.0.name', 'Наша воронка'));
    }
}
