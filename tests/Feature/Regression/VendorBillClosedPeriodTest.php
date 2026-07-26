<?php

use App\Filament\Admin\Resources\VendorBills\Pages\CreateVendorBill;
use App\Filament\Admin\Resources\VendorBills\Pages\EditVendorBill;
use App\Models\AccountingPeriod;
use App\Models\Vendor;
use App\Models\VendorBill;
use App\Services\Accounting\FiscalCalendar;
use App\Services\Accounting\PeriodService;
use App\Services\VendorBillService;
use Database\Seeders\AccountMappingSeeder;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\RolesPermissionsSeeder;
use Filament\Facades\Filament;
use Illuminate\Support\Carbon;
use Livewire\Livewire;

/**
 * Regression — gap-analysis **D-96** (modules 15/21/29): `VendorBill::bill_date` is operator-typed
 * and becomes the journal entry's `entry_date`, but nothing checked the target period was open.
 *
 * Same class as F-89/F-93: a bill dated into a CLOSED period would commit, yet its GL post would
 * silently fail (the sync job rejects a sealed period and only logs it), so the AP subledger and
 * the GL diverge with the operator told "saved". `CreateVendorBill` / `EditVendorBill` now run the
 * `App\Support\PostingDate` guard on bill_date, rendered on the field.
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
    // A month earlier in the current fiscal year, sealed by a month-end close.
    $this->closedDate = now()->startOfYear()->addDays(14)->toDateString();
    app(PeriodService::class)->closePeriod(AccountingPeriod::forDate(Carbon::parse($this->closedDate)));
});

afterEach(fn () => Filament::setTenant(null, isQuiet: true));

it('refuses to create a vendor bill dated into a closed period', function () {
    Livewire::test(CreateVendorBill::class)
        ->fillForm([
            'vendor_id' => $this->vendor->id,
            'category' => 'maintenance',
            'bill_date' => $this->closedDate,
            'subtotal' => 10000,
            'vat_amount' => 1400,
            'total' => 11400,
        ])
        ->call('create')
        ->assertHasFormErrors(['bill_date']);

    expect(VendorBill::count())->toBe(0);
});

it('still creates a vendor bill dated in an open period', function () {
    Livewire::test(CreateVendorBill::class)
        ->fillForm([
            'vendor_id' => $this->vendor->id,
            'category' => 'maintenance',
            'bill_date' => now()->toDateString(),
            'subtotal' => 10000,
            'vat_amount' => 1400,
            'total' => 11400,
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    expect(VendorBill::count())->toBe(1);
});

it('refuses an edit that moves bill_date into a closed period', function () {
    $bill = VendorBill::create([
        'asset_id' => Filament::getTenant()->id,
        'vendor_id' => $this->vendor->id,
        'category' => 'maintenance',
        'bill_date' => now()->toDateString(),
        'subtotal' => 10000, 'vat_amount' => 1400, 'total' => 11400,
        'status' => 'draft',
    ]);

    Livewire::test(EditVendorBill::class, ['record' => $bill->getRouteKey()])
        ->fillForm(['bill_date' => $this->closedDate])
        ->call('save')
        ->assertHasFormErrors(['bill_date']);

    expect($bill->fresh()->bill_date->toDateString())->toBe(now()->toDateString());
});

it('allows an unrelated edit of a bill whose unchanged date sits in a closed period', function () {
    // The changed-only logic must not re-block a bill legitimately dated before the close.
    $bill = VendorBill::create([
        'asset_id' => Filament::getTenant()->id,
        'vendor_id' => $this->vendor->id,
        'category' => 'maintenance',
        'bill_date' => $this->closedDate,
        'subtotal' => 10000, 'vat_amount' => 1400, 'total' => 11400,
        'status' => 'draft',
    ]);

    Livewire::test(EditVendorBill::class, ['record' => $bill->getRouteKey()])
        ->fillForm(['notes' => 'Chasing the vendor for the PO number'])
        ->call('save')
        ->assertHasNoFormErrors();
});

/*
|--------------------------------------------------------------------------
| The same guard on the PAYMENT and APPROVE paths (the gap the sweep found).
| payment_date and the recognition-at-approve are both operator-controlled
| GL entry_dates that the bill-create/edit guard never covered.
|--------------------------------------------------------------------------
*/

function openApprovedBill(): VendorBill
{
    $bill = VendorBill::create([
        'asset_id' => Filament::getTenant()->id,
        'vendor_id' => test()->vendor->id,
        'category' => 'maintenance',
        'bill_date' => now()->toDateString(),
        'subtotal' => 10000, 'vat_amount' => 1400, 'total' => 11400,
        'status' => 'draft',
    ]);

    return app(VendorBillService::class)->approve($bill)->fresh();
}

it('refuses a vendor-bill PAYMENT dated into a closed period (the AP mirror of the AR receipt)', function () {
    $bill = openApprovedBill();

    expect(fn () => app(VendorBillService::class)->recordPayment($bill, 5000, 'bank_transfer', Carbon::parse($this->closedDate)))
        ->toThrow(DomainException::class);

    // Nothing committed — no payment row, the payable is untouched.
    expect($bill->fresh()->payments()->count())->toBe(0)
        ->and((float) $bill->fresh()->balance)->toBe(11400.0);
});

it('still records a payment dated in an open period', function () {
    $bill = openApprovedBill();

    $paid = app(VendorBillService::class)->recordPayment($bill, 5000, 'bank_transfer', now());

    expect($paid)->toBe(5000.0)
        ->and($bill->fresh()->payments()->count())->toBe(1);
});

it('refuses to APPROVE a draft whose bill_date sits in a closed period (recognition would strand)', function () {
    $bill = VendorBill::create([
        'asset_id' => Filament::getTenant()->id,
        'vendor_id' => $this->vendor->id,
        'category' => 'maintenance',
        'bill_date' => $this->closedDate,
        'subtotal' => 10000, 'vat_amount' => 1400, 'total' => 11400,
        'status' => 'draft',
    ]);

    expect(fn () => app(VendorBillService::class)->approve($bill))->toThrow(DomainException::class);
    expect($bill->fresh()->status)->toBe('draft');
});
