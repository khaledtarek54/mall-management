<?php

namespace Tests\Feature;

use App\Models\Asset;
use App\Models\User;
use App\Support\AssignedAssets;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class AssignedAssetsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Create the roles the helper checks against.
        Role::findOrCreate('super_admin', 'web');
        Role::findOrCreate('manager', 'web');
    }

    private function makeUser(string $role, array $assetIds = []): User
    {
        $u = User::create([
            'name' => $role . ' user',
            'email' => $role . uniqid() . '@test.local',
            'password' => Hash::make('pw'),
        ]);
        $u->syncRoles([$role]);
        if ($assetIds) {
            $u->assignedAssets()->sync(array_fill_keys($assetIds, ['assigned_at' => now()]));
        }
        return $u;
    }

    private function makeAsset(): Asset
    {
        return Asset::create([
            'name' => 'Test Asset ' . uniqid(),
            'code' => strtoupper(substr(uniqid(), -6)),
            'type' => 'mall', 'city' => 'Cairo', 'country' => 'EG',
            'total_area_sqm' => 100, 'leasable_area_sqm' => 80,
            'currency' => 'EGP', 'is_active' => true,
        ]);
    }

    public function test_super_admin_returns_null_unrestricted(): void
    {
        $admin = $this->makeUser('super_admin');
        $this->assertNull(AssignedAssets::idsFor($admin));
        $this->assertFalse(AssignedAssets::isRestricted($admin));
    }

    public function test_user_with_no_assignments_returns_null_unrestricted(): void
    {
        // Back-compat: when admins haven't configured staff-property assignments
        // yet, the user should see everything rather than nothing.
        $manager = $this->makeUser('manager');
        $this->assertNull(AssignedAssets::idsFor($manager));
        $this->assertFalse(AssignedAssets::isRestricted($manager));
    }

    public function test_user_with_assignments_returns_those_ids_only(): void
    {
        $a = $this->makeAsset();
        $b = $this->makeAsset();
        $c = $this->makeAsset();

        $manager = $this->makeUser('manager', [$a->id, $c->id]);

        $ids = AssignedAssets::idsFor($manager);
        $this->assertIsArray($ids);
        $this->assertEqualsCanonicalizing([$a->id, $c->id], $ids);
        $this->assertNotContains($b->id, $ids);
        $this->assertTrue(AssignedAssets::isRestricted($manager));
    }

    public function test_super_admin_with_assignments_still_unrestricted(): void
    {
        // Even if super_admin is explicitly assigned to one asset, they should
        // still see everything — they're platform-level.
        $a = $this->makeAsset();
        $admin = $this->makeUser('super_admin', [$a->id]);

        $this->assertNull(AssignedAssets::idsFor($admin));
    }
}
