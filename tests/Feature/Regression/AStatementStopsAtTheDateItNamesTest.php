<?php

use App\Models\CreditNote;
use App\Models\Payment;
use App\Services\TenantStatementPdfService;
use App\Services\WriteOffInvoiceService;
use Database\Seeders\AccountMappingSeeder;
use Database\Seeders\ChartOfAccountsSeeder;

/**
 * A STATEMENT PRINTED THE WINDOW IT WAS ASKED FOR AND IGNORED IT.
 *
 * `$asOf` set the date in the header and bounded nothing. `$invoicesAll` had no upper bound at all,
 * and both the recent-invoice list and every settlement query were `>= $since` with no `<=`. So
 * `GET /me/statement?to=2026-03-31` rendered *"as at 31 March"* over rows dated April, May and June
 * — on the document a tenant's accountant reconciles a quarter from.
 *
 * The bound is `endOfDay()`, or a transaction dated the last day of the window is cut off by the
 * window's own end date — an off-by-one that would look like the same bug pointing the other way.
 *
 * **What this deliberately does NOT claim:** the balances are as they stand today, not as they stood
 * on the end date. Reconstructing a historical balance means replaying four settlement channels to a
 * date, which is an aged-debt-as-at report and a different document. What the statement claims is
 * which TRANSACTIONS fall in the window, and that is what is asserted here.
 */
beforeEach(function () {
    $this->seed(ChartOfAccountsSeeder::class);
    $this->seed(AccountMappingSeeder::class);

    $this->asset = makeAsset();
    $this->tenant = makeTenant(['name' => 'Café Crema']);
    $this->lease = makeLease(makeUnit($this->asset), $this->tenant);
});

function statementBetween(string $from, string $to): array
{
    return app(TenantStatementPdfService::class)
        ->data(test()->tenant->fresh(), null, $from, $to);
}

it('lists no invoice issued after the end date it prints', function () {
    $inside = makeInvoice($this->lease, [
        'status' => 'issued', 'issue_date' => '2026-03-15', 'due_date' => '2026-03-25',
        'subtotal' => 10000, 'vat_amount' => 0, 'total' => 10000, 'balance' => 10000,
    ]);

    $after = makeInvoice($this->lease, [
        'status' => 'issued', 'issue_date' => '2026-04-10', 'due_date' => '2026-04-20',
        'subtotal' => 40000, 'vat_amount' => 0, 'total' => 40000, 'balance' => 40000,
    ]);

    $data = statementBetween('2026-01-01', '2026-03-31');

    // The control and the refusal together: a window that excluded everything would satisfy the
    // second assertion on its own and silently produce an empty statement.
    // Since 2026-09-11 the statement is the ledger, so "listed" means "has a ledger row".
    $references = $data['ledger']['rows']->pluck('reference');
    expect($references)->toContain($inside->number)
        ->and($references)->not->toContain($after->number)
        ->and($data['openInvoices']->pluck('id'))->not->toContain($after->id)
        ->and($data['summary']['total_billed'])->toEqual(10000.0)
        ->and($data['summary']['outstanding'])->toEqual(10000.0);
});

it('keeps a transaction dated the LAST day of the window', function () {
    // The off-by-one that would look like the same bug pointing the other way. Asserted on a
    // PAYMENT, deliberately: `payments.payment_date` is a datetime, so a bound of 31 March 00:00
    // silently drops everything received that day — where `invoices.issue_date` is a plain date and
    // would survive either bound, which is why an invoice-only edge case proves nothing here.
    $invoice = makeInvoice($this->lease, [
        'status' => 'issued', 'issue_date' => '2026-03-01', 'due_date' => '2026-03-10',
        'subtotal' => 7000, 'vat_amount' => 0, 'total' => 7000, 'balance' => 7000,
    ]);

    $edge = Payment::create([
        'tenant_id' => $this->tenant->id, 'payment_date' => '2026-03-31 14:30:00', 'amount' => 7000,
        'method' => 'bank_transfer', 'status' => 'captured', 'currency' => 'EGP',
    ]);
    $edge->invoices()->attach($invoice->id, ['allocated_amount' => 7000]);

    $rows = statementBetween('2026-01-01', '2026-03-31')['ledger']['rows'];

    expect($rows->where('type', 'payment')->pluck('credit')->all())->toBe([7000.0])
        // …and the ledger lands where it should: the invoice is settled by the day's end.
        ->and(statementBetween('2026-01-01', '2026-03-31')['ledger']['closing'])->toBe(0.0);
});

it('lists no payment or credit note after the end date either', function () {
    $invoice = makeInvoice($this->lease, [
        'status' => 'issued', 'issue_date' => '2026-03-01', 'due_date' => '2026-03-10',
        'subtotal' => 20000, 'vat_amount' => 0, 'total' => 20000, 'balance' => 20000,
    ]);

    $inWindow = Payment::create([
        'tenant_id' => $this->tenant->id, 'payment_date' => '2026-03-20', 'amount' => 5000,
        'method' => 'bank_transfer', 'status' => 'captured', 'currency' => 'EGP',
    ]);
    $inWindow->invoices()->attach($invoice->id, ['allocated_amount' => 5000]);

    $afterWindow = Payment::create([
        'tenant_id' => $this->tenant->id, 'payment_date' => '2026-05-02', 'amount' => 6000,
        'method' => 'bank_transfer', 'status' => 'captured', 'currency' => 'EGP',
    ]);
    $afterWindow->invoices()->attach($invoice->id, ['allocated_amount' => 6000]);

    CreditNote::create([
        'number' => 'CN-'.uniqid(), 'tenant_id' => $this->tenant->id, 'asset_id' => $this->asset->id,
        'status' => 'issued', 'issue_date' => '2026-05-05', 'reason' => 'adjustment',
        'subtotal' => 1000, 'vat_amount' => 0, 'total' => 1000,
        'applied_amount' => 0, 'balance' => 1000, 'currency' => 'EGP',
    ]);

    $data = statementBetween('2026-01-01', '2026-03-31');

    $payments = $data['ledger']['rows']->where('type', 'payment')->pluck('credit')->values()->all();

    expect($payments)->toBe([5000.0])
        ->and($data['ledger']['rows']->where('type', 'credit_note'))->toBeEmpty()
        // The balance carried on the page is struck at the window's end: 20,000 billed, 5,000
        // received by 31 March — the 6,000 of May is a later page.
        ->and($data['ledger']['closing'])->toBe(15000.0);
});

