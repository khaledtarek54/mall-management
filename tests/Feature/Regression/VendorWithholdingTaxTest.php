<?php

use App\Models\JournalLine;
use App\Models\LedgerAccount;
use App\Models\Vendor;
use App\Models\VendorBill;
use App\Services\VendorBillService;
use App\Settings\TaxSettings;
use App\Support\WithholdingTax;
use Database\Seeders\AccountMappingSeeder;
use Database\Seeders\ChartOfAccountsSeeder;

/**
 * Egyptian withholding tax on supplier payments — خصم وإضافة (module 12b).
 *
 * Atriom paid vendors GROSS. Under Income Tax Law 91/2005 art. 59 an Egyptian entity must withhold
 * tax at source on local supplier payments and remit it to the ETA; failing to withhold makes the
 * un-withheld amount the operator's OWN liability. This is the AP-side twin of the VAT already
 * handled on the AR side.
 *
 * The invariant that matters: `amount` settles the payable in full (the vendor's claim is
 * discharged — part cash, part tax paid on their behalf), while only the CASH leg shrinks. Getting
 * that backwards would leave every paid bill showing a phantom balance.
 *
 * Per the GL registry rule, the tie-out here is driven through the real service AND the real
 * `accounting:sync-ledger` sweep — not by calling LedgerPoster directly, which would prove only
 * the journalizer's arithmetic.
 */
function whtSetup(float $rate = 3.0, ?float $vendorRate = null): array
{
    $settings = app(TaxSettings::class);
    $settings->wht_enabled = true;
    $settings->wht_default_rate = $rate;
    $settings->save();

    $asset = makeAsset();
    $vendor = Vendor::create([
        'name' => 'Falcon Facilities '.uniqid(),
        'status' => Vendor::STATUS_ACTIVE,
        'withholding_tax_rate' => $vendorRate,
    ]);

    $bill = VendorBill::create([
        'number' => 'VB-'.uniqid(),
        'vendor_id' => $vendor->id,
        'asset_id' => $asset->id,
        'category' => 'cleaning_security',
        'status' => 'approved',
        'bill_date' => now()->toDateString(),
        'due_date' => now()->addDays(30)->toDateString(),
        'subtotal' => 1000, 'vat_amount' => 0, 'total' => 1000, 'balance' => 1000,
    ]);

    return [$asset, $vendor, $bill];
}

it('settles the payable in full while only the cash leg shrinks', function () {
    [, , $bill] = whtSetup(3.0);

    $paid = app(VendorBillService::class)->recordPayment($bill, 1000.0);

    $payment = $bill->refresh()->payments()->sole();

    expect($paid)->toBe(1000.0)
        ->and((float) $payment->amount)->toBe(1000.0)          // discharges the vendor's claim
        ->and((float) $payment->withholding_amount)->toBe(30.0) // owed onward to the ETA
        ->and($payment->netPaid())->toBe(970.0)                 // what left the bank
        // The bill must be fully settled — the vendor is owed nothing more.
        ->and((float) $bill->balance)->toBe(0.0)
        ->and((float) $bill->paid_amount)->toBe(1000.0);
});

it('posts Dr AP / Cr bank(net) / Cr WHT payable through the real sweep', function () {
    $this->seed(ChartOfAccountsSeeder::class);
    $this->seed(AccountMappingSeeder::class);
    [$asset, , $bill] = whtSetup(3.0);

    app(VendorBillService::class)->recordPayment($bill, 1000.0);

    // Drive the REAL sweep, not LedgerPoster directly — a journalizer can be arithmetically
    // perfect and still never reach the books (the documented GL registry trap).
    $this->artisan('accounting:sync-ledger', ['--all' => true])->assertSuccessful();

    $payment = $bill->refresh()->payments()->sole();
    $lines = JournalLine::query()
        ->whereHas('entry', fn ($q) => $q
            ->where('source_type', $payment->getMorphClass())
            ->where('source_id', $payment->id))
        ->get();

    $byCode = fn (string $code) => $lines
        ->firstWhere('ledger_account_id', LedgerAccount::where('code', $code)->value('id'));

    expect((float) $byCode('21101001')?->debit)->toBe(1000.0)   // AP cleared gross
        ->and((float) $byCode('11102001')?->credit)->toBe(970.0) // bank: only the net left
        ->and((float) $byCode('21303001')?->credit)->toBe(30.0)  // WHT payable to the ETA
        // Double-entry must still balance, and the property dimension must be carried.
        ->and(round($lines->sum('debit'), 2))->toBe(round($lines->sum('credit'), 2))
        ->and($lines->pluck('asset_id')->unique()->all())->toBe([$asset->id]);
});

it('posts no withholding leg at all when the feature is off', function () {
    $this->seed(ChartOfAccountsSeeder::class);
    $this->seed(AccountMappingSeeder::class);
    [, , $bill] = whtSetup(3.0);
    $settings = app(TaxSettings::class);
    $settings->wht_enabled = false;
    $settings->save();

    app(VendorBillService::class)->recordPayment($bill, 1000.0);
    $this->artisan('accounting:sync-ledger', ['--all' => true])->assertSuccessful();

    $payment = $bill->refresh()->payments()->sole();
    $lines = JournalLine::query()
        ->whereHas('entry', fn ($q) => $q
            ->where('source_type', $payment->getMorphClass())
            ->where('source_id', $payment->id))
        ->get();

    $whtAccountId = LedgerAccount::where('code', '21303001')->value('id');

    expect((float) $payment->withholding_amount)->toBe(0.0)
        ->and($lines)->toHaveCount(2)
        ->and($lines->contains('ledger_account_id', $whtAccountId))->toBeFalse();
});

it('honours a per-vendor rate over the portfolio default, and 0 means exempt', function () {
    // A vendor rate of 5% beats the 3% default…
    [, , $agreed] = whtSetup(3.0, 5.0);
    app(VendorBillService::class)->recordPayment($agreed, 1000.0);
    expect((float) $agreed->refresh()->payments()->sole()->withholding_amount)->toBe(50.0);

    // …and an explicit 0 is "exempt", NOT "unset" — it must not fall through to the default.
    [, , $exempt] = whtSetup(3.0, 0.0);
    app(VendorBillService::class)->recordPayment($exempt, 1000.0);
    expect((float) $exempt->refresh()->payments()->sole()->withholding_amount)->toBe(0.0);
});

it('withholds proportionally on a partial payment', function () {
    [, , $bill] = whtSetup(3.0);

    app(VendorBillService::class)->recordPayment($bill, 400.0);

    $payment = $bill->refresh()->payments()->sole();

    expect((float) $payment->withholding_amount)->toBe(12.0)
        ->and($payment->netPaid())->toBe(388.0)
        // Still owed 600 — withholding does not reduce the debt, only the cash.
        ->and((float) $bill->balance)->toBe(600.0);
});

it('never lets a mis-set rate drive cash negative', function () {
    $vendor = Vendor::create(['name' => 'Odd Co', 'status' => Vendor::STATUS_ACTIVE]);
    $settings = app(TaxSettings::class);
    $settings->wht_enabled = true;
    $settings->wht_default_rate = 250.0; // fat-fingered
    $settings->save();

    // Clamped to the payment itself, so the bank leg can never post a negative credit
    // (which would silently reverse the direction of the entry).
    expect(WithholdingTax::on(1000.0, $vendor))->toBe(1000.0);
});
