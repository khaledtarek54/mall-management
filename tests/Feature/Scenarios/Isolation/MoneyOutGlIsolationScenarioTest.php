<?php

use App\Filament\Admin\Resources\Expenses\ExpenseResource;
use App\Filament\Admin\Resources\JournalEntries\JournalEntryResource;
use App\Filament\Admin\Resources\Payrolls\PayrollResource;
use App\Filament\Admin\Resources\VendorBills\VendorBillResource;
use App\Models\Asset;
use App\Models\Expense;
use App\Models\JournalEntry;
use App\Models\Payroll;
use App\Models\Vendor;
use App\Models\VendorBill;
use Database\Seeders\RolesPermissionsSeeder;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Property-isolation scenario suite — money-out + GL group.
 *
 * Covers the four financial resources whose model carries a DIRECT, NULLABLE
 * `asset_id` dimension (Expense, VendorBill, Payroll, JournalEntry). Their read
 * scope lives entirely in each resource's own getEloquentQuery() (they opt out
 * of Filament auto-tenancy via BypassesFilamentTenantAutoScope), and the write
 * guard is GuardsAssetInScope::assertAssetInScope($assetId).
 *
 * Group-specific rule (asset_id NULLABLE = consolidated / portfolio-level row):
 *   - a null-asset row is CONSOLIDATED and surfaces to EVERYONE (every property
 *     view + every restricted user), by design — property expenses OR company
 *     consolidated expenses;
 *   - but a RESTRICTED user must NOT be able to CREATE a null (consolidated) row
 *     — assertAssetInScope(null) throws for them, and is a no-op for super_admin.
 *
 * This complements (does not duplicate) the write-guard-only coverage in
 * tests/Feature/Regression/Isolation/AssetInScopeWriteGuardTest.php — here we
 * exercise the READ-SCOPE and ALL-PROPERTIES read paths for this group, which
 * that regression file does not touch.
 */
beforeEach(function () {
    $this->seed(RolesPermissionsSeeder::class);
    ensureAllPropertiesAsset();

    $this->assetA = makeAsset(['code' => 'MOA']);
    $this->assetB = makeAsset(['code' => 'MOB']);
    $this->all = Asset::where('code', Asset::ALL_PROPERTIES_CODE)->first();
});

// A tiny row-maker per model so each scenario reads three rows into being:
// one owned by A, one owned by B, one consolidated (null asset_id).
function makeExpenseFor(?int $assetId): Expense
{
    return Expense::create([
        'number' => 'EXP-'.uniqid(),
        'asset_id' => $assetId,
        'category' => 'admin',
        'amount' => 1000,
        'vat_amount' => 140,
        'total' => 1140,
        'paid_from' => 'cash',
        'expense_date' => '2026-07-01',
        'status' => 'recorded',
    ]);
}

function makeVendorBillFor(?int $assetId): VendorBill
{
    $vendor = Vendor::create([
        'name' => 'Vendor '.uniqid(),
        'slug' => 'vendor-'.uniqid(),
        'type' => 'supplier',
        'status' => 'active',
    ]);

    return VendorBill::create([
        'number' => 'BILL-'.uniqid(),
        'vendor_id' => $vendor->id,
        'asset_id' => $assetId,
        'category' => 'maintenance',
        'status' => 'approved',
        'bill_date' => '2026-07-01',
        'subtotal' => 2000,
        'vat_amount' => 280,
        'total' => 2280,
        'paid_amount' => 0,
        'balance' => 2280,
        'currency' => 'EGP',
    ]);
}

function makePayrollFor(?int $assetId): Payroll
{
    return Payroll::create([
        'number' => 'PR-'.uniqid(),
        'asset_id' => $assetId,
        'period_month' => '2026-07-01',
        'gross_salaries' => 50000,
        'salary_tax' => 5000,
        'social_insurance' => 3000,
        'net_paid' => 42000,
        'paid_from' => 'bank',
        'status' => 'approved',
    ]);
}

function makeJournalEntryFor(?int $assetId): JournalEntry
{
    return JournalEntry::create([
        'number' => 'JE-'.uniqid(),
        'entry_date' => '2026-07-01',
        'description_en' => 'Test entry',
        'is_manual' => true,
        'status' => 'posted',
        'asset_id' => $assetId,
    ]);
}

/*
|--------------------------------------------------------------------------
| Dataset: resource ⇄ row-maker for each of the four money-out / GL models.
|--------------------------------------------------------------------------
*/
$group = [
    'Expense' => [ExpenseResource::class, 'makeExpenseFor'],
    'VendorBill' => [VendorBillResource::class, 'makeVendorBillFor'],
    'Payroll' => [PayrollResource::class, 'makePayrollFor'],
    'JournalEntry' => [JournalEntryResource::class, 'makeJournalEntryFor'],
];

