<?php

use App\Console\Commands\RemindOverdueTenantsCommand;
use App\Models\Invoice;
use App\Notifications\InvoiceOverdueTenantNotification;
use App\Services\WriteOffInvoiceService;
use Database\Seeders\AccountMappingSeeder;
use Database\Seeders\ChartOfAccountsSeeder;
use Illuminate\Support\Facades\Notification;

/**
 * SW-156. The overdue sweep re-read its DECISION under the lock and not the AMOUNT.
 *
 * `RemindOverdueTenantsCommand` selects everything overdue, then locks each invoice and re-checks
 * the stamp, the follow-up window and the notice ceiling — carefully, and none of that is about
 * whether the money is still owed. A payment landing between the outer query and the lock therefore
 * produced a chase letter for an invoice the tenant had just settled: the one notification
 * guaranteed to be read, saying the one thing guaranteed to be wrong. A write-off in the same
 * window did it too, which is worse, because the operator had themselves forgiven the money.
 *
 * The window is real rather than theoretical: the sweep runs over every overdue invoice in the
 * portfolio, and the tenant paying is exactly the person whose invoice is in that list.
 */
beforeEach(function () {
    $this->seed(ChartOfAccountsSeeder::class);
    $this->seed(AccountMappingSeeder::class);

    $this->lease = makeLease(makeUnit(makeAsset()), null, ['status' => 'active']);
    $this->tenant = $this->lease->tenant;
});

function anOverdueInvoiceToChase(): Invoice
{
    return makeInvoice(test()->lease, [
        'status' => 'overdue',
        'subtotal' => 10000, 'total' => 10000, 'balance' => 10000,
        'issue_date' => now()->subDays(40),
        'due_date' => now()->subDays(30),
    ]);
}

it('chases a debt that is still owed', function () {
    Notification::fake();
    anOverdueInvoiceToChase();

    test()->artisan(RemindOverdueTenantsCommand::class)->assertSuccessful();

    // The control. Every refusal below is paired with it, because a sweep that notified nobody
    // would satisfy them all and read as a pass.
    Notification::assertSentTo($this->tenant, InvoiceOverdueTenantNotification::class);
});

it('does not chase an invoice the sweep would no longer select', function () {
    Notification::fake();
    $invoice = anOverdueInvoiceToChase();

    // The tenant pays — a REAL captured payment, not a hand-set balance. `recomputeTotals()` is
    // the single source of truth for `paid_amount`/`balance` and recomputes both from the four
    // settlement channels on every save, so forcing the columns is undone the moment anything
    // touches the row; a fixture that fakes them tests nothing and passes for the wrong reason.
    $payment = $this->tenant->payments()->create([
        'amount' => 10000, 'currency' => 'EGP', 'method' => 'bank_transfer',
        'status' => 'captured', 'payment_date' => now(),
    ]);
    $payment->invoices()->attach($invoice->id, ['allocated_amount' => 10000]);
    $invoice->recomputeTotals();

    expect((float) $invoice->fresh()->balance)->toBe(0.0);

    test()->artisan(RemindOverdueTenantsCommand::class)->assertSuccessful();

    Notification::assertNothingSentTo($this->tenant);

    // …and nothing is recorded either. Nothing was sent, so if it falls overdue again the ladder
    // resumes where it stood rather than skipping a rung.
    expect($invoice->fresh()->tenant_overdue_notified_at)->toBeNull()
        ->and((int) $invoice->fresh()->dunning_level)->toBe(0);
});

it('does not chase a debt the operator has written off in full', function () {
    Notification::fake();
    $invoice = anOverdueInvoiceToChase();

    app(WriteOffInvoiceService::class)->write($invoice, ['reason' => 'tenant_insolvent']);

    // A write-off deliberately leaves `balance` standing — it is not a settlement channel — so a
    // guard reading `balance` sees the full 10,000 and chases money that has already gone to bad
    // debt. Only `collectableBalance()` answers this.
    expect((float) $invoice->fresh()->balance)->toBeGreaterThan(0.0);

    test()->artisan(RemindOverdueTenantsCommand::class)->assertSuccessful();

    Notification::assertNothingSentTo($this->tenant);
});

it('still chases the part of a debt that was not forgiven', function () {
    Notification::fake();
    $invoice = anOverdueInvoiceToChase();

    app(WriteOffInvoiceService::class)->write($invoice, ['amount' => 6000, 'reason' => 'tenant_insolvent']);

    // 4,000 is still owed, so the letter goes — and the amount inside it is the collectable one,
    // which the notification was already careful about.
    test()->artisan(RemindOverdueTenantsCommand::class)->assertSuccessful();

    Notification::assertSentTo(
        $this->tenant,
        InvoiceOverdueTenantNotification::class,
        fn (InvoiceOverdueTenantNotification $n) => (float) $n->toDatabase($this->tenant)['balance'] === 4000.0,
    );
});

/**
 * **The race itself — and it needs simulating, because a plain fixture cannot reach the guard.**
 *
 * The outer query already carries `whereCollectable()`, so an invoice settled BEFORE the sweep runs
 * never reaches the lock: the three cases above pin that selection and prove nothing about the
 * guard inside the transaction. Removing the guard leaves all three green, which is exactly what
 * the mutation run reported.
 *
 * What the guard is for is the window between that query and the lock — the sweep walks every
 * overdue invoice in the portfolio, and the tenant paying is precisely the person whose invoice is
 * in that list. So the payment is recorded from a one-shot `retrieved` listener on the LOCKING read,
 * which is the same instant, observably, as it landing a moment before.
 */
it('does not chase an invoice settled in the window between the query and the lock', function () {
    Notification::fake();
    $invoice = anOverdueInvoiceToChase();
    $tenant = $this->tenant;

    $settled = false;

    Invoice::retrieved(function (Invoice $model) use ($invoice, $tenant, &$settled) {
        // Only the sweep's own locking re-read, and only once — the guard itself re-reads through
        // relations, and settling on every retrieval would be a different scenario.
        if ($settled || $model->getKey() !== $invoice->getKey() || ! $model->exists) {
            return;
        }

        $settled = true;

        $payment = $tenant->payments()->create([
            'amount' => 10000, 'currency' => 'EGP', 'method' => 'bank_transfer',
            'status' => 'captured', 'payment_date' => now(),
        ]);
        $payment->invoices()->attach($invoice->id, ['allocated_amount' => 10000]);
        $invoice->recomputeTotals();
    });

    test()->artisan(RemindOverdueTenantsCommand::class)->assertSuccessful();

    // The money arrived. Without the guard the letter goes out anyway, asking for it.
    expect($settled)->toBeTrue()
        ->and((float) $invoice->fresh()->balance)->toBe(0.0);

    Notification::assertNothingSentTo($tenant);

    // And the stamp stays clean, so the ladder resumes rather than skipping a rung if this debt
    // ever falls overdue again.
    expect($invoice->fresh()->tenant_overdue_notified_at)->toBeNull();
});
