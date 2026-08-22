<?php

use App\Filament\Admin\Pages\WithholdingTaxReturn;
use App\Models\JournalEntry;
use App\Models\Vendor;
use App\Models\VendorBill;
use App\Services\Accounting\FiscalCalendar;
use App\Services\Reports\WithholdingTaxReturnService;
use App\Services\VendorBillService;
use App\Services\WithholdingCertificatePdfService;
use App\Settings\TaxSettings;
use Carbon\CarbonImmutable;
use Database\Seeders\AccountMappingSeeder;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\RolesPermissionsSeeder;
use Database\Seeders\TaxCodeSeeder;
use Filament\Facades\Filament;
use Livewire\Livewire;

/**
 * **The filing artefact Egypt requires, and the certificate the supplier needs — EG-21.**
 *
 * The withholding ENGINE has been right for months: per-vendor code → portfolio default → nothing,
 * resolved for the payment's date, charged on the VAT-exclusive share, posting
 * `Cr withholding_tax_payable`. What did not exist was any way to DECLARE it (Form 41, quarterly) or
 * to EVIDENCE it (a certificate the supplier sets against their own income tax). That absence is
 * what kept `wht_enabled` switched off: withholding money you cannot declare is worse than not
 * withholding it.
 *
 * The tie-out is the property worth pinning. Two independent sides — what was deducted from
 * suppliers (`vendor_bill_payments.withholding_amount`) and what the books owe the ETA (the credit
 * movement on `withholding_tax_payable`) — must agree, and every refusal below is paired with a
 * control that must go the other way.
 */
function whtReturnSetup(float $rate = 3.0): array
{
    test()->seed(ChartOfAccountsSeeder::class);
    test()->seed(AccountMappingSeeder::class);
    test()->seed(TaxCodeSeeder::class);
    app(FiscalCalendar::class)->ensureYear((int) now()->year);

    $settings = app(TaxSettings::class);
    $settings->wht_enabled = true;
    $settings->wht_default_tax_code = match ($rate) {
        0.5 => 'WH_0_5_P', 1.0 => 'WH_1_P', 5.0 => 'WH_5_P', default => 'WH_3_P',
    };
    $settings->save();

    $asset = makeAsset(['code' => 'WH']);

    return [$asset];
}

function whtBillFor(Vendor $vendor, $asset, float $net, float $vat = 0.0): VendorBill
{
    return VendorBill::create([
        'number' => 'VB-'.uniqid(),
        'vendor_id' => $vendor->id,
        'asset_id' => $asset->id,
        'category' => 'cleaning_security',
        'status' => 'approved',
        'bill_date' => now()->startOfMonth()->toDateString(),
        'due_date' => now()->addDays(30)->toDateString(),
        'subtotal' => $net, 'vat_amount' => $vat, 'total' => $net + $vat, 'balance' => $net + $vat,
    ]);
}

