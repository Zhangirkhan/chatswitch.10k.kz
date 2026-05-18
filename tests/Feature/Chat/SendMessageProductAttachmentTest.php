<?php

declare(strict_types=1);

namespace Tests\Feature\Chat;

use App\Jobs\SendOutboundMessageJob;
use App\Models\Chat;
use App\Models\Company;
use App\Models\Product;
use App\Models\User;
use App\Models\WhatsappSession;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

final class SendMessageProductAttachmentTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['administrator', 'manager', 'employee'] as $role) {
            Role::findOrCreate($role);
        }
    }

    public function test_manager_can_send_message_with_product_attachment(): void
    {
        Bus::fake([SendOutboundMessageJob::class]);
        Storage::fake('public');

        $company = Company::create(['name' => 'Test Co']);
        $user = User::factory()->create(['company_id' => $company->id]);
        $user->assignRole('administrator');

        $session = WhatsappSession::factory()->create();
        $chat = Chat::factory()->create([
            'company_id' => $company->id,
            'whatsapp_session_id' => $session->id,
        ]);

        $product = Product::create([
            'company_id' => $company->id,
            'name' => 'Окно ПВХ 1200',
            'sku' => 'WIN-1200',
            'price' => 125000,
            'is_active' => true,
        ]);

        $response = $this->actingAs($user)
            ->postJson("/chats/{$chat->id}/send-message", [
                'message' => 'Вот модель, которую обсуждали',
                'product_id' => $product->id,
            ]);

        $response->assertOk()
            ->assertJsonPath('message.metadata.product.id', $product->id)
            ->assertJsonPath('message.metadata.product.name', 'Окно ПВХ 1200');

        Bus::assertDispatched(SendOutboundMessageJob::class, function ($job): bool {
            return $job->payloadType === 'text'
                && str_contains((string) ($job->payload['body'] ?? ''), 'Окно ПВХ 1200');
        });
    }

    public function test_cannot_attach_product_from_other_company(): void
    {
        $company = Company::create(['name' => 'Main']);
        $other = Company::create(['name' => 'Other']);
        $user = User::factory()->create(['company_id' => $company->id]);
        $user->assignRole('administrator');

        $session = WhatsappSession::factory()->create();
        $chat = Chat::factory()->create([
            'company_id' => $company->id,
            'whatsapp_session_id' => $session->id,
        ]);

        $foreign = Product::create([
            'company_id' => $other->id,
            'name' => 'Foreign',
            'is_active' => true,
        ]);

        $this->actingAs($user)
            ->postJson("/chats/{$chat->id}/send-message", [
                'message' => 'Test',
                'product_id' => $foreign->id,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['product_id']);
    }

    public function test_products_endpoint_lists_company_catalog(): void
    {
        $company = Company::create(['name' => 'Main']);
        $user = User::factory()->create(['company_id' => $company->id]);
        $user->assignRole('administrator');

        $session = WhatsappSession::factory()->create();
        $chat = Chat::factory()->create([
            'company_id' => $company->id,
            'whatsapp_session_id' => $session->id,
        ]);

        Product::create([
            'company_id' => $company->id,
            'name' => 'Товар A',
            'is_active' => true,
        ]);
        Product::create([
            'company_id' => $company->id,
            'name' => 'Скрытый',
            'is_active' => false,
        ]);

        $this->actingAs($user)
            ->getJson("/chats/{$chat->id}/products")
            ->assertOk()
            ->assertJsonCount(1, 'products')
            ->assertJsonPath('products.0.name', 'Товар A');
    }
}
