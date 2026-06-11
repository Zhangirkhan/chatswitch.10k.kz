<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1;

use App\Models\User;
use App\Models\UserDevice;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

final class DevicesApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['administrator', 'manager', 'employee'] as $roleName) {
            Role::findOrCreate($roleName);
        }
    }

    public function test_register_device_creates_record(): void
    {
        $user = User::factory()->create();
        $user->assignRole('employee');
        Sanctum::actingAs($user);

        $response = $this->postJson('/api/v1/devices', [
            'platform' => 'android',
            'fcm_token' => 'test-fcm-token-'.str_repeat('a', 40),
            'device_name' => 'Pixel 8',
            'app_version' => '1.0.0+1',
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.platform', 'android')
            ->assertJsonPath('data.fcm_token', 'test-fcm-token-'.str_repeat('a', 40));

        $this->assertDatabaseHas('user_devices', [
            'user_id' => $user->id,
            'company_id' => $user->company_id,
            'platform' => 'android',
        ]);
    }

    public function test_register_device_upserts_same_token_for_user(): void
    {
        $user = User::factory()->create();
        $user->assignRole('employee');
        Sanctum::actingAs($user);

        $token = 'shared-token-'.str_repeat('b', 40);

        $this->postJson('/api/v1/devices', [
            'platform' => 'android',
            'fcm_token' => $token,
            'device_name' => 'Old phone',
        ])->assertCreated();

        $this->postJson('/api/v1/devices', [
            'platform' => 'android',
            'fcm_token' => $token,
            'device_name' => 'New phone',
        ])
            ->assertOk()
            ->assertJsonPath('data.fcm_token', $token);

        $this->assertSame(1, UserDevice::query()->where('fcm_token', $token)->count());
        $this->assertDatabaseHas('user_devices', [
            'user_id' => $user->id,
            'device_name' => 'New phone',
        ]);
    }

    public function test_register_device_reassigns_token_to_new_user(): void
    {
        $first = User::factory()->create();
        $first->assignRole('employee');
        $second = User::factory()->create(['company_id' => $first->company_id]);
        $second->assignRole('employee');

        $token = 'reassign-token-'.str_repeat('c', 40);

        Sanctum::actingAs($first);
        $this->postJson('/api/v1/devices', [
            'platform' => 'android',
            'fcm_token' => $token,
        ])->assertCreated();

        Sanctum::actingAs($second);
        $this->postJson('/api/v1/devices', [
            'platform' => 'android',
            'fcm_token' => $token,
        ])->assertCreated();

        $this->assertDatabaseMissing('user_devices', [
            'user_id' => $first->id,
            'fcm_token' => $token,
        ]);
        $this->assertDatabaseHas('user_devices', [
            'user_id' => $second->id,
            'fcm_token' => $token,
        ]);
    }

    public function test_delete_device_requires_ownership(): void
    {
        $owner = User::factory()->create();
        $owner->assignRole('employee');
        $other = User::factory()->create(['company_id' => $owner->company_id]);
        $other->assignRole('employee');

        $device = UserDevice::query()->create([
            'company_id' => $owner->company_id,
            'user_id' => $owner->id,
            'platform' => 'android',
            'fcm_token' => 'owner-token-'.str_repeat('d', 40),
        ]);

        Sanctum::actingAs($other);
        $this->deleteJson('/api/v1/devices/'.$device->id)->assertNotFound();

        Sanctum::actingAs($owner);
        $this->deleteJson('/api/v1/devices/'.$device->id)->assertNoContent();
        $this->assertDatabaseMissing('user_devices', ['id' => $device->id]);
    }

    public function test_unregister_by_token(): void
    {
        $user = User::factory()->create();
        $user->assignRole('employee');
        Sanctum::actingAs($user);

        $token = 'unregister-token-'.str_repeat('e', 40);
        UserDevice::query()->create([
            'company_id' => $user->company_id,
            'user_id' => $user->id,
            'platform' => 'android',
            'fcm_token' => $token,
        ]);

        $this->postJson('/api/v1/devices/unregister', ['fcm_token' => $token])
            ->assertNoContent();

        $this->assertDatabaseMissing('user_devices', ['fcm_token' => $token]);
    }

    public function test_register_requires_authentication(): void
    {
        $this->postJson('/api/v1/devices', [
            'platform' => 'android',
            'fcm_token' => 'no-auth-token-'.str_repeat('f', 40),
        ])->assertUnauthorized();
    }

    public function test_register_device_stores_metadata_and_client_ip(): void
    {
        $user = User::factory()->create();
        $user->assignRole('employee');
        Sanctum::actingAs($user);

        $token = 'meta-token-'.str_repeat('g', 40);

        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.42'])
            ->postJson('/api/v1/devices', [
                'platform' => 'android',
                'fcm_token' => $token,
                'device_name' => 'samsung SM-G991B',
                'device_model' => 'SM-G991B',
                'device_manufacturer' => 'samsung',
                'os_version' => 'Android 14 (API 34)',
                'locale' => 'ru_RU',
                'is_physical_device' => true,
                'app_version' => '1.0.4+12',
            ])
            ->assertCreated();

        $this->assertDatabaseHas('user_devices', [
            'user_id' => $user->id,
            'fcm_token' => $token,
            'device_model' => 'SM-G991B',
            'device_manufacturer' => 'samsung',
            'os_version' => 'Android 14 (API 34)',
            'locale' => 'ru_RU',
            'is_physical_device' => 1,
            'app_version' => '1.0.4+12',
            'last_seen_ip' => '203.0.113.42',
        ]);
    }

    public function test_register_device_upsert_updates_metadata_and_last_seen_ip(): void
    {
        $user = User::factory()->create();
        $user->assignRole('employee');
        Sanctum::actingAs($user);

        $token = 'upsert-meta-'.str_repeat('h', 40);

        $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.10'])
            ->postJson('/api/v1/devices', [
                'platform' => 'android',
                'fcm_token' => $token,
                'device_model' => 'Pixel 7',
            ])
            ->assertCreated();

        $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.20'])
            ->postJson('/api/v1/devices', [
                'platform' => 'android',
                'fcm_token' => $token,
                'device_model' => 'Pixel 8',
                'locale' => 'kk_KZ',
            ])
            ->assertOk();

        $this->assertDatabaseHas('user_devices', [
            'user_id' => $user->id,
            'fcm_token' => $token,
            'device_model' => 'Pixel 8',
            'locale' => 'kk_KZ',
            'last_seen_ip' => '198.51.100.20',
        ]);
    }
}
