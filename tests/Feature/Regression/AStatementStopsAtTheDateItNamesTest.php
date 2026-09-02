<?php

use App\Models\CreditNote;
use App\Models\Payment;
use App\Services\TenantStatementPdfService;
use Carbon\CarbonImmutable;
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
    expect($data['recentInvoices']->pluck('id'))->toContain($inside->id)
        ->and($data['recentInvoices']->pluck('id'))->not->toContain($after->id)
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

    expect(statementBetween('2026-01-01', '2026-03-31')['payments']->pluck('id'))
        ->toContain($edge->id);
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

    expect($data['payments']->pluck('id'))->toContain($inWindow->id)
        ->and($data['payments']->pluck('id'))->not->toContain($afterWindow->id)
        ->and($data['credits'])->toBeEmpty();
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

    expect($data['recentInvoices'])->toHaveCount(1)
        ->and($data['summary']['outstanding'])->toEqual(9000.0);
});