/*
|--------------------------------------------------------------------------
| (a) READ-SCOPE — a restricted user pinned to A, single property A active.
|--------------------------------------------------------------------------
| Sees A's row + the consolidated (null) row; must NEVER see B's row.
*/
it('read-scope: a user restricted to A sees only A + consolidated, never B', function (string $resource, string $maker) {
    $a = $maker($this->assetA->id);
    $b = $maker($this->assetB->id);
    $consolidated = $maker(null);

    // Restricted user assigned to A, browsing property A.
    $this->actingAs(makeUser('manager', [$this->assetA->id]));

    $ids = asTenant($this->assetA, fn () => scopedResourceQuery($resource)->pluck('id')->all());

    expect($ids)->toContain($a->id)
        ->and($ids)->toContain($consolidated->id) // null-asset consolidated is visible to everyone
        ->and($ids)->not->toContain($b->id);       // B's row is hidden — the isolation guarantee
})->with($group);

/*
|--------------------------------------------------------------------------
| (b) ALL-PROPERTIES — portfolio (super_admin) sees EVERYTHING.
|--------------------------------------------------------------------------
*/
it('all-properties: a super_admin sees A + B + consolidated rows', function (string $resource, string $maker) {
    $a = $maker($this->assetA->id);
    $b = $maker($this->assetB->id);
    $consolidated = $maker(null);

    $this->actingAs(makeUser('super_admin'));

    $ids = asTenant($this->all, fn () => scopedResourceQuery($resource)->pluck('id')->all());

    expect($ids)->toContain($a->id)
        ->and($ids)->toContain($b->id)
        ->and($ids)->toContain($consolidated->id);
})->with($group);

/*
|--------------------------------------------------------------------------
| (b') ALL-PROPERTIES — a RESTRICTED user in All-mode is still pinned.
|--------------------------------------------------------------------------
| Even with the "All Properties" pseudo-asset active, a user assigned only to
| A must see only A's set + consolidated, never B's. This is the trap the
| production guard closes (All-mode leaked every property to restricted users).
*/
it('all-properties: a restricted user in All-mode still sees only A + consolidated, never B', function (string $resource, string $maker) {
    $a = $maker($this->assetA->id);
    $b = $maker($this->assetB->id);
    $consolidated = $maker(null);

    // Restricted user assigned to A — but browsing "All Properties".
    $this->actingAs(makeUser('manager', [$this->assetA->id]));

    $ids = asTenant($this->all, fn () => scopedResourceQuery($resource)->pluck('id')->all());

    expect($ids)->toContain($a->id)
        ->and($ids)->toContain($consolidated->id)
        ->and($ids)->not->toContain($b->id);
})->with($group);

/*
|--------------------------------------------------------------------------
| (c) WRITE-GUARD — assertAssetInScope rejects out-of-scope, allows in-scope.
|--------------------------------------------------------------------------
*/
it('write-guard: rejects B, allows A for a user restricted to A', function (string $resource, string $maker) {
    $this->actingAs(makeUser('manager', [$this->assetA->id]));

    // In-scope: no throw.
    $resource::assertAssetInScope($this->assetA->id);
    expect(true)->toBeTrue();

    // Out-of-scope property: blocked.
    expect(fn () => $resource::assertAssetInScope($this->assetB->id))
        ->toThrow(HttpException::class);
})->with($group);

/*
|--------------------------------------------------------------------------
| (d) GROUP-SPECIFIC — null (consolidated) row is READABLE by everyone but
|     only super_admin may CREATE one; a restricted user is blocked.
|--------------------------------------------------------------------------
*/
it('null-consolidated write-guard: throws for a restricted user, no-op for super_admin', function (string $resource, string $maker) {
    // Restricted user assigned to A cannot post a consolidated (null) row.
    $this->actingAs(makeUser('manager', [$this->assetA->id]));
    expect(fn () => $resource::assertAssetInScope(null))
        ->toThrow(HttpException::class);

    // super_admin (portfolio) may post a consolidated row — no throw.
    $this->actingAs(makeUser('super_admin'));
    $resource::assertAssetInScope(null);
    expect(true)->toBeTrue();
})->with($group);

/*
|--------------------------------------------------------------------------
| (d') GROUP-SPECIFIC READ — the consolidated (null) row surfaces to EVERYONE,
|      including a user restricted to B who has no A/B-owned rows of their own.
|      Confirms null-asset rows are shared, not accidentally hidden.
|--------------------------------------------------------------------------
*/
it('consolidated (null) rows surface even to a user with a different assigned property', function (string $resource, string $maker) {
    $consolidated = $maker(null);
    $b = $maker($this->assetB->id);

    // User restricted to B, browsing property B.
    $this->actingAs(makeUser('manager', [$this->assetB->id]));

    $ids = asTenant($this->assetB, fn () => scopedResourceQuery($resource)->pluck('id')->all());

    expect($ids)->toContain($consolidated->id) // shared consolidated row is visible
        ->and($ids)->toContain($b->id);
})->with($group);