it('still renders the whole history when no window is asked for', function () {
    // The default path — no `from`, no `to` — must be unchanged, or every statement anyone
    // downloads from the panel silently narrows.
    makeInvoice($this->lease, [
        'status' => 'issued', 'issue_date' => now()->subDays(3)->toDateString(),
        'due_date' => now()->addDays(7)->toDateString(),
        'subtotal' => 9000, 'vat_amount' => 0, 'total' => 9000, 'balance' => 9000,
    ]);

    $data = app(TenantStatementPdfService::class)->data($this->tenant->fresh());

    expect($data['ledger']['rows']->where('type', 'invoice'))->toHaveCount(1)
        ->and($data['summary']['outstanding'])->toEqual(9000.0);
});

it('does not narrow the DEFAULT statement — an invoice may be issued in advance', function () {
    // **The first version of this fix bounded the default path too, and that was a real regression.**
    // A future `issue_date` is a first-class state, not an exotic one: both billing runs carry
    // explicit code for it (*"never let an invoice be born overdue"*), and
    // `billing:run-monthly --period=2026-10` produces a month of them. Bounding the default at today
    // dropped them from the statement while the portal's invoice LIST — which has no such bound —
    // still showed them, and `Tenant::outstandingBalance()` still counted them: measured, the
    // statement said 50,000 where the screen the tenant downloaded it from said 100,000. That is the
    // divergence this service's own docblock says the figure exists to prevent.
    makeInvoice($this->lease, [
        'status' => 'issued', 'issue_date' => now()->subMonth()->toDateString(),
        'due_date' => now()->subDays(20)->toDateString(),
        'subtotal' => 50000, 'vat_amount' => 0, 'total' => 50000, 'balance' => 50000,
    ]);

    makeInvoice($this->lease, [
        'status' => 'issued', 'issue_date' => now()->addMonth()->toDateString(),
        'due_date' => now()->addMonth()->addDays(10)->toDateString(),
        'subtotal' => 50000, 'vat_amount' => 0, 'total' => 50000, 'balance' => 50000,
    ]);

    $data = app(TenantStatementPdfService::class)->data($this->tenant->fresh());

    expect($data['summary']['outstanding'])->toEqual(100000.0)
        // …and it agrees with the headline the tenant read on the screen they downloaded it from.
        ->and($data['summary']['outstanding'])
        ->toEqual(round($this->tenant->fresh()->outstandingBalance(), 2));
});

it('prints the collectable figure on the tenant s own document', function () {
    // The blade, for the reason the owner statement's is pinned: selecting on `collectableBalance()`
    // and printing `balance` asks the tenant for money the operator forgave.
    $invoice = makeInvoice($this->lease, [
        'status' => 'issued', 'issue_date' => now()->subMonth()->toDateString(),
        'due_date' => now()->subDays(20)->toDateString(),
        'subtotal' => 20000, 'vat_amount' => 0, 'total' => 20000, 'balance' => 20000,
    ]);

    app(WriteOffInvoiceService::class)->write($invoice->fresh(), [
        'amount' => 5000, 'reason' => 'tenant_insolvent', 'entry_date' => now()->toDateString(),
    ]);

    $html = view('tenants.statement', app(TenantStatementPdfService::class)->data($this->tenant->fresh()))->render();

    // The open-invoice TABLE FOOTER specifically, not just "somewhere on the page": the summary
    // tiles print the same figure, so a page-wide count stays satisfied when only the footer
    // regresses — measured, reverting the footer to `sum('balance')` left a page-wide assertion
    // fully green. Since 2026-09-11 that table closes the document, after the ledger and the
    // deposit account, so it is the LAST tfoot; the ledger's own footer legitimately carries the
    // 20,000 as the period's total debit beside the 5,000 write-off credit.
    preg_match_all('#<tfoot>.*?</tfoot>#s', $html, $footers);
    $footer = end($footers[0]);
    preg_match_all('#<tbody>.*?</tbody>#s', $html, $bodies);
    $body = end($bodies[0]);

    expect($footer)->not->toBeEmpty('the statement lost its outstanding total')
        ->and($footer)->toContain('15,000.00')
        ->and($footer)->not->toContain('20,000.00')
        ->and($body)->not->toBeEmpty()
        // 20,000 is legitimately in the row as the TOTAL billed; 15,000 must be there as the
        // balance, and a row printing `balance` would show 20,000 twice and 15,000 not at all.
        ->and($body)->toContain('15,000.00');

    // …and the LEDGER agrees: 20,000 charged, 5,000 forgiven as its own row, closing at 15,000 —
    // the same figure the headline prints. Before the write-off row existed the ledger closed at
    // 20,000 beside a headline of 15,000, on one page.
    $data = app(TenantStatementPdfService::class)->data($this->tenant->fresh());
    expect($data['ledger']['rows']->where('type', 'write_off')->pluck('credit')->all())->toBe([5000.0])
        ->and($data['ledger']['closing'])->toBe(15000.0)
        ->and($data['summary']['due_from_tenant'])->toBe($data['summary']['outstanding']);
});
