<?php

/*
|--------------------------------------------------------------------------
| A zero-rated supply is not an exempt one, and the return has to say so
|--------------------------------------------------------------------------
| `VatReturnService` split the taxable base on `vat_rate > 0`. Both a zero-rated supply (taxable, at
| 0%) and an exempt one (outside the scope of VAT entirely) bill nothing, so both landed in the
| exempt bucket — and they are DIFFERENT LINES on a filed return.
|
| The distinction has always existed on the tax code; what was missing was carrying it onto the
| document. `invoice_items.tax_code` (2026-08-12) closed that, and this is the report finally able
| to read it.
|
| The honest half matters as much: a line raised BEFORE the classification existed carries no code,
| and a document that recorded only a zero cannot be asked afterwards which kind of zero it was. Those
| fall back to the old heuristic and are COUNTED, so the operator signing the return can tell "no
| zero-rated supplies this period" apart from "we cannot tell".
*/

use App\Models\ChargeCode;
use App\Models\CreditNote;
use App\Models\CreditNoteItem;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Services\Reports\VatReturnService;
use Carbon\CarbonImmutable;
use Database\Seeders\AccountMappingSeeder;
use Database\Seeders\ChargeCodeSeeder;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\TaxCodeSeeder;

beforeEach(function () {
    $this->seed(ChartOfAccountsSeeder::class);
    $this->seed(AccountMappingSeeder::class);
    $this->seed(TaxCodeSeeder::class);
    $this->seed(ChargeCodeSeeder::class);

    // An export is the textbook zero-rated supply; a mall's nearest equivalent is a service the
    // accountant has ruled zero-rated. Either way it is a ruling on the charge code, which is the
    // whole point: no deploy.
    ChargeCode::where('code', 'utility')->update(['tax_code' => 'VAT_0']);
    ChargeCode::flushLookupCaches();
});

/** An issued invoice carrying one line of each treatment. */
function returnInvoice(): Invoice
{
    $lease = makeLease(makeUnit(makeAsset()), makeTenant(), [
        'status' => 'active',
        'commencement_date' => '2026-01-01',
        'expiry_date' => '2026-12-31',
    ]);

    $invoice = Invoice::create([
        'lease_id' => $lease->id,
        'tenant_id' => $lease->tenant_id,
        'status' => 'issued',
        'issue_date' => '2026-03-10',
        'due_date' => '2026-03-17',
        'period_start' => '2026-03-01',
        'period_end' => '2026-03-31',
        'subtotal' => 0, 'vat_amount' => 0, 'total' => 0,
        'paid_amount' => 0, 'balance' => 0, 'currency' => 'EGP',
    ]);

    foreach ([
        ['type' => 'service_charge', 'amount' => 1000, 'vat_rate' => 14],   // standard
        ['type' => 'utility', 'amount' => 500, 'vat_rate' => 0],            // zero-rated
        ['type' => 'base_rent', 'amount' => 8000, 'vat_rate' => 0],         // exempt
    ] as $line) {
        InvoiceItem::create($line + ['invoice_id' => $invoice->id, 'description' => $line['type']]);
    }

    return $invoice->fresh('items');
}

function returnFor(): array
{
    return app(VatReturnService::class)->for(
        CarbonImmutable::parse('2026-03-01'),
        CarbonImmutable::parse('2026-03-31'),
    );
}

it('reports zero-rated separately from exempt', function () {
    returnInvoice();

    $r = returnFor();

    expect($r['base_standard'])->toBe(1000.0)
        ->and($r['base_zero_rated'])->toBe(500.0)
        ->and($r['base_exempt'])->toBe(8000.0)
        // Everything was classified, so the operator can trust the split.
        ->and($r['unclassified_lines'])->toBe(0);
});

it('collapses the two and says so when a line carries no tax code', function () {
    // The pre-2026-08-12 state, reproduced: strip the codes the model stamped on and re-run. Without
    // the count, "zero-rated: 0" would read as a fact about the period rather than a limit of the
    // data — and it is the number an operator signs.
    $invoice = returnInvoice();
    InvoiceItem::where('invoice_id', $invoice->id)->update(['tax_code' => null]);

    $r = returnFor();

    expect($r['base_standard'])->toBe(1000.0)
        ->and($r['base_zero_rated'])->toBe(0.0)
        // The zero-rated 500 has fallen in with the exempt 8000 — which is all an un-coded document
        // can support.
        ->and($r['base_exempt'])->toBe(8500.0)
        ->and($r['unclassified_lines'])->toBe(3);
});

it('reduces the bucket the credited supply belonged to', function () {
    $invoice = returnInvoice();

    $note = CreditNote::create([
        'tenant_id' => $invoice->tenant_id,
        'invoice_id' => $invoice->id,
        'lease_id' => $invoice->lease_id,
        'status' => 'issued',
        'reason' => 'adjustment',
        'issue_date' => '2026-03-20',
        'subtotal' => 0, 'vat_amount' => 0, 'total' => 0,
        'applied_amount' => 0, 'balance' => 0, 'currency' => 'EGP',
    ]);

    CreditNoteItem::create([
        'credit_note_id' => $note->id,
        'description' => 'Utility over-billed',
        'tax_code' => 'VAT_0',
        'amount' => 200,
        'vat_rate' => 0,
        'vat_amount' => 0,
        'total' => 200,
    ]);

    $r = returnFor();

    expect($r['base_zero_rated'])->toBe(300.0, 'the credit must reduce the ZERO-RATED supply')
        // …and neither of the others moved. Before the tax code reached the line, that 200 would
        // have come off the exempt bucket, understating exempt supplies and overstating zero-rated.
        ->and($r['base_exempt'])->toBe(8000.0)
        ->and($r['base_standard'])->toBe(1000.0);
});

it('leaves a non-VAT supply out of the VAT return entirely', function () {
    // Stamp duty and schedule tax are separate Egyptian taxes with their own accounts and returns.
    // Nothing can carry them yet — those codes ship inactive pending their GL wiring — so this is
    // the guard that stops commissioning them silently corrupting this report.
    $invoice = returnInvoice();

    InvoiceItem::create([
        'invoice_id' => $invoice->id,
        'description' => 'Signage — stamp duty',
        'type' => 'other',
        'tax_code' => 'STAMP_20',
        'amount' => 1000,
        'vat_rate' => 20,
    ]);

    $r = returnFor();

    expect($r['base_standard'])->toBe(1000.0, 'stamp duty is not a VAT-standard supply')
        ->and($r['base_zero_rated'])->toBe(500.0)
        ->and($r['base_exempt'])->toBe(8000.0)
        // …and its tax stayed out of the output-VAT tie-out, which reads the VAT family only.
        ->and($r['output_vat_documents'])->toBe(140.0);
});
