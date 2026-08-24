<?php

use App\Models\Vendor;
use App\Models\VendorBill;
use App\Services\VendorBillService;
use App\Settings\TaxSettings;
use App\Support\WithholdingTax;
use Database\Seeders\TaxCodeSeeder;

/**
 * Egyptian withholding tax is charged on the supply, not on the VAT.
 *
 * `recordPayment()` passed `min($amount, $bill->balance)` into `WithholdingTax::on()`. That balance
 * derives from `total` — net **plus** VAT — and `on()` applies the rate to whatever it is handed.
 * So every VAT-bearing bill over-withheld: at 3% on a 100,000 net bill, 3,420 instead of 3,000. The
 * operator short-pays the vendor by 420 and over-remits the same to the ETA, on every payment.
 *
 * Withholding under Law 91/2005 art. 59 is a prepayment of the SUPPLIER'S INCOME tax, so its base
 * is the consideration for the supply. The VAT on top is the supplier's own output tax, which they
 * remit themselves — withholding on it taxes a tax.
 *
 * **The whole existing WHT suite was blind to this**: every fixture in `VendorWithholdingTaxTest`
 * sets `vat_amount => 0`, so net and gross were the same number and no test could tell the two
 * bases apart. Nothing was wrong with those tests; the case simply did not exist in them.
 *
 * Live only once `wht_enabled` is switched on — which is exactly why it was worth fixing before the
 * accountant switches it on, rather than in a vendor's first reconciliation.
 */
beforeEach(function () {
    $this->seed(TaxCodeSeeder::class);

    $settings = app(TaxSettings::class);
    $settings->wht_enabled = true;
    // The nature, not the number: `WH_3_P` carries 3% in the operator's own catalogue.
    $settings->wht_default_tax_code = 'WH_3_P';

    $this->vendor = Vendor::create(['name' => 'SupplyCo', 'status' => 'active']);
});

/** A bill of `$net` plus 14% VAT — the ordinary Egyptian service bill. */
function whtVatBill(float $net = 100000, float $vatRate = 14): VendorBill
{
    $vat = round($net * $vatRate / 100, 2);

    $bill = VendorBill::create([
        'vendor_id' => test()->vendor->id,
        'asset_id' => makeAsset()->id,
        'category' => 'maintenance',
        'status' => 'approved',
        'bill_date' => now()->toDateString(),
        'due_date' => now()->addDays(30)->toDateString(),
        'subtotal' => $net,
        'vat_amount' => $vat,
        'total' => round($net + $vat, 2),
        'paid_amount' => 0,
        'balance' => round($net + $vat, 2),
    ]);

    return $bill->fresh();
}

/** What was actually withheld across every live payment on the bill. */
function whtWithheldOn(VendorBill $bill): float
{
    return round((float) $bill->payments()->whereNull('voided_at')->sum('withholding_amount'), 2);
}

it('withholds on the net supply, not on the VAT', function () {
    $bill = whtVatBill(100000, 14); // 100,000 + 14,000 = 114,000

    app(VendorBillService::class)->recordPayment($bill, 114000);

    // 3% of 100,000 = 3,000. It was withholding 3,420 — 3% of the VAT-inclusive 114,000.
    expect(whtWithheldOn($bill->fresh()))->toBe(3000.0);
});

it('settles the payable in full regardless — only the cash leg moves', function () {
    // The invariant the fix must not disturb: the vendor's payable is discharged by the GROSS
    // payment; withholding only splits how it was discharged (part cash, part tax paid on their
    // behalf). A fix that reduced `amount` instead of the withholding would leave a phantom payable.
    $bill = whtVatBill(100000, 14);

    app(VendorBillService::class)->recordPayment($bill, 114000);

    expect((float) $bill->fresh()->balance)->toBe(0.0)
        ->and((float) $bill->fresh()->paid_amount)->toBe(114000.0);
});

it('splits a PARTIAL payment into its consideration and VAT parts', function () {
    // Half of a 114,000 bill is 57,000, of which 50,000 is consideration and 7,000 is VAT.
    // 3% of 50,000 = 1,500. The naive base would have withheld 1,710.
    $bill = whtVatBill(100000, 14);

    app(VendorBillService::class)->recordPayment($bill, 57000);

    expect(whtWithheldOn($bill->fresh()))->toBe(1500.0);
});

it('reaches the same total across two half payments as one whole one', function () {
    // Proportional splitting must not leak or duplicate tax across instalments.
    $bill = whtVatBill(100000, 14);

    app(VendorBillService::class)->recordPayment($bill, 57000);
    app(VendorBillService::class)->recordPayment($bill->fresh(), 57000);

    expect(whtWithheldOn($bill->fresh()))->toBe(3000.0);
});

it('is unchanged for a bill carrying no VAT', function () {
    // The paired control, and the reason this change is invisible to every exempt supply: with no
    // VAT the base IS the payment. Without this case, "excluding VAT" could be implemented as any
    // arbitrary reduction and the headline test would still pass.
    $bill = whtVatBill(100000, 0);

    app(VendorBillService::class)->recordPayment($bill, 100000);

    expect(whtWithheldOn($bill->fresh()))->toBe(3000.0);
});

it('shows the operator the same figure the bank will pay', function () {
    // The preview on EditVendorBill and the service must agree. When a displayed figure and a
    // recorded one drift apart nobody notices, because the screen is the only thing anyone checks
    // — the same shape as the annual percentage-rent deduction bug.
    $bill = whtVatBill(100000, 14);

    $previewed = WithholdingTax::onBillPayment(114000, $bill);
    app(VendorBillService::class)->recordPayment($bill, 114000);

    expect($previewed)->toBe(whtWithheldOn($bill->fresh()));
});

it('never scales the base above the cash actually moving', function () {
    // The `$net >= $gross` branch, on the case that actually occurs: a zero-VAT bill, where the
    // whole payment is consideration. The base must equal the payment exactly — scaling it UP
    // would withhold on money that never moved.
    //
    // (An earlier version of this case forced `vat_amount` negative to probe the same branch. That
    // state is not reachable — the finalized-bill guard refuses the edit, and no form or service
    // writes it — so the test was asserting over dead input rather than behaviour.)
    $bill = whtVatBill(100000, 0);

    expect(WithholdingTax::vatExclusiveShareOf(1000, $bill))->toBe(1000.0)
        ->and(WithholdingTax::vatExclusiveShareOf(100000, $bill))->toBe(100000.0);
});
