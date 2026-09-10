<?php

use App\Models\Invoice;
use App\Support\ProjectedState;

/**
 * Regression — SW-245. `invoices.status` is a PROJECTION of the calendar, and something sweeps it.
 *
 * `overdue` is derived by `Invoice::recomputeTotals()` from the four settlement channels and the
 * due date — and that method runs when a SETTLEMENT lands, so an issued invoice nobody pays or
 * penalises read `issued` for ever. Measured on the staging soak, 2026-09-10: six invoices two days
 * past due, money on all six, all still `issued`; the four that did say `overdue` said it only
 * because the late-fee run had touched them. No money read the column (SW-135 routed collections
 * through `stillOwed()` + the date), but the register's status filter and tabs and the tenant's
 * own portal view do, and a screen that under-reports is a failure nobody files.
 *
 * `billing:scan-overdue-invoices` — the sweep that exists to notice an invoice going past due —
 * now re-runs the projector on every row whose stored status disagrees with `pastDue()`, in both
 * directions, and the column is registered in `ProjectedState` so the gate keeps the sweep
 * scheduled and idempotent. The projector is `recomputeTotals()` itself: no second rule.
 */
beforeEach(function () {
    $this->lease = makeLease(makeUnit(makeAsset(['code' => 'ODU'])), null, ['status' => 'active']);
});

function sw245Invoice(string $status, string $dueDate): Invoice
{
    return makeInvoice(test()->lease, [
        'status' => $status,
        'issue_date' => now()->subDays(30)->toDateString(),
        'due_date' => $dueDate,
        'subtotal' => 10000,
        'total' => 10000,
        'paid_amount' => 0,
        'balance' => 10000,
    ]);
}

it('moves an untouched invoice to overdue once its due date has passed', function () {
    $invoice = sw245Invoice('issued', now()->subDay()->toDateString());

    $this->artisan('billing:scan-overdue-invoices')
        ->expectsOutputToContain('Re-projected 1 invoice status(es).')
        ->assertSuccessful();

    expect($invoice->fresh()->status)->toBe('overdue');
});

it('moves an overdue invoice back to issued when its due date was extended', function () {
    // The concession path: `due_date` stays editable on a live receivable precisely so an operator
    // can extend it, and nothing recomputed when they did.
    $invoice = sw245Invoice('overdue', now()->addWeek()->toDateString());

    $this->artisan('billing:scan-overdue-invoices')->assertSuccessful();

    expect($invoice->fresh()->status)->toBe('issued');
});

it('leaves alone what the calendar does not contradict — the controls', function () {
    $current = sw245Invoice('issued', now()->addDay()->toDateString());
    // The projector's own exclusion list: a status that is the outcome of an ACT is never
    // projected over, however late the document is.
    $disputed = sw245Invoice('disputed', now()->subDays(10)->toDateString());
    $draft = sw245Invoice('draft', now()->subDays(10)->toDateString());

    $this->artisan('billing:scan-overdue-invoices')
        ->expectsOutputToContain('No invoice status has gone stale.')
        ->assertSuccessful();

    expect($current->fresh()->status)->toBe('issued')
        ->and($disputed->fresh()->status)->toBe('disputed')
        ->and($draft->fresh()->status)->toBe('draft');
});

it('finds nothing on a second consecutive run', function () {
    sw245Invoice('issued', now()->subDay()->toDateString());

    $this->artisan('billing:scan-overdue-invoices')->assertSuccessful();
    $this->artisan('billing:scan-overdue-invoices')
        ->expectsOutputToContain('No invoice status has gone stale.')
        ->assertSuccessful();
});

it('writes nothing on a dry run', function () {
    $invoice = sw245Invoice('issued', now()->subDay()->toDateString());

    $this->artisan('billing:scan-overdue-invoices', ['--dry-run' => true])
        ->expectsOutputToContain('Would re-project 1 invoice status(es):')
        ->assertSuccessful();

    expect($invoice->fresh()->status)->toBe('issued');
});

it('is registered as a projection, swept by the scan that notices late invoices', function () {
    // The registry is what keeps the sweep SCHEDULED and idempotent (`ProjectedStateConformanceTest`);
    // an entry that quietly moved back to NOT_PROJECTED would leave this file green and the
    // column stale again.
    expect(ProjectedState::PROJECTIONS)->toHaveKey('invoice.past_due')
        ->and(ProjectedState::PROJECTIONS['invoice.past_due']['sweep'])->toBe('billing:scan-overdue-invoices')
        ->and(ProjectedState::PROJECTIONS['invoice.past_due']['projector'])->toBe('recomputeTotals')
        ->and(ProjectedState::NOT_PROJECTED)->not->toHaveKey('invoices.status');
});
