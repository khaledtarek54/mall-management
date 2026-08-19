<?php

use App\Models\Asset;
use App\Models\Invoice;
use App\Models\JournalEntry;
use App\Models\TaxCode;
use App\Models\Vendor;
use App\Models\VendorBill;
use App\Services\Accounting\AccountResolver;
use App\Services\Accounting\FiscalCalendar;
use Database\Seeders\AccountMappingSeeder;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\TaxCodeSeeder;

/**
 * A tax posts to the account ITS OWN code names — not to VAT because VAT was first.
 *
 * **Why this could not be switched on before.** The catalogue has held stamp tax (ضريبة الدمغة,
 * Law 111/1980) and schedule tax (ضريبة الجدول, the table attached to VAT Law 67/2016) since it
 * shipped, and both families were inactive. The stated reason was "their GL accounts are not
 * wired". The accounts were the smaller half. Both journalizers **threw the document's own
 * `tax_code` away**: the invoice one summed every line's `vat_amount` into a single accumulator and
 * credited it to `vat_payable`, and the vendor bill one hard-coded `vat_recoverable`. So an active
 * stamp code would have put 20% of a line onto the VAT return, under the VAT liability, with the
 * entry balancing perfectly and the tie-out green.
 *
 * `invoice_items.tax_code` recorded which tax the line carried the whole time. The posting simply
 * did not read it.
 *
 * ## The asymmetry that is the actual accounting question
 *
 * Input VAT is creditable against output VAT, so it is an **asset** (`vat_recoverable`). Neither
 * stamp duty nor schedule tax has a credit mechanism a mall operator can use, so the input side of
 * both is an **expense**. Copying the VAT shape would have grown a receivable nobody could ever
 * collect — an asset that is not one — quietly, on the balance sheet, for as long as the operator
 * kept buying. That is what the last two cases here pin.
 */
beforeEach(function () {
    seedRoles();
    $this->seed(ChartOfAccountsSeeder::class);
    $this->seed(AccountMappingSeeder::class);
    $this->seed(TaxCodeSeeder::class);
    app(FiscalCalendar::class)->ensureYear(2026);

    $this->asset = makeAsset();
    $this->unit = makeUnit($this->asset, ['code' => 'T-1']);
    $this->lease = makeLease($this->unit, null, [
        'status' => 'active',
        'commencement_date' => '2025-01-01',
        'expiry_date' => '2035-12-31',
    ]);
    $this->accounts = app(AccountResolver::class);

    /** The posted entry's credit/debit against one posting role, after the REAL sweep. */
    $this->legFor = function (string $sourceType, int $sourceId, string $role): array {
        $this->artisan('accounting:sync-ledger', ['--all' => true]);

        $entry = JournalEntry::where('source_type', $sourceType)
            ->where('source_id', $sourceId)
            ->where('status', 'posted')
            ->firstOrFail();

        $accountId = $this->accounts->id($role, $this->asset->id);

        $lines = $entry->lines->where('ledger_account_id', $accountId);

        return [
            'debit' => round((float) $lines->sum('debit'), 2),
            'credit' => round((float) $lines->sum('credit'), 2),
        ];
    };

    /** An issued invoice with one line carrying a named tax code. */
    $this->invoiceTaxed = function (?string $taxCode, float $net, float $tax): Invoice {
        $invoice = makeInvoice($this->lease, [
            'asset_id' => $this->asset->id,
            'subtotal' => $net, 'vat_amount' => $tax, 'total' => $net + $tax,
            'balance' => $net + $tax, 'paid_amount' => 0, 'status' => 'issued',
        ]);

        $invoice->items()->create([
            'type' => 'service_charge',
            'description' => 'Probe',
            'quantity' => 1,
            'unit_price' => $net,
            'amount' => $net,
            'vat_rate' => $net > 0 ? round($tax / $net * 100, 2) : 0,
            'vat_amount' => $tax,
            'tax_code' => $taxCode,
        ]);

        return $invoice;
    };
});

it('credits VAT to the VAT liability — the control', function () {
    // Without this every assertion below would pass just as happily against a journalizer that had
    // stopped posting tax at all.
    $invoice = ($this->invoiceTaxed)('VAT_14', 10_000, 1_400);

    expect(($this->legFor)('invoice', $invoice->id, 'vat_payable')['credit'])->toBe(1400.0)
        ->and(($this->legFor)('invoice', $invoice->id, 'stamp_tax_payable')['credit'])->toBe(0.0);
});

it('credits stamp tax to the stamp liability, not to VAT', function () {
    // ضريبة الدمغة at 20%. Before this change the 2,000 landed in `vat_payable` and on the VAT
    // return — balanced, tied out, and wrong on two filings at once.
    $invoice = ($this->invoiceTaxed)('STAMP_20', 10_000, 2_000);

    expect(($this->legFor)('invoice', $invoice->id, 'stamp_tax_payable')['credit'])->toBe(2000.0)
        ->and(($this->legFor)('invoice', $invoice->id, 'vat_payable')['credit'])->toBe(0.0);
});

