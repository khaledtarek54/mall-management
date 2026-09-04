<?php

/*
|--------------------------------------------------------------------------
| Withholding is the rung in force on the PAYMENT's date, not today's (SW-087)
|--------------------------------------------------------------------------
| `WithholdingTax::rateFor()` takes a date and its docblock says why — "a withholding rate has a
| validity period like every other rate in the catalogue, and a back-dated payment must withhold
| what was due when it was made". `WithholdingByTaxCodeTest` already pins that the primitive obeys
| it. The two CALLERS did not pass one: `VendorBillService::recordPayment()` and the breakdown the
| operator reads on the payment modal both went through with `$on = null`, so
| `TaxCode::rateFromLadder()` fell to `CarbonImmutable::now()` and resolved TODAY's rung.
|
| That is money in both directions, on a figure `WithholdingCertificatePdfService` then certifies to
| the supplier so they can set it against their own income tax: withhold too much and the operator
| short-pays the vendor and over-remits to the ETA; too little and the operator carries the shortfall
| themselves under Law 91/2005 art. 59.
|
| Live only once `wht_enabled` is switched on — which is exactly why it is worth pinning before the
| accountant switches it on rather than in a supplier's first reconciliation.
*/

use App\Filament\Admin\Resources\VendorBills\Pages\EditVendorBill;
use App\Models\Vendor;
use App\Models\VendorBill;
use App\Services\Accounting\FiscalCalendar;
use App\Services\VendorBillService;
use App\Settings\TaxSettings;
use Carbon\CarbonImmutable;
use Database\Seeders\AccountMappingSeeder;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\RolesPermissionsSeeder;
use Database\Seeders\TaxCodeSeeder;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\Support\TaxCatalogue;

beforeEach(function () {
    // A fixed today, so "the rate in force on the payment date" and "the rate in force now" are
    // provably different numbers rather than the same one by accident.
    Carbon::setTestNow('2026-09-15 09:00:00');

    $this->seed(RolesPermissionsSeeder::class);
    $this->seed(ChartOfAccountsSeeder::class);
    $this->seed(AccountMappingSeeder::class);
    $this->seed(TaxCodeSeeder::class);
    app(FiscalCalendar::class)->ensureYear((int) now()->year);

    $this->actingAs(makeUser('super_admin'));
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    Filament::setTenant(makeAsset());

    // The operator's own catalogue: WH_3_P was 3% and rises to 5% from 1 September. The sheet
    // writes withholding NEGATIVE, which is what the seeded rows do too.
    TaxCatalogue::setOnlyRate('WH_3_P', -3.0, '2017-07-01');
    TaxCatalogue::setRate('WH_3_P', -5.0, '2026-09-01');

    $settings = app(TaxSettings::class);
    $settings->wht_enabled = true;
    $settings->wht_default_tax_code = 'WH_3_P';
    $settings->save();

    $this->vendor = Vendor::create(['name' => 'SupplyCo', 'status' => 'active']);
});

afterEach(function () {
    Filament::setTenant(null, isQuiet: true);
    Carbon::setTestNow();
});

/** 100,000 of consideration plus 14% VAT — the ordinary Egyptian service bill. */
function whtDatedBill(): VendorBill
{
    $bill = VendorBill::create([
        'vendor_id' => test()->vendor->id,
        'asset_id' => Filament::getTenant()->id,
        'category' => 'maintenance',
        'status' => 'approved',
        'bill_date' => '2026-08-01',
        'due_date' => '2026-08-31',
        'subtotal' => 100000,
        'vat_amount' => 14000,
        'total' => 114000,
        'paid_amount' => 0,
        'balance' => 114000,
    ]);

    return $bill->fresh();
}

/** What was actually withheld across every live payment on the bill. */
function whtDatedWithheldOn(VendorBill $bill): float
{
    return round((float) $bill->payments()->whereNull('voided_at')->sum('withholding_amount'), 2);
}

it('withholds the rate that was in force when the payment was made', function () {
    $bill = whtDatedBill();

    // Keyed in mid-September for money that left the bank on 20 August, when the rate was 3%.
    app(VendorBillService::class)->recordPayment(
        $bill, 114000, 'bank_transfer', CarbonImmutable::parse('2026-08-20'),
    );

    // 3% of the 100,000 net supply. It was withholding 5,000 — September's rung — so the supplier
    // was short-paid 2,000 and the same 2,000 was over-remitted to the ETA.
    expect(whtDatedWithheldOn($bill->fresh()))->toBe(3000.0);
});

it('still withholds the current rung for a payment made today', function () {
    // The control. A fix that simply pinned the older rate would satisfy the case above and be
    // wrong for every ordinary payment, which is most of them.
    $bill = whtDatedBill();

    app(VendorBillService::class)->recordPayment($bill, 114000, 'bank_transfer', now());

    expect(whtDatedWithheldOn($bill->fresh()))->toBe(5000.0);
});

it('withholds the current rung when no date is stated at all', function () {
    // `payment_date` is nullable on the service and defaults to now, so "not stated" must keep
    // behaving exactly as it did before this change.
    $bill = whtDatedBill();

    app(VendorBillService::class)->recordPayment($bill, 114000);

    expect(whtDatedWithheldOn($bill->fresh()))->toBe(5000.0);
});

it('shows the operator the rung the bank will actually withhold at', function () {
    // The preview and the service must agree, which is the whole reason the breakdown exists. When
    // a displayed figure and a recorded one drift apart nobody notices, because the screen is the
    // only thing anyone checks.
    $bill = whtDatedBill();

    $component = Livewire::test(EditVendorBill::class, ['record' => $bill->getRouteKey()])
        ->mountAction(TestAction::make('record_payment'))
        ->setActionData(['amount' => 114000, 'payment_date' => '2026-08-20']);

    $page = $component->instance();
    $schema = $page->getSchema($page->getMountedActionSchemaName());
    $components = $schema->getFlatComponents();

    $preview = strip_tags((string) $components['wht_breakdown']->getContent());

    expect($preview)->toContain('at 3%')
        ->and($preview)->toContain('3,000.00')
        // September's rung, which is what it used to quote for an August payment.
        ->and($preview)->not->toContain('5,000.00');

    // …and the field the preview now depends on must re-render it when the operator moves the date.
    // Without this the breakdown silently keeps quoting the rung in force today.
    expect($components['payment_date']->isLive())->toBeTrue();
});
