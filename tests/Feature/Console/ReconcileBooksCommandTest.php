<?php

use App\Models\Invoice;
use App\Models\Payment;
use App\Services\Accounting\FiscalCalendar;
use App\Services\Accounting\LedgerPoster;
use Database\Seeders\AccountMappingSeeder;
use Database\Seeders\ChartOfAccountsSeeder;
use Illuminate\Support\Facades\DB;

/**
 * `billing:reconcile` is the CLI face of BooksReconciliationService — the read-only
 * audit that gates a monthly close. It must exit 0 and report "books tie out" on
 * clean data, and exit non-zero (without mutating anything) the moment a stored
 * aggregate has drifted from its source.
 */
beforeEach(function () {
    ensureAllPropertiesAsset();
});

/** Capture a payment allocated to an invoice (mirrors the Create page + S2S callback). */
function captureReconcilePayment(Invoice $invoice, float $amount): Payment
{
    $payment = Payment::create([
        'tenant_id' => $invoice->tenant_id,
        'amount' => $amount,
        'currency' => 'EGP',
        'method' => 'bank_transfer',
        'status' => 'initiated',
        'payment_date' => now(),
    ]);
    $payment->invoices()->attach($invoice->id, ['allocated_amount' => $amount]);
    $payment->status = 'captured';
    $payment->save(); // Payment::saved → recompute on the allocated invoices

    return $payment;
}

it('exits 0 and reports the books tie out on clean data', function () {
    $invoice = makeInvoice(makeLease(makeUnit(makeAsset()))); // total 11400
    captureReconcilePayment($invoice, 5000); // partial → recompute: paid 5000, balance 6400

    $this->artisan('billing:reconcile')
        ->expectsOutputToContain('Books tie out')
        ->assertExitCode(0);
});

it('gates the close when the general ledger has drifted from the source', function () {
    $this->seed(ChartOfAccountsSeeder::class);
    $this->seed(AccountMappingSeeder::class);
    app(FiscalCalendar::class)->ensureYear((int) now()->year);

    $invoice = makeInvoice(makeLease(makeUnit(makeAsset())));
    app(LedgerPoster::class)->sync($invoice->fresh()); // GL AR = total

    // Cancel the invoice but leave the GL un-synced → the ledger overstates AR.
    $invoice->update(['status' => 'cancelled']);

    $this->artisan('billing:reconcile')
        ->expectsOutputToContain('do NOT tie out')
        ->assertExitCode(1);
});

it('surfaces the period and control totals an accountant reconciles against', function () {
    $invoice = makeInvoice(makeLease(makeUnit(makeAsset())));
    captureReconcilePayment($invoice, 5000);

    $this->artisan('billing:reconcile')
        ->expectsOutputToContain('period: all')   // no --month → whole book
        ->expectsOutputToContain('Collected (paid)')
        ->expectsOutputToContain('Outstanding AR')
        ->assertExitCode(0);
});

it('exits non-zero and reports the failure when a stored paid_amount drifts', function () {
    $invoice = makeInvoice(makeLease(makeUnit(makeAsset())));
    captureReconcilePayment($invoice, 5000);

    // Corrupt the stored paid_amount directly, bypassing recomputeTotals().
    DB::table('invoices')->where('id', $invoice->id)->update(['paid_amount' => 9999, 'balance' => 1401]);

    $this->artisan('billing:reconcile')
        ->expectsOutputToContain('Books do NOT tie out')
        ->assertExitCode(1);
});

it('exits non-zero when invoice composition is broken (subtotal + VAT ≠ total)', function () {
    $invoice = makeInvoice(makeLease(makeUnit(makeAsset())));

    DB::table('invoices')->where('id', $invoice->id)->update(['total' => 99999]);

    $this->artisan('billing:reconcile')
        ->expectsOutputToContain('Books do NOT tie out')
        ->assertExitCode(1);
});

it('is read-only — a failing run does not repair or mutate the drifted row', function () {
    $invoice = makeInvoice(makeLease(makeUnit(makeAsset())));
    captureReconcilePayment($invoice, 5000);
    DB::table('invoices')->where('id', $invoice->id)->update(['paid_amount' => 9999, 'balance' => 1401]);

    $this->artisan('billing:reconcile')->assertExitCode(1);

    // The audit must NOT have "fixed" the row — it only reports.
    $row = DB::table('invoices')->where('id', $invoice->id)->first();
    expect((float) $row->paid_amount)->toBe(9999.0);
    expect((float) $row->balance)->toBe(1401.0);
});

it('is idempotent on clean data — a second run still passes and changes nothing', function () {
    $invoice = makeInvoice(makeLease(makeUnit(makeAsset())));
    captureReconcilePayment($invoice, 5000);

    $before = DB::table('invoices')->where('id', $invoice->id)->first();

    $this->artisan('billing:reconcile')->assertExitCode(0);
    $this->artisan('billing:reconcile')->assertExitCode(0);

    $after = DB::table('invoices')->where('id', $invoice->id)->first();
    expect((float) $after->paid_amount)->toBe((float) $before->paid_amount);
    expect((float) $after->balance)->toBe((float) $before->balance);
});

it('scopes to the requested month and ignores drift in other months', function () {
    // A drifted invoice in January; a clean invoice in February.
    $jan = makeInvoice(makeLease(makeUnit(makeAsset())), [
        'issue_date' => '2026-01-05',
        'period_start' => '2026-01-01',
        'period_end' => '2026-01-31',
    ]);
    DB::table('invoices')->where('id', $jan->id)->update(['paid_amount' => 9999, 'balance' => 1401]);

    makeInvoice(makeLease(makeUnit(makeAsset())), [
        'issue_date' => '2026-02-05',
    ]);

    // Scoped to Feb → only the clean invoice is in scope → ties out.
    $this->artisan('billing:reconcile --month=2026-02')
        ->expectsOutputToContain('period: 2026-02')
        ->expectsOutputToContain('Books tie out')
        ->assertExitCode(0);

    // Scoped to Jan → the drifted invoice is in scope → fails.
    $this->artisan('billing:reconcile --month=2026-01')
        ->expectsOutputToContain('Books do NOT tie out')
        ->assertExitCode(1);
});

it('counts applied credit notes as collected so a credited invoice still ties out', function () {
    $invoice = makeInvoice(makeLease(makeUnit(makeAsset())));
    $invoice->credit_applied_amount = 1400;
    $invoice->recomputeTotals(); // paid = 0 payments + 1400 credit = 1400

    $this->artisan('billing:reconcile')
        ->expectsOutputToContain('Credits applied')
        ->expectsOutputToContain('Books tie out')
        ->assertExitCode(0);
});