it('splits an invoice that carries two different taxes, and still balances', function () {
    $invoice = makeInvoice($this->lease, [
        'asset_id' => $this->asset->id,
        'subtotal' => 20_000, 'vat_amount' => 3_400, 'total' => 23_400,
        'balance' => 23_400, 'paid_amount' => 0, 'status' => 'issued',
    ]);

    $invoice->items()->create([
        'type' => 'service_charge', 'description' => 'Service', 'quantity' => 1,
        'unit_price' => 10_000, 'amount' => 10_000,
        'vat_rate' => 14, 'vat_amount' => 1_400, 'tax_code' => 'VAT_14',
    ]);
    $invoice->items()->create([
        'type' => 'other', 'description' => 'Stamped supply', 'quantity' => 1,
        'unit_price' => 10_000, 'amount' => 10_000,
        'vat_rate' => 20, 'vat_amount' => 2_000, 'tax_code' => 'STAMP_20',
    ]);

    expect(($this->legFor)('invoice', $invoice->id, 'vat_payable')['credit'])->toBe(1400.0)
        ->and(($this->legFor)('invoice', $invoice->id, 'stamp_tax_payable')['credit'])->toBe(2000.0);

    $entry = JournalEntry::where('source_type', 'invoice')->where('source_id', $invoice->id)
        ->where('status', 'posted')->firstOrFail();

    // The whole point of grouping rather than branching: two tax legs must still sum to the AR debit.
    expect(round((float) $entry->lines->sum('debit'), 2))->toBe(23400.0)
        ->and(round((float) $entry->lines->sum('credit'), 2))->toBe(23400.0);
});

it('falls back to VAT for a line that names no tax code', function () {
    // The floor, and it must stay a floor rather than becoming a guess: a line with no code is
    // legacy or came from a service that does not classify, and VAT is what it was. Same shape as
    // the revenue side's `REVENUE_ROLE` fallback.
    $invoice = ($this->invoiceTaxed)(null, 10_000, 1_400);

    expect(($this->legFor)('invoice', $invoice->id, 'vat_payable')['credit'])->toBe(1400.0);
});

it('debits input VAT to a recoverable ASSET — the control for the asymmetry below', function () {
    $bill = vendorBillTaxed($this->asset, 'VAT_14_P', 10_000, 1_400);

    expect(($this->legFor)('vendor_bill', $bill->id, 'vat_recoverable')['debit'])->toBe(1400.0);
});

it('debits input stamp tax to an EXPENSE, because there is nothing to recover', function () {
    // The accounting question this whole change turned on. Stamp duty paid is a cost: there is no
    // credit to claim it back against. Booking it as `vat_recoverable` — the shape a careless copy
    // of the VAT leg produces — creates a receivable that can never be collected and never ages,
    // and it grows with every purchase.
    $bill = vendorBillTaxed($this->asset, 'STAMP_20_P', 10_000, 2_000);

    expect(($this->legFor)('vendor_bill', $bill->id, 'stamp_tax_expense')['debit'])->toBe(2000.0)
        ->and(($this->legFor)('vendor_bill', $bill->id, 'vat_recoverable')['debit'])->toBe(0.0);
});

it('ships every stamp and schedule code able to bill, in both directions', function () {
    // The commissioning itself. `TaxCode` refuses to activate a taxable code with no rate or no
    // posting role, so this asserts the state that guard now permits — and the both-directions
    // rule is what stops one side of the books classifying a supply the other cannot.
    $incomplete = TaxCode::query()
        ->whereIn('family', [TaxCode::FAMILY_STAMP, TaxCode::FAMILY_SCHEDULE])
        ->where(fn ($q) => $q->whereNull('posting_role')->orWhere('is_active', false))
        ->pluck('code')
        ->all();

    expect($incomplete)->toBe([], 'Still not commissioned: '.implode(', ', $incomplete));

    // And every role they name resolves to a real account — a role with no mapping is the same
    // "collects into nowhere" failure wearing a different hat.
    foreach (['stamp_tax_payable', 'schedule_tax_payable', 'stamp_tax_expense', 'schedule_tax_expense'] as $role) {
        expect($this->accounts->id($role, $this->asset->id))->toBeGreaterThan(0, "{$role} maps to no account");
    }
});

/** An approved vendor bill carrying a header tax code — the level at which a bill states its tax. */
function vendorBillTaxed(Asset $asset, string $taxCode, float $net, float $tax): VendorBill
{
    $vendor = Vendor::create([
        'name' => 'Tax probe vendor '.$taxCode,
        'category' => 'services',
        'status' => 'active',
    ]);

    return VendorBill::create([
        'vendor_id' => $vendor->id,
        'asset_id' => $asset->id,
        'category' => 'maintenance',
        'status' => 'approved',
        'bill_date' => '2026-02-05',
        'due_date' => '2026-03-05',
        'subtotal' => $net,
        'vat_amount' => $tax,
        'tax_code' => $taxCode,
        'total' => $net + $tax,
        'paid_amount' => 0,
        'balance' => $net + $tax,
        'currency' => 'EGP',
    ]);
}
