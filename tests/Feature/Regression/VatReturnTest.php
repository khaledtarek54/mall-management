<?php

use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Vendor;
use App\Models\VendorBill;
use App\Services\Accounting\FiscalCalendar;
use App\Services\Reports\VatReturnService;
use App\Support\Vat;
use Carbon\CarbonImmutable;
use Database\Seeders\AccountMappingSeeder;
use Database\Seeders\ChartOfAccountsSeeder;

/**
 * The VAT return — what the operator owes the tax authority for a period, and the proof it ties.
 *
 * Output and input VAT are read from the LEDGER, because that is the single source of truth and
 * what the statements are built on. The documents are used for one thing: to check it. When Σ of
 * the invoices' VAT disagrees with the ledger's movement, something has gone unposted or been
 * posted twice — and a return is the last chance to catch that before it is filed and becomes a
 * position the operator has taken.
 *
 * The taxable base can only come from the documents, and it matters here because **base rent is
 * exempt while service charge is not** — one invoice normally carries both.
 */
function vatInvoice(array $items, string $issueDate = '2026-03-10'): Invoice
{
    $lease = makeLease(makeUnit(makeAsset()));

    $invoice = Invoice::create([
        'lease_id' => $lease->id,
        'tenant_id' => $lease->tenant_id,
        'status' => 'issued',
        'issue_date' => $issueDate,
        'due_date' => $issueDate,
        'period_start' => $issueDate,
        'period_end' => $issueDate,
        'subtotal' => 0, 'vat_amount' => 0, 'total' => 0, 'balance' => 0,
    ]);

    foreach ($items as $item) {
        $amount = (float) $item['amount'];
        $rate = (float) ($item['vat_rate'] ?? 0);

        InvoiceItem::create([
            'invoice_id' => $invoice->id,
            'type' => $item['type'],
            'description' => $item['type'],
            'quantity' => 1,
            'unit_price' => $amount,
            'amount' => $amount,
            'vat_rate' => $rate,
            'vat_amount' => round($amount * $rate / 100, 2),
            'total' => round($amount + $amount * $rate / 100, 2),
        ]);
    }

    $invoice->recomputeTotals();

    return $invoice->refresh();
}

beforeEach(function () {
    $this->seed(ChartOfAccountsSeeder::class);
    $this->seed(AccountMappingSeeder::class);
    app(FiscalCalendar::class)->ensureYear(2026);
});

it('reports output VAT from the ledger and proves it against the invoices', function () {
    // Base rent is EXEMPT, service charge is standard-rated — one invoice, both treatments, which
    // is the normal shape here rather than an edge case.
    vatInvoice([
        ['type' => 'base_rent', 'amount' => 10000, 'vat_rate' => Vat::EXEMPT],
        ['type' => 'service_charge', 'amount' => 2000, 'vat_rate' => Vat::standardRate()],
    ]);

    $this->artisan('accounting:sync-ledger', ['--all' => true])->assertSuccessful();

    $return = app(VatReturnService::class)->for(
        CarbonImmutable::parse('2026-03-01'),
        CarbonImmutable::parse('2026-03-31'),
    );

    $expectedVat = round(2000 * Vat::standardRate() / 100, 2);

    expect($return['output_vat'])->toBe($expectedVat)
        ->and($return['output_vat_documents'])->toBe($expectedVat)
        ->and($return['ties_out'])->toBeTrue()
        // The base split is what only the documents can answer.
        ->and($return['base_standard'])->toBe(2000.0)
        ->and($return['base_exempt'])->toBe(10000.0);
});

it('catches a return that does not tie to the books', function () {
    // The whole reason the documents are consulted at all. An invoice that never reached the ledger
    // makes the return understate what is owed — filed, that is a position the operator has taken.
    vatInvoice([['type' => 'service_charge', 'amount' => 2000, 'vat_rate' => Vat::standardRate()]]);

    // Deliberately NOT synced: the invoice exists, the posting does not.
    $return = app(VatReturnService::class)->for(
        CarbonImmutable::parse('2026-03-01'),
        CarbonImmutable::parse('2026-03-31'),
    );

    expect($return['output_vat'])->toBe(0.0)
        ->and($return['output_vat_documents'])->toBeGreaterThan(0.0)
        ->and($return['ties_out'])->toBeFalse();
});

it('nets input VAT off what is owed', function () {
    vatInvoice([['type' => 'service_charge', 'amount' => 10000, 'vat_rate' => Vat::standardRate()]]);

    $asset = makeAsset();
    $vendor = Vendor::create(['name' => 'Supplier '.uniqid(), 'status' => Vendor::STATUS_ACTIVE]);

    // A vendor bill carries recoverable input VAT.
    $bill = VendorBill::create([
        'vendor_id' => $vendor->id,
        'asset_id' => $asset->id,
        'category' => 'cleaning_security',
        'status' => 'approved',
        'bill_date' => '2026-03-15',
        'subtotal' => 1000,
        'vat_amount' => 140,
        'total' => 1140,
        'balance' => 1140,
    ]);
    $bill->recompute();

    $this->artisan('accounting:sync-ledger', ['--all' => true])->assertSuccessful();

    $return = app(VatReturnService::class)->for(
        CarbonImmutable::parse('2026-03-01'),
        CarbonImmutable::parse('2026-03-31'),
    );

    $outputVat = round(10000 * Vat::standardRate() / 100, 2);

    expect($return['input_vat'])->toBe(140.0)
        ->and($return['output_vat'])->toBe($outputVat)
        ->and($return['net_payable'])->toBe(round($outputVat - 140, 2));
});

it('reports a credit position rather than treating it as an error', function () {
    // A month of heavy purchasing leaves the operator in credit with the authority. That is a real
    // state, and a report that clamped it at zero would hide money the operator is owed.
    $asset = makeAsset();
    $vendor = Vendor::create(['name' => 'Supplier '.uniqid(), 'status' => Vendor::STATUS_ACTIVE]);

    $bill = VendorBill::create([
        'vendor_id' => $vendor->id, 'asset_id' => $asset->id,
        'category' => 'maintenance', 'status' => 'approved',
        'bill_date' => '2026-03-15',
        'subtotal' => 5000, 'vat_amount' => 700, 'total' => 5700, 'balance' => 5700,
    ]);
    $bill->recompute();

    $this->artisan('accounting:sync-ledger', ['--all' => true])->assertSuccessful();

    $return = app(VatReturnService::class)->for(
        CarbonImmutable::parse('2026-03-01'),
        CarbonImmutable::parse('2026-03-31'),
    );

    expect($return['net_payable'])->toBe(-700.0);
});

it('counts only the period asked for', function () {
    vatInvoice([['type' => 'service_charge', 'amount' => 1000, 'vat_rate' => Vat::standardRate()]], '2026-03-10');
    vatInvoice([['type' => 'service_charge', 'amount' => 9999, 'vat_rate' => Vat::standardRate()]], '2026-04-10');

    $this->artisan('accounting:sync-ledger', ['--all' => true])->assertSuccessful();

    $return = app(VatReturnService::class)->for(
        CarbonImmutable::parse('2026-03-01'),
        CarbonImmutable::parse('2026-03-31'),
    );

    expect($return['output_vat'])->toBe(round(1000 * Vat::standardRate() / 100, 2))
        ->and($return['base_standard'])->toBe(1000.0);
});