it('lists each supplier withheld from, and ties out to the ledger', function () {
    [$asset] = whtReturnSetup(3.0);

    $cleaner = Vendor::create(['name' => 'Nile Cleaning', 'status' => Vendor::STATUS_ACTIVE, 'tax_id' => '123-456-789']);
    $security = Vendor::create(['name' => 'Delta Security', 'status' => Vendor::STATUS_ACTIVE, 'tax_id' => '987-654-321']);

    app(VendorBillService::class)->recordPayment(whtBillFor($cleaner, $asset, 10_000), 10_000);
    app(VendorBillService::class)->recordPayment(whtBillFor($cleaner, $asset, 5_000), 5_000);
    app(VendorBillService::class)->recordPayment(whtBillFor($security, $asset, 20_000), 20_000);

    // The REAL sweep, not `LedgerPoster::post()` — a journalizer can be arithmetically perfect and
    // never reach the books, which is the GL-registry trap CLAUDE.md names. The ledger side of the
    // tie-out below is only worth reading if it came the way production's does.
    $this->artisan('accounting:sync-ledger', ['--all' => true])->assertSuccessful();

    $report = app(WithholdingTaxReturnService::class)->for(
        CarbonImmutable::now()->startOfQuarter(),
        CarbonImmutable::now()->endOfQuarter(),
    );

    // Ordered by what was withheld — the biggest exposure first, which is what an accountant checks.
    expect($report['suppliers'])->toHaveCount(2)
        ->and($report['suppliers'][0]['vendor'])->toBe('Delta Security')
        ->and($report['suppliers'][0]['withheld'])->toBe(600.0)
        ->and($report['suppliers'][0]['payments'])->toBe(1)
        ->and($report['suppliers'][1]['vendor'])->toBe('Nile Cleaning')
        // Two payments rolled into one supplier line: 15,000 × 3%.
        ->and($report['suppliers'][1]['withheld'])->toBe(450.0)
        ->and($report['suppliers'][1]['payments'])->toBe(2)
        ->and($report['suppliers'][1]['effective_rate'])->toBe(3.0);

    // THE TIE-OUT. Documents and ledger are two independent reads of the same fact.
    expect($report['withheld_documents'])->toBe(1050.0)
        ->and($report['withheld_ledger'])->toBe(1050.0)
        ->and($report['ties_out'])->toBeTrue()
        ->and($report['difference'])->toBe(0.0)
        // Nothing paid over yet, so the whole amount is still held for the ETA.
        ->and($report['outstanding'])->toBe(1050.0);
});

it('notices when what was withheld is not what the books say', function () {
    // The control for the tie-out above: it has to be ABLE to fail, or it is the reconciliation
    // check that could not be wrong. Withholding recorded on the payment and the entry deleted is
    // exactly the shape of a real failure — a posting that never landed.
    [$asset] = whtReturnSetup(3.0);

    $vendor = Vendor::create(['name' => 'Unposted Supplies', 'status' => Vendor::STATUS_ACTIVE]);
    app(VendorBillService::class)->recordPayment(whtBillFor($vendor, $asset, 10_000), 10_000);
    $this->artisan('accounting:sync-ledger', ['--all' => true])->assertSuccessful();

    // The premise: the entry really was there before it was removed, so what follows is a posting
    // that went missing rather than one that never existed.
    expect(JournalEntry::query()->count())->toBeGreaterThan(0);

    JournalEntry::query()->delete();

    $report = app(WithholdingTaxReturnService::class)->for(
        CarbonImmutable::now()->startOfQuarter(),
        CarbonImmutable::now()->endOfQuarter(),
    );

    expect($report['withheld_documents'])->toBe(300.0)
        ->and($report['withheld_ledger'])->toBe(0.0)
        ->and($report['ties_out'])->toBeFalse()
        ->and($report['difference'])->toBe(300.0);
});

it('charges the base net of VAT, which is what the supplier reconciles against', function () {
    [$asset] = whtReturnSetup(3.0);

    $vendor = Vendor::create(['name' => 'Taxable Supplies', 'status' => Vendor::STATUS_ACTIVE]);

    // 100,000 net + 14,000 VAT, paid in full. Withholding is a prepayment of the supplier's INCOME
    // tax, so it is charged on the consideration — 3,000, not 3,420.
    app(VendorBillService::class)->recordPayment(whtBillFor($vendor, $asset, 100_000, 14_000), 114_000);

    $report = app(WithholdingTaxReturnService::class)->for(
        CarbonImmutable::now()->startOfQuarter(),
        CarbonImmutable::now()->endOfQuarter(),
    );

    expect($report['suppliers'][0]['base'])->toBe(100_000.0)
        ->and($report['suppliers'][0]['withheld'])->toBe(3_000.0)
        ->and($report['suppliers'][0]['effective_rate'])->toBe(3.0);
});

