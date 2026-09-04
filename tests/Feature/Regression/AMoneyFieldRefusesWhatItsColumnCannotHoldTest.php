<?php

use App\Filament\Admin\Resources\VendorBills\Pages\CreateVendorBill;
use App\Models\Vendor;
use App\Models\VendorBill;
use App\Services\Accounting\FiscalCalendar;
use Database\Seeders\AccountMappingSeeder;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\RolesPermissionsSeeder;
use Filament\Facades\Filament;
use Livewire\Livewire;

/**
 * Regression: A MONEY FIELD REFUSES WHAT ITS COLUMN CANNOT HOLD, WITH A MESSAGE.
 *
 * `vendor_bills.subtotal`, `vat_amount` and `total` are all `decimal(14,2)` (measured 2026-09-05,
 * `show columns from vendor_bills` on the dev MySQL), so the driver's own ceiling is
 * 999,999,999,999.99. Both writable fields carried `->minValue(0)` and no ceiling at all, and
 * reaching the driver's is NOT a validation message: measured the same day in a session-local
 * TEMPORARY table on MySQL 8.0.33 (session `sql_mode` includes `STRICT_TRANS_TABLES`),
 * `insert 999999999999.99` succeeds and `insert 1000000000000.00` raises
 * `SQLSTATE[22003]: Numeric value out of range: 1264 Out of range value` — a `QueryException`, so
 * the 500 page, after the operator pressed Save and lost the form they had filled in.
 *
 * **The suite structurally cannot see the driver half.** SQLite renders the same column as a bare
 * `numeric` with no precision — `Schema::getColumns()` answers `type => numeric` there, measured —
 * so it stores the overflowing figure silently. That is why this file tests the FORM's refusal
 * rather than the write: the refusal is the thing that exists in both drivers, and it is also the
 * thing the operator actually experiences.
 *
 * The ceiling is a TENTH of the column's, and the last case is why: `VendorBill::saving()`
 * re-derives `total = subtotal + vat_amount` on every write path, into a column of the same width,
 * so capping each half at the column's own ceiling would still let the pair overflow the total.
 *
 * Sibling of SW-077, which did the same for `facility_work_orders.est_labour_hours`.
 */
beforeEach(function () {
    $this->seed(RolesPermissionsSeeder::class);
    $this->seed(ChartOfAccountsSeeder::class);
    $this->seed(AccountMappingSeeder::class);
    app(FiscalCalendar::class)->ensureYear((int) now()->year);

    $this->actingAs(makeUser('super_admin'));
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    Filament::setTenant(makeAsset());

    $this->vendor = Vendor::factory()->create();

    $this->bill = fn (array $money) => Livewire::test(CreateVendorBill::class)
        ->fillForm(array_merge([
            'vendor_id' => $this->vendor->id,
            'category' => 'maintenance',
            'bill_date' => now()->toDateString(),
            'subtotal' => 10000,
            'vat_amount' => 1400,
        ], $money))
        ->call('create');
});

afterEach(fn () => Filament::setTenant(null, isQuiet: true));

it('refuses a subtotal past what the column can hold, on the field', function () {
    ($this->bill)(['subtotal' => 1_000_000_000_000])
        // The RULE, not just "an error": a bare `assertHasFormErrors(['subtotal'])` would pass on
        // any unrelated failure — a missing vendor, a closed period — and prove nothing about the
        // ceiling.
        ->assertHasFormErrors(['subtotal' => 'max']);

    expect(VendorBill::count())->toBe(0, 'Refused before the driver ever saw it.');
});

it('refuses a VAT figure past what the column can hold, on the field', function () {
    ($this->bill)(['vat_amount' => 1_000_000_000_000])
        ->assertHasFormErrors(['vat_amount' => 'max']);

    expect(VendorBill::count())->toBe(0);
});

it('still accepts a bill at the ceiling itself', function () {
    // The control. A ceiling set low enough to refuse real supplier invoices would satisfy both
    // refusals above and be worse than the bug it fixed — nothing a mall actually receives comes
    // near 99.9 billion EGP, and this proves the bound is the driver's and not somebody's guess.
    ($this->bill)([
        'subtotal' => VendorBill::MAX_DOCUMENT_AMOUNT,
        'vat_amount' => 0,
    ])->assertHasNoFormErrors();

    $bill = VendorBill::sole();

    expect((float) $bill->subtotal)->toBe(VendorBill::MAX_DOCUMENT_AMOUNT)
        ->and((float) $bill->total)->toBe(VendorBill::MAX_DOCUMENT_AMOUNT);
});

it('still accepts an ordinary six-figure bill', function () {
    // The other control, and the one that matters most: the fix must be invisible on real work.
    ($this->bill)(['subtotal' => 250_000, 'vat_amount' => 35_000])
        ->assertHasNoFormErrors();

    expect((float) VendorBill::sole()->total)->toBe(285_000.0);
});

it('leaves the derived total inside its own column, whatever the two halves are', function () {
    // `VendorBill::saving()` recomputes `total = subtotal + vat_amount` on EVERY write path, into a
    // `decimal(14,2)` of its own. So the two ceilings are not independent: capping each half at the
    // column's own ceiling would let a perfectly valid pair overflow the total and 500 anyway, on a
    // field the operator cannot even type into. Two of ours sum to 199,999,999,999.98.
    $columnCeiling = 999_999_999_999.99;

    expect(VendorBill::MAX_DOCUMENT_AMOUNT * 2)->toBeLessThanOrEqual($columnCeiling)
        // …and not so cautious that it stops being a real ceiling: the bound must still be far past
        // anything a mall receives, or the refusals above are testing an arbitrary small number.
        ->and(VendorBill::MAX_DOCUMENT_AMOUNT)->toBeGreaterThan(1_000_000_000.0);

    // Proved through the real form rather than by arithmetic alone: the largest pair the two fields
    // will now accept still writes a total the column holds.
    ($this->bill)([
        'subtotal' => VendorBill::MAX_DOCUMENT_AMOUNT,
        'vat_amount' => VendorBill::MAX_DOCUMENT_AMOUNT,
    ])->assertHasNoFormErrors();

    expect((float) VendorBill::sole()->total)->toBeLessThanOrEqual($columnCeiling);
});
