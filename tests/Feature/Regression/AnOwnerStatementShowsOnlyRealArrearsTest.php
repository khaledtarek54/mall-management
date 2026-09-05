<?php

use App\Models\Payment;
use App\Services\AssetStatementPdfService;
use App\Services\WriteOffInvoiceService;
use Database\Seeders\AccountMappingSeeder;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\RolesPermissionsSeeder;

/**
 * THE ONE DOCUMENT JAWAD READS BILLED HIM A DRAFT AND CHASED FORGIVEN DEBT.
 *
 * `AssetStatementPdfService` filtered `['cancelled', 'credited']` and omitted **both** of the
 * statuses that matter here, failing in opposite directions:
 *
 *  - **`draft`.** `invoices.status` DEFAULTS to `draft` at the column, so an invoice nobody has
 *    issued — a working figure, an abandoned one — was reported to the owner as billed revenue and
 *    listed on their arrears. `TenantVisibility` makes sure the TENANT never sees a draft; the owner
 *    was shown one.
 *  - **`written_off`.** A write-off deliberately leaves `balance` standing, because balance is
 *    derived from the four settlement channels and a write-off is none of them. So the
 *    `where('balance', '>', 0)` two lines further down put already-relieved bad debt on the owner's
 *    outstanding list — money the operator has formally given up and the ledger has already
 *    expensed as such.
 *
 * And a PARTIAL write-off is neither status, which is why `Invoice::collectableBalance()` exists:
 * `balance` says what was OWED, `status` says whether the document left the books, and a partial
 * write-off is a third thing. Selecting correctly and then quoting the raw balance would be worse
 * than fixing neither, so the page prints the collectable figure too.
 *
 * Every sibling AR read already excluded these — `TenantLedger`, `TenantStatementPdfService`,
 * `DepositBilling`, `InvoiceSettlement`. This was the one that did not.
 */
beforeEach(function () {
    $this->seed(ChartOfAccountsSeeder::class);
    $this->seed(AccountMappingSeeder::class);
    $this->seed(RolesPermissionsSeeder::class);
    $this->actingAs(makeUser('super_admin'));

    $this->asset = makeAsset(['code' => 'HW', 'name' => 'Haya Walk']);
    $this->unit = makeUnit($this->asset, ['status' => 'occupied']);
    $this->tenant = makeTenant(['name' => 'Café Crema']);
    $this->lease = makeLease($this->unit, $this->tenant);
});

/** The figures the statement actually prints. */
function statementSummary(): array
{
    return app(AssetStatementPdfService::class)->data(test()->asset)['summary'];
}

it('does not report a DRAFT invoice as billed or outstanding', function () {
    // The control: a real, issued invoice IS on the statement.
    makeInvoice($this->lease, [
        'status' => 'issued', 'subtotal' => 10000, 'vat_amount' => 0,
        'total' => 10000, 'balance' => 10000, 'due_date' => now()->subDays(5),
    ]);

    makeInvoice($this->lease, [
        // The column default, and what any create that omits a status produces.
        'status' => 'draft', 'subtotal' => 40000, 'vat_amount' => 0,
        'total' => 40000, 'balance' => 40000, 'due_date' => now()->subDays(5),
    ]);

    $summary = statementSummary();

    expect($summary['outstanding'])->toEqual(10000.0)
        ->and($summary['total_billed'])->toEqual(10000.0)
        ->and($summary['open_count'])->toBe(1);
});

it('does not chase a debt the operator has written off', function () {
    $invoice = makeInvoice($this->lease, [
        'status' => 'issued', 'subtotal' => 10000, 'vat_amount' => 0,
        'total' => 10000, 'balance' => 10000, 'due_date' => now()->subDays(30),
    ]);

    // The control: before the write-off, the owner is owed 10,000.
    expect(statementSummary()['outstanding'])->toEqual(10000.0);

    app(WriteOffInvoiceService::class)->write($invoice->fresh(), [
        'amount' => 10000, 'reason' => 'tenant_insolvent', 'entry_date' => now()->toDateString(),
    ]);

    $summary = statementSummary();

    expect($summary['outstanding'])->toEqual(0.0)
        ->and($summary['overdue'])->toEqual(0.0)
        ->and($summary['open_count'])->toBe(0)
        // **AND THE BILLED/PAID TILES**, which `collectableBalance()` does not reach: they sum the
        // raw columns, so a retired invoice left the owner reading *Billed 10,000 · Paid 0 ·
        // Outstanding 0* with nothing on the page explaining where the ten thousand went. That is
        // why `written_off` is excluded outright, exactly as the tenant statement does it.
        ->and($summary['total_billed'])->toEqual(0.0)
        ->and($summary['total_paid'])->toEqual(0.0);
});

