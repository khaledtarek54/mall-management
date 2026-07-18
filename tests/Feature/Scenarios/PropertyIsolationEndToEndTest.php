<?php

use App\Filament\Admin\Resources\Expenses\Pages\CreateExpense;
use App\Filament\Admin\Resources\TenantRequests\Pages\CreateTenantRequest;
use App\Filament\Admin\Resources\Payments\Pages\CreatePayment;
use App\Filament\Admin\Resources\TenantSalesDeclarations\Pages\CreateTenantSalesDeclaration;
use App\Models\Expense;
use App\Models\TenantRequest;
use App\Models\TenantSalesDeclaration;
use App\Services\Accounting\FiscalCalendar;
use Database\Seeders\AccountMappingSeeder;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\RolesPermissionsSeeder;
use Filament\Facades\Filament;
use Livewire\Livewire;

/**
 * BEHAVIORAL property-isolation gate — the recommendation from the 2026-07 security
 * sweep. Proves the OUTCOME end-to-end: a property-restricted user in All-Properties
 * mode who submits a tampered property (direct asset_id, or a lease/unit/invoice FK)
 * through a real Create page must NOT create a record in the other property — while an
 * in-scope submit succeeds (so the block is real isolation, not an unrelated form error).
 *
 * Isolation here is enforced by TWO layers, and this asserts the outcome regardless of
 * which fires:
 *   1. the scoped picker — Filament validates a closure-`options()` Select with `in:`
 *      against the user's visible set, so an out-of-scope value fails validation; and
 *   2. the server guard (GuardsAssetInScope) — the load-bearing line for `relationship()`
 *      Selects (validated with `exists:`, which does NOT check scope) and for any
 *      non-form write path. Its correctness is proven directly in the regression suite
 *      (AssetInScopeWriteGuardTest) and its wiring by PropertyIsolationConformanceTest.
 */

beforeEach(function () {
    $this->seed(RolesPermissionsSeeder::class);
    $this->seed(ChartOfAccountsSeeder::class);
    $this->seed(AccountMappingSeeder::class);
    app(FiscalCalendar::class)->ensureYear((int) now()->year);

    $this->a = makeAsset(['code' => 'E2EA']);
    $this->b = makeAsset(['code' => 'E2EB']);
    $this->all = ensureAllPropertiesAsset();

    // A user restricted to property A only (manager holds every create permission).
    $this->actingAs(makeUser('manager', [$this->a->id]));
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    // All-Properties mode: currentAssetId() is null, so the property field / FK picker
    // is client-supplied — the exact surface the guard must defend.
    Filament::setTenant($this->all);
});

afterEach(fn () => Filament::setTenant(null, isQuiet: true));

/** Drive a Create page and swallow the guard's 403 — the outcome is asserted by the caller. */
function attemptCreate(string $page, array $data): void
{
    try {
        Livewire::test($page)->fillForm($data)->call('create');
    } catch (\Throwable) {
        // Blocked (guard abort(403) or scoped-picker validation) — intended.
    }
}

it('direct asset_id (Expense): blocks a tampered property, allows the in-scope one', function () {
    $valid = [
        'category' => 'utilities',
        'paid_from' => 'cash',
        'expense_date' => now()->toDateString(),
        'amount' => 1000,
        'vat_amount' => 140,
    ];

    // In-scope create succeeds → the form data is genuinely valid.
    Livewire::test(CreateExpense::class)
        ->fillForm($valid + ['asset_id' => $this->a->id])
        ->call('create')
        ->assertHasNoFormErrors();
    expect(Expense::where('asset_id', $this->a->id)->exists())->toBeTrue();

    // Tampered property → no expense lands in B.
    attemptCreate(CreateExpense::class, $valid + ['asset_id' => $this->b->id]);
    expect(Expense::where('asset_id', $this->b->id)->exists())->toBeFalse();
});

it('lease-derived (TenantSalesDeclaration): blocks a foreign-property lease', function () {
    $leaseA = makeLease(makeUnit($this->a));
    $leaseB = makeLease(makeUnit($this->b));

    $valid = [
        'period_start' => now()->startOfMonth()->subMonth()->toDateString(),
        'period_end' => now()->subMonth()->endOfMonth()->toDateString(),
        'declared_sales' => 5000,
    ];

    // In-scope lease succeeds.
    Livewire::test(CreateTenantSalesDeclaration::class)
        ->fillForm($valid + ['lease_id' => $leaseA->id])
        ->call('create')
        ->assertHasNoFormErrors();
    expect(TenantSalesDeclaration::where('lease_id', $leaseA->id)->exists())->toBeTrue();

    // A lease in property B → no declaration lands against it.
    attemptCreate(CreateTenantSalesDeclaration::class, $valid + ['lease_id' => $leaseB->id]);
    expect(TenantSalesDeclaration::where('lease_id', $leaseB->id)->exists())->toBeFalse();
});

it('unit-derived (TenantRequest): blocks a foreign-property unit', function () {
    $unitA = makeUnit($this->a);
    $unitB = makeUnit($this->b);
    $tenant = makeTenant();

    $valid = [
        'request_type' => \App\Enums\TenantRequestType::default()->value,
        'tenant_id' => $tenant->id,
        'priority' => 'medium',
        'title' => 'Broken AC',
        'description' => 'The air conditioning unit is not working.',
    ];

    // In-scope unit succeeds.
    Livewire::test(CreateTenantRequest::class)
        ->fillForm($valid + ['unit_id' => $unitA->id])
        ->call('create')
        ->assertHasNoFormErrors();
    expect(TenantRequest::where('unit_id', $unitA->id)->exists())->toBeTrue();

    // A unit in property B → no request lands against it.
    attemptCreate(CreateTenantRequest::class, $valid + ['unit_id' => $unitB->id]);
    expect(TenantRequest::where('unit_id', $unitB->id)->exists())->toBeFalse();
});

it('invoice-derived (Payment): blocks allocating to a foreign-property invoice', function () {
    // A shared tenant leasing in BOTH properties — the guard must scope per-property,
    // not merely per-tenant (assertInvoicesShareTenant alone would pass here).
    $tenant = makeTenant();
    $invoiceA = makeInvoice(makeLease(makeUnit($this->a), $tenant));
    $invoiceB = makeInvoice(makeLease(makeUnit($this->b), $tenant));

    $base = [
        'tenant_id' => $tenant->id,
        'payment_date' => now()->toDateString(),
        'amount' => 1000,
        'method' => 'cash',
        'status' => 'captured',
    ];

    // Allocating to the in-scope invoice succeeds.
    Livewire::test(CreatePayment::class)
        ->fillForm($base + ['allocations' => [['invoice_id' => $invoiceA->id, 'allocated_amount' => 1000]]])
        ->call('create')
        ->assertHasNoFormErrors();
    expect($invoiceA->fresh()->payments()->count())->toBe(1);

    // Allocating to the other property's invoice (same tenant) is blocked → B untouched.
    attemptCreate(CreatePayment::class, $base + ['allocations' => [['invoice_id' => $invoiceB->id, 'allocated_amount' => 1000]]]);
    expect($invoiceB->fresh()->payments()->count())->toBe(0);
});