it('reports the rate actually withheld rather than one recomputed today', function () {
    // A supplier can be withheld from twice in a quarter at different rates — a code corrected on
    // the vendor, or a rate revised mid-quarter. A single "agreed rate" column would be a guess, so
    // the report states what was withheld over what it was withheld from.
    [$asset] = whtReturnSetup(3.0);

    $vendor = Vendor::create(['name' => 'Rate Changed Ltd', 'status' => Vendor::STATUS_ACTIVE]);
    app(VendorBillService::class)->recordPayment(whtBillFor($vendor, $asset, 10_000), 10_000);

    $vendor->update(['withholding_tax_code' => 'WH_5_P']);
    app(VendorBillService::class)->recordPayment(whtBillFor($vendor, $asset, 10_000), 10_000);

    $report = app(WithholdingTaxReturnService::class)->for(
        CarbonImmutable::now()->startOfQuarter(),
        CarbonImmutable::now()->endOfQuarter(),
    );

    // 300 + 500 over 20,000 = 4%, which is neither of the two rates and is the honest answer.
    expect($report['suppliers'][0]['withheld'])->toBe(800.0)
        ->and($report['suppliers'][0]['effective_rate'])->toBe(4.0);
});

it('issues a certificate the supplier can actually use', function () {
    [$asset] = whtReturnSetup(3.0);

    $vendor = Vendor::create([
        'name' => 'Certificate Co', 'status' => Vendor::STATUS_ACTIVE, 'tax_id' => '555-666-777',
    ]);

    app(VendorBillService::class)->recordPayment(whtBillFor($vendor, $asset, 40_000), 40_000);

    $certificate = app(WithholdingTaxReturnService::class)->forVendor(
        $vendor,
        CarbonImmutable::now()->startOfQuarter(),
        CarbonImmutable::now()->endOfQuarter(),
    );

    expect($certificate['withheld'])->toBe(1_200.0)
        ->and($certificate['base'])->toBe(40_000.0)
        ->and($certificate['tax_id'])->toBe('555-666-777')
        ->and($certificate['lines'])->toHaveCount(1);

    // …and it RENDERS. A PDF service with a template that references a missing key throws only when
    // somebody presses the button, which is the failure mode this project keeps finding.
    $pdf = app(WithholdingCertificatePdfService::class)->build(
        $vendor,
        CarbonImmutable::now()->startOfQuarter(),
        CarbonImmutable::now()->endOfQuarter(),
    );

    expect($pdf)->toStartWith('%PDF')->and(strlen($pdf))->toBeGreaterThan(1000);
});

it('renders the certificate in Arabic too', function () {
    [$asset] = whtReturnSetup(3.0);
    $vendor = Vendor::create(['name' => 'شركة النيل', 'status' => Vendor::STATUS_ACTIVE, 'tax_id' => '111-222-333']);
    app(VendorBillService::class)->recordPayment(whtBillFor($vendor, $asset, 10_000), 10_000);

    app()->setLocale('ar');

    $pdf = app(WithholdingCertificatePdfService::class)->build(
        $vendor,
        CarbonImmutable::now()->startOfQuarter(),
        CarbonImmutable::now()->endOfQuarter(),
    );

    expect($pdf)->toStartWith('%PDF');

    app()->setLocale('en');
});

it('opens the page and offers the quarters', function () {
    $this->seed(RolesPermissionsSeeder::class);
    [$asset] = whtReturnSetup(3.0);

    $vendor = Vendor::create(['name' => 'Page Supplies', 'status' => Vendor::STATUS_ACTIVE]);
    app(VendorBillService::class)->recordPayment(whtBillFor($vendor, $asset, 10_000), 10_000);

    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $this->actingAs(makeUser('super_admin', [$asset->id]));

    // Mounted, not just constructed: the period picker is built while the FILTER FORM renders, and
    // a `parent::periodOptions()` that reaches nothing throws exactly there — a 500 on open that no
    // test of the service would ever see. It shipped that way for the length of one gate run.
    asTenant($asset, function () {
        Livewire::test(WithholdingTaxReturn::class)
            ->assertOk()
            ->assertSee('Page Supplies');
    });

    Filament::setTenant(null, isQuiet: true);
});
