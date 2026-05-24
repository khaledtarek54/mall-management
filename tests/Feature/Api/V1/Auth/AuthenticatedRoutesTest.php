<?php

namespace Tests\Feature\Api\V1\Auth;

use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AuthenticatedRoutesTest extends TestCase
{
    use RefreshDatabase;

    private function makeTenantWithToken(): array
    {
        $tenant = Tenant::create([
            'name' => 'Test Tenant',
            'email' => 'authed-' . uniqid() . '@t.local',
            'password' => Hash::make('secret-pw'),
            'status' => 'active',
            'type' => 'company',
        ]);
        $token = $tenant->createToken('test-device', ['tenant:*'])->plainTextToken;
        return [$tenant, $token];
    }

    public function test_me_returns_authenticated_tenant(): void
    {
        [$tenant, $token] = $this->makeTenantWithToken();

        $this->getJson('/api/v1/auth/me', ['Authorization' => "Bearer {$token}"])
            ->assertOk()
            ->assertJsonPath('data.id', $tenant->id)
            ->assertJsonPath('data.email', $tenant->email);
    }

    public function test_me_rejects_unauthenticated(): void
    {
        $this->getJson('/api/v1/auth/me')->assertStatus(401);
    }

    public function test_me_rejects_invalid_token(): void
    {
        $this->getJson('/api/v1/auth/me', [
            'Authorization' => 'Bearer this-is-not-a-real-token',
        ])->assertStatus(401);
    }

    public function test_logout_revokes_the_current_token(): void
    {
        [$tenant, $token] = $this->makeTenantWithToken();

        $this->postJson('/api/v1/auth/logout', [], ['Authorization' => "Bearer {$token}"])
            ->assertOk()
            ->assertJsonStructure(['message']);

        // The DB row must be gone.
        $this->assertEquals(0, $tenant->fresh()->tokens()->count(),
            'logout should delete the personal_access_tokens row');

        // Laravel's test client shares the auth manager between requests in
        // the same test, so the first request's resolved user gets cached.
        // Flush guard state so the next call re-resolves via Sanctum.
        Auth::forgetGuards();

        // The same bearer must now be rejected (DB row gone).
        $this->getJson('/api/v1/auth/me', ['Authorization' => "Bearer {$token}"])
            ->assertStatus(401);
    }

    public function test_logout_only_revokes_the_current_device(): void
    {
        $tenant = Tenant::create([
            'name' => 'Multi Device',
            'email' => 'multi-' . uniqid() . '@t.local',
            'password' => Hash::make('secret-pw'),
            'status' => 'active',
            'type' => 'company',
        ]);
        $iphone = $tenant->createToken('iphone', ['tenant:*'])->plainTextToken;
        $ipad = $tenant->createToken('ipad', ['tenant:*'])->plainTextToken;

        $this->postJson('/api/v1/auth/logout', [], ['Authorization' => "Bearer {$iphone}"])
            ->assertOk();

        // ipad still works
        $this->getJson('/api/v1/auth/me', ['Authorization' => "Bearer {$ipad}"])
            ->assertOk();
        $this->assertEquals(1, $tenant->tokens()->count());
    }
}
