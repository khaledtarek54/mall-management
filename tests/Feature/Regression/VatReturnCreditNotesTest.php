<?php

use App\Filament\Admin\Pages\VatReturn;
use App\Models\CreditNote;
use App\Models\CreditNoteItem;
use App\Models\InvoiceItem;
use App\Services\Accounting\FiscalCalendar;
use App\Services\Reports\VatReturnService;
use Carbon\CarbonImmutable;
use Database\Seeders\AccountMappingSeeder;
use Database\Seeders\ChartOfAccountsSeeder;

/**
 * The VAT return must net credit notes — and an operator must be able to open it.
 *
 * Two defects in one service, both live.
 *
 * **The tie-out was false in every period containing a VAT-bearing credit note.** The ledger side
 * is net of them (`CreditNoteJournalizer` debits `vat_payable`); the documents side was built from
 * invoices ALONE. So `difference = −(credit-note VAT)` by construction, and `ties_out` reported a
 * discrepancy that did not exist. A control that cries wolf is a control the operator stops
 * reading — and this one is the last chance to catch a genuinely unposted invoice.
 *
 * **`base_standard` / `base_exempt` never netted them either**, and those are numbers that go on a
 * filed return. They are split by LINE, so a credit against exempt base rent must reduce the
 * exempt supply and one against a service charge the standard-rated supply.
 *
 * And none of it was reachable: the service had **zero callers** — no page, route, nav entry or
 * command — while its fifteen sibling report services all had a page. Egypt files VAT monthly.
 */
beforeEach(function () {
    $this->seed(ChartOfAccountsSeeder::class);
    $this->seed(AccountMappingSeeder::class);
    app(FiscalCalendar::class)->ensureYear(2026);

    $asset = makeAsset(['code' => 'MALL']);
    $lease = makeLease(makeUnit($asset), makeTenant());

    // A standard-rated service charge: 10,000 + 14% VAT.
    $this->invoice = makeInvoice($lease, [
        'status' => 'issued',
        'issue_date' => '2026-03-05',
        'subtotal' => 10000, 'vat_amount' => 1400, 'total' => 11400, 'balance' => 11400,
    ]);
    InvoiceItem::create([
        'invoice_id' => $this->invoice->id,
        'type' => 'service_charge',
        'description' => 'Service charge',
        'amount' => 10000, 'vat_rate' => 14, 'vat_amount' => 1400, 'total' => 11400,
    ]);

    $this->start = CarbonImmutable::create(2026, 3, 1);
    $this->end = CarbonImmutable::create(2026, 3, 31);
});

function issueCreditNote(float $amount, float $vatRate): CreditNote
{
    $vat = round($amount * $vatRate / 100, 2);

    $note = CreditNote::create([
        'invoice_id' => test()->invoice->id,
        'tenant_id' => test()->invoice->tenant_id,
        'status' => 'issued',
        'issue_date' => '2026-03-20',
        'reason' => 'billing_error',
        'subtotal' => $amount, 'vat_amount' => $vat, 'total' => round($amount + $vat, 2),
        'applied_amount' => 0, 'balance' => round($amount + $vat, 2),
    ]);

    CreditNoteItem::create([
        'credit_note_id' => $note->id,
        'description' => 'Correction',
        'amount' => $amount, 'vat_rate' => $vatRate, 'vat_amount' => $vat,
        'total' => round($amount + $vat, 2),
    ]);

    return $note->fresh();
}

it('ties out when a VAT-bearing credit note is issued in the period', function () {
    issueCreditNote(2000, 14);
    $this->artisan('accounting:sync-ledger', ['--all' => true])->assertSuccessful();

    $r = app(VatReturnService::class)->for($this->start, $this->end);

    // Before this, the documents side ignored the credit note and `difference` was −280 in every
    // period one was issued.
    expect($r['ties_out'])->toBeTrue()
        ->and($r['output_vat_difference'])->toBe(0.0);
});

it('nets the credit note out of the standard-rated base', function () {
    issueCreditNote(2000, 14);

    $r = app(VatReturnService::class)->for($this->start, $this->end);

    // 10,000 invoiced − 2,000 credited. This figure goes on a filed return.
    expect($r['base_standard'])->toBe(8000.0);
});

it('nets an exempt credit against the EXEMPT base, not the standard one', function () {
    // Base rent is exempt, so a credit against it must not reduce standard-rated supplies —
    // collapsing both into one bucket would misstate the split the return is filed on.
    issueCreditNote(3000, 0);

    $r = app(VatReturnService::class)->for($this->start, $this->end);

    expect($r['base_standard'])->toBe(10000.0)
        ->and($r['base_exempt'])->toBe(-3000.0);
});

it('still reports a real discrepancy — the control that keeps the tie-out worth reading', function () {
    // An invoice that never posted. If netting credit notes had been done by simply relaxing the
    // check, this would now pass silently, and the return would lose its only control.
    $r = app(VatReturnService::class)->for($this->start, $this->end);

    expect($r['ties_out'])->toBeFalse()
        ->and($r['output_vat_difference'])->toBe(-1400.0);
});

it('is reachable — the page exists and is registered in the admin panel', function () {
    // The service was complete, tested and had zero callers. Reachability is the other half of
    // the fix, and the smoke manifest now carries it.
    $manifest = json_decode(file_get_contents(base_path('tests/e2e/filament-admin-manifest.json')), true);
    $slugs = collect($manifest['pages'] ?? [])->pluck('slug');

    expect(class_exists(VatReturn::class))->toBeTrue()
        ->and($slugs)->toContain('vat-return');
});
