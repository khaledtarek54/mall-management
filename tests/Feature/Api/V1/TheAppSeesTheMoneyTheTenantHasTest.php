<?php

use App\Models\Payment;
use App\Models\User;
use App\Services\WriteOffInvoiceService;
use Database\Seeders\AccountMappingSeeder;
use Database\Seeders\ChartOfAccountsSeeder;

/**
 * **Three money figures the app either could not see or was told wrongly.**
 *
 * All three come from the same root: a write-off deliberately leaves `invoices.balance` standing —
 * it is not one of the four settlement channels — so any read that wants "what may still be
 * collected" has to say `collectableBalance()` out loud. `Tenant::outstandingBalance()` was taught
 * that on 2026-09-01 and its neighbours on the same two payloads were not, so the home screen could
 * contradict itself. The third is the tenant's own on-account cash, which this API had never
 * mentioned at all.
 */
beforeEach(function () {
    $this->seed(ChartOfAccountsSeeder::class);
    $this->seed(AccountMappingSeeder::class);

    $this->asset = makeAsset();
    $this->lease = makeLease(makeUnit($this->asset), null, ['status' => 'active']);
    $this->tenant = $this->lease->tenant;
    User::factory()->create();
});

it('does not report MORE overdue than is outstanding after a write-off', function () {
    $invoice = makeInvoice($this->lease, [
        'total' => 10000, 'balance' => 10000, 'subtotal' => 10000,
        'due_date' => now()->subDays(30), 'status' => 'overdue',
    ]);

    // The operator forgives 6,000 of the 10,000. `balance` does NOT move — that is the whole point
    // of a write-off not being a settlement channel — so a read on `balance` still says 10,000.
    app(WriteOffInvoiceService::class)->write($invoice, ['amount' => 6000, 'reason' => 'tenant_insolvent']);

    expect((float) $invoice->fresh()->balance)->toBe(10000.0);

    foreach (['/api/v1/me/balance', '/api/v1/me/summary'] as $url) {
        $d = $this->getJson($url, apiHeaders($this->tenant))->assertOk()->json('data');

        // Both figures net the forgiven slice. Before this, `outstanding` did and `overdue` did
        // not, so the two headline numbers on the home screen disagreed — and `overdue` was the
        // LARGER, i.e. the app chased money the operator had already written off.
        expect((float) $d['outstanding'])->toBe(4000.0)
            ->and((float) $d['overdue'])->toBe(4000.0)
            ->and((float) $d['overdue'])->toBeLessThanOrEqual((float) $d['outstanding']);
    }
});

it('stops counting an invoice as open once it is written off in full', function () {
    $invoice = makeInvoice($this->lease, [
        'total' => 10000, 'balance' => 10000, 'subtotal' => 10000, 'status' => 'issued',
    ]);
    app(WriteOffInvoiceService::class)->write($invoice, ['reason' => 'tenant_insolvent']);

    // `balance` still stands, so a count keyed on it reported an invoice the tenant is not being
    // asked for — and the app's "2 invoices to pay" badge said so on a screen with nothing to pay.
    expect((float) $invoice->fresh()->balance)->toBeGreaterThan(0.0);

    expect($this->getJson('/api/v1/me/balance', apiHeaders($this->tenant))->json('data.openCount'))->toBe(0)
        ->and($this->getJson('/api/v1/me/summary', apiHeaders($this->tenant))->json('data.openInvoices'))->toBe(0);
});

it('shows the tenant money they have paid that is not yet applied', function () {
    // A received payment with nothing allocated to it — an overpayment, or cash taken before the
    // invoice was raised. It sits on the books as unearned revenue and is spendable through
    // ApplyTenantCreditService, one of the four settlement channels.
    Payment::create([
        'tenant_id' => $this->tenant->id,
        'amount' => 2500,
        'currency' => 'EGP',
        'method' => 'bank_transfer',
        'status' => 'captured',
        'payment_date' => now(),
    ]);

    foreach (['/api/v1/me/balance', '/api/v1/me/summary'] as $url) {
        $d = $this->getJson($url, apiHeaders($this->tenant))->assertOk()->json('data');

        // The portal's AccountBalance widget has always shown this. To an app-only tenant the
        // money simply looked lost — and then an invoice was part-settled from it with nothing in
        // the payment history to explain why.
        expect((float) $d['creditOnAccount'])->toBe(2500.0);
    }
});

it('keeps a credit NOTE and cash on account apart — they are different things', function () {
    $this->tenant->creditNotes()->create([
        'number' => 'CN-'.uniqid(),
        'asset_id' => $this->asset->id,
        'status' => 'issued',
        'reason' => 'adjustment',
        'subtotal' => 1500, 'total' => 1500, 'balance' => 1500,
        'issue_date' => now(),
        'currency' => 'EGP',
    ]);

    $d = $this->getJson('/api/v1/me/summary', apiHeaders($this->tenant))->assertOk()->json('data');

    // A credit NOTE is a document the operator issued; on-account cash is money the tenant paid.
    // Summing them into one "credit" would tell the tenant they have twice what they have.
    expect((float) $d['creditAvailable'])->toBe(1500.0)
        ->and((float) $d['creditOnAccount'])->toBe(0.0);
});

it('tells the app what the gateway will actually charge, not the raw balance', function () {
    $invoice = makeInvoice($this->lease, [
        'total' => 10000, 'balance' => 10000, 'subtotal' => 10000, 'status' => 'issued',
    ]);
    app(WriteOffInvoiceService::class)->write($invoice, ['amount' => 6000, 'reason' => 'tenant_insolvent']);

    $d = $this->getJson("/api/v1/me/invoices/{$invoice->id}", apiHeaders($this->tenant))
        ->assertOk()->json('data');

    // `balance` is KEPT and still says what was owed — an accountant reconciles against it.
    expect((float) $d['balance'])->toBe(10000.0)
        // …and `payableAmount` is what every money path already charges. Printing `balance` on a
        // Pay button meant the app said 10,000 and the checkout took 4,000, with nothing on screen
        // explaining the difference.
        ->and((float) $d['payableAmount'])->toBe(4000.0);
});

it('answers zero payable on an invoice that is fully written off, while the balance stands', function () {
    $invoice = makeInvoice($this->lease, [
        'total' => 10000, 'balance' => 10000, 'subtotal' => 10000, 'status' => 'issued',
    ]);
    app(WriteOffInvoiceService::class)->write($invoice, ['reason' => 'tenant_insolvent']);

    $d = $this->getJson("/api/v1/me/invoices/{$invoice->id}", apiHeaders($this->tenant))
        ->assertOk()->json('data');

    expect((float) $d['balance'])->toBeGreaterThan(0.0)
        ->and((float) $d['payableAmount'])->toBe(0.0)
        // The button the client gates on agrees with the amount behind it — one predicate,
        // `isPayable()`, which is `InvoiceSettlement::accepts() && payableAmount() > 0`.
        ->and($d['paymentLinkUrl'])->toBeNull();
});
