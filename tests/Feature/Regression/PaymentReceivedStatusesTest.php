<?php

use App\Models\Payment;
use App\Services\Accounting\Journalizers\PaymentJournalizer;

/**
 * Canonical "money received" set (Module 06 close-out 2026-07-19): captured / reconciled / settled
 * all mean the money is on the books. The core AR (recomputeTotals), the GL journalizer, and the
 * collections widgets had drifted to `captured`-only, so marking a captured payment 'reconciled' or
 * 'settled' — a normal bank-reconciliation step, offered by the form and the state-machine doc —
 * silently UN-paid the invoice and voided its cash entry. These pin the consolidated predicate.
 */
function receivedTestInvoiceAndPayment(string $status = 'captured'): array
{
    $tenant = makeTenant();
    $lease = makeLease(makeUnit(makeAsset()), $tenant, ['status' => 'active']);
    $invoice = makeInvoice($lease, ['status' => 'issued', 'total' => 1000, 'balance' => 1000, 'paid_amount' => 0]);
    $payment = Payment::create([
        'tenant_id' => $tenant->id, 'amount' => 1000, 'currency' => 'EGP',
        'method' => 'cash', 'status' => $status, 'payment_date' => now(),
    ]);
    $payment->invoices()->attach($invoice->id, ['allocated_amount' => 1000]);
    $invoice->recomputeTotals();

    return [$invoice, $payment];
}

it('a captured payment pays the invoice', function () {
    [$invoice] = receivedTestInvoiceAndPayment('captured');
    expect((float) $invoice->fresh()->balance)->toBe(0.0)
        ->and($invoice->fresh()->status)->toBe('paid');
});

it('marking a captured payment reconciled KEEPS the invoice paid (was: silently un-paid)', function () {
    [$invoice, $payment] = receivedTestInvoiceAndPayment('captured');
    $payment->update(['status' => 'reconciled']); // saved hook recomputes the allocated invoice

    expect((float) $invoice->fresh()->balance)->toBe(0.0)
        ->and($invoice->fresh()->status)->toBe('paid');
});

it('marking a captured payment settled KEEPS the invoice paid', function () {
    [$invoice, $payment] = receivedTestInvoiceAndPayment('captured');
    $payment->update(['status' => 'settled']);

    expect((float) $invoice->fresh()->balance)->toBe(0.0);
});

it('a refunded payment re-opens the AR', function () {
    [$invoice, $payment] = receivedTestInvoiceAndPayment('captured');
    $payment->update(['status' => 'refunded']);

    expect((float) $invoice->fresh()->balance)->toBe(1000.0)
        ->and((float) $invoice->fresh()->paid_amount)->toBe(0.0);
});

it('a failed payment re-opens the AR', function () {
    [$invoice, $payment] = receivedTestInvoiceAndPayment('captured');
    $payment->update(['status' => 'failed']);

    expect((float) $invoice->fresh()->balance)->toBe(1000.0);
});

it('the GL journalizer posts every received status and skips reversals', function () {
    $this->seed(\Database\Seeders\ChartOfAccountsSeeder::class);
    $this->seed(\Database\Seeders\AccountMappingSeeder::class);
    [, $payment] = receivedTestInvoiceAndPayment('captured');
    $j = app(PaymentJournalizer::class);

    expect($j->payload($payment->fresh()))->not->toBeNull();      // captured → posts
    $payment->update(['status' => 'reconciled']);
    expect($j->payload($payment->fresh()))->not->toBeNull();      // reconciled → still posts
    $payment->update(['status' => 'settled']);
    expect($j->payload($payment->fresh()))->not->toBeNull();      // settled → still posts
    $payment->update(['status' => 'refunded']);
    expect($j->payload($payment->fresh()))->toBeNull();           // refunded → voided
});

it('the received scope (what the collections widgets + statements + reconciliation sum) counts captured/reconciled/settled and excludes reversals', function () {
    $tenant = makeTenant();
    foreach (['captured', 'reconciled', 'settled', 'refunded', 'failed', 'bounced', 'initiated'] as $i => $status) {
        Payment::create([
            'tenant_id' => $tenant->id, 'amount' => 100, 'currency' => 'EGP',
            'method' => 'cash', 'status' => $status, 'payment_date' => now(),
        ]);
    }

    // Only the three received statuses (3 × 100) — the same predicate MallStats / RecentPayments /
    // the tenant+owner statements / ReportService / BooksReconciliationService all use.
    expect((float) Payment::query()->received()->sum('amount'))->toBe(300.0)
        ->and(Payment::RECEIVED_STATUSES)->toBe(['captured', 'reconciled', 'settled']);
});

it('a received payment (any of captured/reconciled/settled) can be voided', function () {
    foreach (['captured', 'reconciled', 'settled'] as $status) {
        [$invoice, $payment] = receivedTestInvoiceAndPayment($status);
        app(\App\Services\VoidPaymentService::class)->void($payment, 'test refund');

        expect($payment->fresh()->status)->toBe('refunded')
            ->and((float) $invoice->fresh()->balance)->toBe(1000.0);
    }
});
