<?php

namespace Tests\Feature\Api\V1\Auth;

use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class LoginTest extends TestCase
{
    use RefreshDatabase;

    private function makeTenant(array $overrides = []): Tenant
    {
        return Tenant::create(array_merge([
            'name' => 'Test Tenant',
            'email' => 'test-' . uniqid() . '@tenant.local',
            'password' => Hash::make('secret-pw'),
            'status' => 'active',
            'type' => 'company',
        ], $overrides));
    }

    public function test_returns_token_and_tenant_on_valid_credentials(): void
    {
        $tenant = $this->makeTenant();

        $response = $this->postJson('/api/v1/auth/login', [
            'email' => $tenant->email,
            'password' => 'secret-pw',
            'device_name' => 'iPhone 16 Pro',
        ]);

        $response->assertOk()
            ->assertJsonStructure([
                'data' => [
                    'tenant' => ['id', 'name', 'email', 'status'],
                    'token',
                    'token_type',
                ],
                'message',
            ])
            ->assertJsonPath('data.tenant.id', $tenant->id)
            ->assertJsonPath('data.token_type', 'Bearer');

        $this->assertNotEmpty($response->json('data.token'));
        $this->assertDatabaseHas('personal_access_tokens', [
            'tokenable_type' => Tenant::class,
            'tokenable_id' => $tenant->id,
            'name' => 'iPhone 16 Pro',
        ]);
    }

    public function test_rejects_wrong_password(): void
    {
        $tenant = $this->makeTenant();

        $this->postJson('/api/v1/auth/login', [
            'email' => $tenant->email,
            'password' => 'wrong',
            'device_name' => 'iPhone',
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['email']);
    }

    public function test_rejects_unknown_email(): void
    {
        $this->postJson('/api/v1/auth/login', [
            'email' => 'nobody@example.com',
            'password' => 'whatever',
            'device_name' => 'iPhone',
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['email']);
    }

    public function test_blocks_inactive_account(): void
    {
        $tenant = $this->makeTenant(['status' => 'inactive']);

        $this->postJson('/api/v1/auth/login', [
            'email' => $tenant->email,
            'password' => 'secret-pw',
            'device_name' => 'iPhone',
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['email']);
    }

    public function test_blocks_blacklisted_account(): void
    {
        $tenant = $this->makeTenant(['status' => 'blacklisted']);

        $this->postJson('/api/v1/auth/login', [
            'email' => $tenant->email,
            'password' => 'secret-pw',
            'device_name' => 'iPhone',
        ])->assertStatus(422);
    }

    public function test_requires_all_three_fields(): void
    {
        $this->postJson('/api/v1/auth/login', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['email', 'password', 'device_name']);
    }

    public function test_revokes_prior_token_for_same_device(): void
    {
        $tenant = $this->makeTenant();

        $first = $this->postJson('/api/v1/auth/login', [
            'email' => $tenant->email,
            'password' => 'secret-pw',
            'device_name' => 'iPhone 16',
        ])->json('data.token');

        $second = $this->postJson('/api/v1/auth/login', [
            'email' => $tenant->email,
            'password' => 'secret-pw',
            'device_name' => 'iPhone 16',
        ])->json('data.token');

        $this->assertNotSame($first, $second);
        $this->assertEquals(1, $tenant->tokens()->where('name', 'iPhone 16')->count(),
            'a fresh login on the same device should revoke + replace, not stack tokens');
    }

    public function test_keeps_tokens_separate_across_devices(): void
    {
        $tenant = $this->makeTenant();

        $this->postJson('/api/v1/auth/login', [
            'email' => $tenant->email, 'password' => 'secret-pw', 'device_name' => 'iPhone',
        ])->assertOk();
        $this->postJson('/api/v1/auth/login', [
            'email' => $tenant->email, 'password' => 'secret-pw', 'device_name' => 'iPad',
        ])->assertOk();

        $this->assertEquals(2, $tenant->tokens()->count());
    }
}
