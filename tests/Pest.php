<?php

use App\Models\Asset;
use App\Models\Invoice;
use App\Models\Lease;
use App\Models\TenantRequest;
use App\Models\Tenant;
use App\Models\Unit;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Unit');

/*
|--------------------------------------------------------------------------
| Domain helpers — keep tests terse by hiding scaffolding here.
|--------------------------------------------------------------------------
*/

/**
 * Create the role catalogue Pest tests assume exists. Run from beforeEach
 * blocks in tests that touch roles.
 */
function seedRoles(): void
{
    foreach (['super_admin', 'manager', 'leasing', 'operations', 'viewer', 'owner'] as $role) {
        Role::findOrCreate($role, 'web');
    }
}

/**
 * Ensure the synthetic "All Properties" Asset row exists in the in-memory
 * test DB. The production migration seeds it; tests that need it before
 * the migration runs can call this in beforeEach.
 */
function ensureAllPropertiesAsset(): Asset
{
    return Asset::firstOrCreate(
        ['code' => Asset::ALL_PROPERTIES_CODE],
        [
            'name' => 'All Properties',
            'type' => 'mall',
            'city' => '—',
            'country' => '—',
            'currency' => 'EGP',
            'is_active' => false,
        ],
    );
}

function makeAsset(array $attrs = []): Asset
{
    return Asset::create(array_merge([
        'name' => 'Asset ' . uniqid(),
        'code' => strtoupper(substr(uniqid(), -6)),
        'type' => 'mall',
        'city' => 'Cairo',
        'country' => 'EG',
        'total_area_sqm' => 1000,
        'leasable_area_sqm' => 800,
        'currency' => 'EGP',
        'is_active' => true,
    ], $attrs));
}

function makeUnit(Asset $asset, array $attrs = []): Unit
{
    return Unit::create(array_merge([
        'asset_id' => $asset->id,
        'code' => 'U-' . uniqid(),
        'area_sqm' => 100,
        'status' => 'vacant',
        'category' => 'retail',
    ], $attrs));
}

function makeTenant(array $attrs = []): Tenant
{
    return Tenant::create(array_merge([
        'name' => 'Tenant ' . uniqid(),
        'email' => uniqid() . '@t.test',
        'type' => 'company',
        'status' => 'active',
    ], $attrs));
}

/** A portal login for a tenant (admin by default). actingAs(.., 'portal'). */
function makeTenantUser(Tenant $tenant, bool $isAdmin = true): \App\Models\TenantUser
{
    return \App\Models\TenantUser::create([
        'tenant_id' => $tenant->id,
        'name' => $tenant->name . ' user',
        'email' => 'tu' . uniqid() . '@test.local',
        'password' => bcrypt('password'),
        'is_admin' => $isAdmin,
    ]);
}

function makeLease(Unit $unit, ?Tenant $tenant = null, array $attrs = []): Lease
{
    $tenant ??= makeTenant();

    return Lease::create(array_merge([
        'reference' => 'L-' . uniqid(),
        'unit_id' => $unit->id,
        'tenant_id' => $tenant->id,
        'status' => 'active',
        'commencement_date' => '2026-01-01',
        'expiry_date' => '2027-12-31',
        'term_months' => 24,
        'base_rent_monthly' => 10000,
        'service_charge_monthly' => 2000,
        'currency' => 'EGP',
        'payment_terms_days' => 7,
    ], $attrs));
}

function makeInvoice(Lease $lease, array $attrs = []): Invoice
{
    return Invoice::create(array_merge([
        'lease_id' => $lease->id,
        'tenant_id' => $lease->tenant_id,
        'status' => 'issued',
        'issue_date' => '2026-02-01',
        'due_date' => '2026-02-10',
        'period_start' => '2026-02-01',
        'period_end' => '2026-02-28',
        'subtotal' => 10000,
        'vat_amount' => 1400,
        'total' => 11400,
        'paid_amount' => 0,
        'balance' => 11400,
        'currency' => 'EGP',
    ], $attrs));
}

function makeMaintenanceRequest(array $attrs = []): TenantRequest
{
    $asset = makeAsset();
    $unit = makeUnit($asset);
    $tenant = makeTenant();

    return TenantRequest::create(array_merge([
        'reference' => 'MR-' . uniqid(),
        'unit_id' => $unit->id,
        'tenant_id' => $tenant->id,
        'title' => 'Test',
        'description' => 'Test description',
        'status' => 'submitted',
        'priority' => 'medium',
        'category' => 'electrical',
        'submitted_at' => now(),
    ], $attrs));
}

/**
 * Authorization header carrying a fresh Sanctum token for a tenant — the
 * mobile API auth path. Keeps /api/v1 tests a single call away from "as this
 * tenant".
 *
 * @return array<string,string>
 */
function apiHeaders(Tenant $tenant, string $device = 'test-device'): array
{
    return ['Authorization' => 'Bearer ' . $tenant->createToken($device, ['tenant:*'])->plainTextToken];
}

function makeUser(string $role = 'manager', array $assetIds = []): User
{
    seedRoles();

    $user = User::create([
        'name' => $role . ' user',
        'email' => $role . uniqid() . '@test.local',
        'password' => bcrypt('password'),
    ]);
    $user->syncRoles([$role]);

    if ($assetIds) {
        $user->assignedAssets()->sync(array_fill_keys($assetIds, [
            'role' => 'Test',
            'assigned_at' => now(),
        ]));
    }

    return $user;
}

/**
 * Run a closure with the given Asset set as the active Filament tenant.
 * Restores the previous tenant afterwards so tests don't bleed state.
 * Requires an authenticated user — Filament's TenantSet event needs one.
 */
function asTenant(Asset $tenant, callable $callback): mixed
{
    if (! auth()->check()) {
        auth()->login(makeUser('super_admin'));
    }

    Filament::setTenant($tenant);
    try {
        return $callback();
    } finally {
        // Pass false to skip the TenantSet event when clearing.
        Filament::setTenant(null, isQuiet: true);
    }
}

/**
 * Get a fully-scoped resource query as Filament would build it for a
 * ListRecords page — applies both the resource's getEloquentQuery() AND
 * the tenant-ownership scope. Use in tests to verify the full filter chain.
 *
 * @param  class-string  $resourceClass
 */
function scopedResourceQuery(string $resourceClass): \Illuminate\Database\Eloquent\Builder
{
    $query = $resourceClass::getEloquentQuery();

    if ($tenant = Filament::getTenant()) {
        if ($resourceClass::isScopedToTenant()) {
            $query = $resourceClass::scopeEloquentQueryToTenant($query, $tenant);
        }
    }

    return $query;
}

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});