it('reports only the UNFORGIVEN part of a partially written-off debt', function () {
    // The case no status can express: still on the books, still live, and smaller.
    $invoice = makeInvoice($this->lease, [
        'status' => 'issued', 'subtotal' => 10000, 'vat_amount' => 0,
        'total' => 10000, 'balance' => 10000, 'due_date' => now()->subDays(30),
    ]);

    app(WriteOffInvoiceService::class)->write($invoice->fresh(), [
        'amount' => 4000, 'reason' => 'tenant_insolvent', 'entry_date' => now()->toDateString(),
    ]);

    expect($invoice->fresh()->status)->not->toBe('written_off')   // still live
        ->and((float) $invoice->fresh()->balance)->toEqual(10000.0);  // …and balance still standing

    $data = app(AssetStatementPdfService::class)->data($this->asset);

    expect($data['summary']['outstanding'])->toEqual(6000.0)
        ->and($data['summary']['overdue'])->toEqual(6000.0)
        ->and($data['summary']['open_count'])->toBe(1)
        // The per-tenant list is what an owner reads first, so it must not disagree with the total.
        ->and((float) $data['delinquentTenants']->first()['balance'])->toEqual(6000.0);
});

it('drops a tenant off the arrears list once their debt is fully relieved', function () {
    $partly = makeInvoice($this->lease, [
        'status' => 'issued', 'subtotal' => 10000, 'vat_amount' => 0,
        'total' => 10000, 'balance' => 10000, 'due_date' => now()->subDays(30),
    ]);

    // Half paid, half forgiven — nothing left to chase, and neither channel moves the other.
    $payment = Payment::create([
        'tenant_id' => $partly->tenant_id, 'payment_date' => now(), 'amount' => 5000,
        'method' => 'bank_transfer', 'status' => 'captured', 'currency' => 'EGP',
    ]);
    $payment->invoices()->attach($partly->id, ['allocated_amount' => 5000]);
    $partly->fresh()->recomputeTotals();

    app(WriteOffInvoiceService::class)->write($partly->fresh(), [
        'amount' => 5000, 'reason' => 'tenant_insolvent', 'entry_date' => now()->toDateString(),
    ]);

    $data = app(AssetStatementPdfService::class)->data($this->asset);

    expect($data['summary']['outstanding'])->toEqual(0.0)
        ->and($data['delinquentTenants'])->toBeEmpty();
});

it('prints the collectable figure on the page, not the raw balance', function () {
    // **The blade was uncovered, which is exactly how its sibling stayed wrong.** Selecting on
    // `collectableBalance()` and then printing `balance` is worse than fixing neither, and only a
    // render can say which one reached the paper.
    $invoice = makeInvoice($this->lease, [
        'status' => 'issued', 'subtotal' => 20000, 'vat_amount' => 0,
        'total' => 20000, 'balance' => 20000, 'due_date' => now()->subDays(30),
    ]);

    app(WriteOffInvoiceService::class)->write($invoice->fresh(), [
        'amount' => 5000, 'reason' => 'tenant_insolvent', 'entry_date' => now()->toDateString(),
    ]);

    $html = view('assets.statement', app(AssetStatementPdfService::class)->data($this->asset))->render();

    // 15,000 is collectable; 20,000 is what `balance` still says. `EGP 20,000.00` is LEGITIMATELY
    // on the page — that is what was billed, and the summary says so — so "must appear nowhere" is
    // the wrong assertion and would fail on correct output. What must be true is that the collectable
    // figure reaches BOTH the outstanding summary and the table's own total, which one occurrence
    // could not tell apart from the billed figure landing in one of them.
    // The TABLE FOOTER specifically, not just "somewhere on the page": the summary tiles print the
    // same figure, so a page-wide count stays satisfied when only the footer regresses — measured,
    // reverting the footer to `sum('balance')` left a page-wide assertion fully green.
    preg_match('#<tfoot>.*?</tfoot>#s', $html, $footer);

    expect($footer)->not->toBeEmpty('the statement lost its outstanding total')
        ->and($footer[0])->toContain('15,000.00')
        ->and($footer[0])->not->toContain('20,000.00');
});
