<?php

namespace Tests\Feature\Api\V1\Auth;

use App\Models\Tenant;
use App\Support\MorphMap;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class LoginTest extends TestCase
{
    use RefreshDatabase;

    /**
     * The login authenticates a PERSON since 2026-09-05, so a company on its own can no longer sign
     * in. Every case here still turns on the company's own email/password/status, so the person is
     * minted alongside on the same address — which is exactly what the backfill migration does for
     * the tenants that already existed.
     */
    private function makeTenant(array $overrides = []): Tenant
    {
        $tenant = $this->makeTenantRow($overrides);

        \App\Models\TenantUser::create([
            'tenant_id' => $tenant->id,
            'name' => $tenant->contact_person ?: $tenant->name,
            'email' => $tenant->email,
            'password' => $overrides['plain_password'] ?? 'secret-pw',
            'is_admin' => true,
        ]);

        return $tenant;
    }

    private function makeTenantRow(array $overrides = []): Tenant
    {
        unset($overrides['plain_password']);

        return Tenant::create(array_merge([
            'name' => 'Test Tenant',
            'email' => 'test-'.uniqid().'@tenant.local',
            'password' => Hash::make('secret-pw'),
            'status' => 'active',
            'type' => 'company',
        ], $overrides));
    }

    public function test_returns_token_and_leases_on_valid_credentials(): void
    {
        $tenant = $this->makeTenant(['name' => 'Acme Co', 'contact_person' => 'John Doe']);
        makeLease(makeUnit(makeAsset(['name' => 'City Mall']), ['code' => 'B-214']), $tenant);

        $response = $this->postJson('/api/v1/auth/login', [
            'email' => $tenant->email,
            'password' => 'secret-pw',
        ]);

        $response->assertOk()
            ->assertJsonStructure([
                'data' => [['id', 'name', 'shop', 'mall', 'unitNumber', 'startDate', 'endDate', 'isActive']],
                'accessToken',
                'tokenType',
                'message',
            ])
            ->assertJsonPath('tokenType', 'Bearer')
            ->assertJsonPath('data.0.name', 'John Doe')
            ->assertJsonPath('data.0.shop', 'Acme Co')
            ->assertJsonPath('data.0.mall', 'City Mall')
            ->assertJsonPath('data.0.unitNumber', 'B-214')
            ->assertJsonPath('data.0.isActive', true);

        $this->assertNotEmpty($response->json('accessToken'));
        $this->assertDatabaseHas('personal_access_tokens', [
            'tokenable_type' => MorphMap::alias(\App\Models\TenantUser::class),
            'tokenable_id' => tenantLogin($tenant)->id,
        ]);
    }

    public function test_data_is_always_an_array_even_with_no_leases(): void
    {
        $tenant = $this->makeTenant();

        $response = $this->postJson('/api/v1/auth/login', [
            'email' => $tenant->email, 'password' => 'secret-pw',
        ])->assertOk();

        $this->assertIsArray($response->json('data'));
        $this->assertCount(0, $response->json('data'));
    }

    public function test_rejects_wrong_password_with_401(): void
    {
        $tenant = $this->makeTenant();

        $this->postJson('/api/v1/auth/login', [
            'email' => $tenant->email, 'password' => 'wrong',
        ])
            ->assertStatus(401)
            ->assertJsonPath('statusCode', 401)
            ->assertJsonStructure(['message', 'statusCode']);
    }

    public function test_rejects_unknown_email_with_401(): void
    {
        $this->postJson('/api/v1/auth/login', [
            'email' => 'nobody@example.com', 'password' => 'whatever',
        ])->assertStatus(401);
    }

    public function test_blocks_inactive_account_with_403(): void
    {
        $tenant = $this->makeTenant(['status' => 'inactive']);

        $this->postJson('/api/v1/auth/login', [
            'email' => $tenant->email, 'password' => 'secret-pw',
        ])->assertStatus(403)->assertJsonPath('statusCode', 403);
    }

    public function test_blocks_blacklisted_account_with_403(): void
    {
        $tenant = $this->makeTenant(['status' => 'blacklisted']);

        $this->postJson('/api/v1/auth/login', [
            'email' => $tenant->email, 'password' => 'secret-pw',
        ])->assertStatus(403);
    }

    public function test_missing_fields_return_400(): void
    {
        $this->postJson('/api/v1/auth/login', [])
            ->assertStatus(400)
            ->assertJsonPath('statusCode', 400)
            ->assertJsonStructure(['message', 'statusCode']);
    }

    public function test_revokes_prior_token_for_same_device(): void
    {
        $tenant = $this->makeTenant();

        $first = $this->postJson('/api/v1/auth/login', [
            'email' => $tenant->email, 'password' => 'secret-pw', 'device_name' => 'iPhone 16',
        ])->json('accessToken');

        $second = $this->postJson('/api/v1/auth/login', [
            'email' => $tenant->email, 'password' => 'secret-pw', 'device_name' => 'iPhone 16',
        ])->json('accessToken');

        $this->assertNotSame($first, $second);
        $this->assertEquals(1, tenantLogin($tenant)->tokens()->where('name', 'iPhone 16')->count());
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

        $this->assertEquals(2, tenantLogin($tenant)->tokens()->count());
    }
}
