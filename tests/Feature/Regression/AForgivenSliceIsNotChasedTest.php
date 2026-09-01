<?php

use App\Models\Invoice;
use App\Models\Payment;
use App\Notifications\InvoiceOverdueTenantNotification;
use App\Services\LateFeeService;
use App\Services\WriteOffInvoiceService;
use Carbon\CarbonImmutable;
use Database\Seeders\AccountMappingSeeder;
use Database\Seeders\ChartOfAccountsSeeder;

/**
 * A partially-forgiven debt is not chased for the part that was forgiven.
 *
 * `invoices.balance` answers *what was owed* and `invoices.status` answers *has this left the books*.
 * A PARTIAL write-off is neither — the invoice is still live, still collectable, but smaller — and
 * there was no third term to say so. Every collections surface therefore fell back to `balance`:
 * the overdue scan, the tenant-facing dunning ladder, the late-fee base, the tenant's own
 * outstanding figure and their delinquency flag all went on demanding money the operator had
 * written off and the bad-debt entry had already relieved (Dr Bad Debt / Cr AR).
 *
 * It got SHARPER, not milder, when the settlement side learnt to net write-offs: the invoice then
 * could not be paid down to zero either, because the cap refused the forgiven part while these
 * reads went on asking for it. That dead end is what this closes.
 *
 * `Invoice::collectableBalance()` is the missing term and `chargeableBalance()` is its penalty
 * twin — two reductions that are deliberately different questions. A DISPUTED amount is still
 * claimed and still chased, only not chargeable; a FORGIVEN amount is not claimed at all.
 */
beforeEach(function () {
    $this->seed(ChartOfAccountsSeeder::class);
    $this->seed(AccountMappingSeeder::class);

    $this->asset = makeAsset();
    $this->lease = makeLease(makeUnit($this->asset), null, ['status' => 'active']);
    $this->tenant = $this->lease->tenant;
    $this->today = CarbonImmutable::parse('2026-06-30');
});

/** An overdue invoice of 11,400 with `$forgiven` written off — still live, and smaller. */
function partlyForgivenInvoice(float $forgiven): Invoice
{
    $invoice = makeInvoice(test()->lease, [
        'issue_date' => test()->today->subDays(60)->toDateString(),
        'due_date' => test()->today->subDays(45)->toDateString(),
    ]);
    $invoice->update(['status' => 'overdue']);

    app(WriteOffInvoiceService::class)->write($invoice->fresh(), [
        'amount' => $forgiven,
        'reason' => 'tenant_insolvent',
    ]);

    $fresh = $invoice->fresh();

    // The premise: a PARTIAL write-off deliberately leaves the invoice live with its whole balance
    // standing. Without that, none of the assertions below would be measuring anything.
    expect($fresh->status)->not->toBe('written_off')
        ->and((float) $fresh->balance)->toEqual(11400.0);

    return $fresh;
}

/** Forgive 1,400 of an 11,400 debt, then receive the other 10,000 — nothing left to collect. */
function settledAfterForgiveness(): Invoice
{
    $invoice = partlyForgivenInvoice(1400);

    $payment = Payment::create([
        'tenant_id' => test()->tenant->id,
        'amount' => 10000,
        'currency' => 'EGP',
        'method' => 'cash',
        'status' => 'captured',
        'payment_date' => test()->today->toDateString(),
    ]);
    $payment->invoices()->sync([$invoice->id => ['allocated_amount' => 10000]]);
    $payment->recomputeAllocatedInvoices();

    $settled = $invoice->fresh();
    expect($settled->collectableBalance())->toEqual(0.0);   // the premise

    return $settled;
}

it('reports only the un-forgiven part as collectable', function () {
    $invoice = partlyForgivenInvoice(1400);

    expect($invoice->collectableBalance())->toEqual(10000.0)
        // `balance` is untouched — it still records what was owed, which is what the write-off
        // relies on and what the GL tie-out reconciles against.
        ->and((float) $invoice->balance)->toEqual(11400.0);
});

it('stops asking the tenant for the forgiven part', function () {
    partlyForgivenInvoice(1400);

    // The tenant's own figure — their statement, the portal, the hub and the mobile API all read it.
    expect($this->tenant->fresh()->outstandingBalance())->toEqual(10000.0);
});

it('charges the late fee on the un-forgiven part only', function () {
    $invoice = partlyForgivenInvoice(1400);

    app(LateFeeService::class)->applyTo($invoice, $this->today);

    $fee = Invoice::query()->where('late_fee_for_invoice_id', $invoice->id)->firstOrFail();

    // 2% of 10,000 — the portfolio default rate on what is still claimed, not on the 11,400 that
    // includes money nobody is owed.
    expect((float) $fee->subtotal)->toEqual(200.0);
});

it('lets a partly-forgiven invoice reach zero, which it could not before', function () {
    // The dead end the settlement cap created: the guard refuses the forgiven slice, so paying the
    // rest used to leave the invoice permanently open to every collections read.
    $invoice = partlyForgivenInvoice(1400);

    // Through a real receipt, not by writing `paid_amount`: `recomputeTotals()` derives it from the
    // four channels and would simply overwrite a hand-set figure — a fixture that sets up a state
    // the product cannot produce.
    $payment = Payment::create([
        'tenant_id' => $this->tenant->id,
        'amount' => 10000,
        'currency' => 'EGP',
        'method' => 'cash',
        'status' => 'captured',
        'payment_date' => $this->today->toDateString(),
    ]);
    $payment->invoices()->sync([$invoice->id => ['allocated_amount' => 10000]]);
    $payment->recomputeAllocatedInvoices();

    $settled = $invoice->fresh();

    expect((float) $settled->paid_amount)->toEqual(10000.0);

    expect($settled->collectableBalance())->toEqual(0.0)
        ->and(Invoice::query()->whereCollectable()->pluck('id'))->not->toContain($settled->id)
        ->and($this->tenant->fresh()->outstandingBalance())->toEqual(0.0);
});

it('drops the invoice out of the sweeps that chase it', function () {
    // The SELECTION half. Reverting `whereCollectable()` in these three left every other assertion
    // in this file green, because neither command was driven — so they are driven here.
    // Forgive part, then receive the rest. A write-off within a hundredth of the balance RETIRES
    // the invoice (`$amount >= $remaining - 0.01`), so this — partial forgiveness plus settlement —
    // is the only reachable state where a LIVE invoice has nothing collectable left.
    $forgiven = settledAfterForgiveness();
    $chased = makeInvoice($this->lease, [
        'issue_date' => $this->today->subDays(60)->toDateString(),
        'due_date' => $this->today->subDays(45)->toDateString(),
    ]);
    $chased->update(['status' => 'overdue']);

    $selected = Invoice::query()
        ->whereIn('status', ['issued', 'partially_paid', 'overdue'])
        ->whereCollectable()
        ->pluck('id');

    expect($selected)->toContain($chased->id)               // the control
        ->and($selected)->not->toContain($forgiven->id);

    // And the late-fee run, end to end, not just applyTo().
    $stats = app(LateFeeService::class)->runForToday($this->today);

    expect(Invoice::query()->where('late_fee_for_invoice_id', $chased->id)->exists())->toBeTrue()
        ->and(Invoice::query()->where('late_fee_for_invoice_id', $forgiven->id)->exists())->toBeFalse()
        ->and($stats['failed'])->toBe(0)
        // `considered` is the SELECTION query's own count, and asserting it is what makes that layer
        // falsifiable: the row-level re-check inside applyTo() refuses the forgiven invoice anyway,
        // so every outcome-based assertion above stays green with the selection reverted. Two layers
        // is right; a test that can only see one of them is not.
        ->and($stats['considered'])->toBe(1);
});

it('stops calling the tenant delinquent once the debt is forgiven', function () {
    settledAfterForgiveness();

    // `isDelinquent()` was routed and nothing asserted it FALSE, so its routing was unproven.
    expect($this->tenant->fresh()->isDelinquent())->toBeFalse();
});

it('tells the tenant the same number everywhere', function () {
    $invoice = partlyForgivenInvoice(1400);

    // The chase letter is the artefact that matters: selecting correctly and then quoting the raw
    // balance asks for the forgiven money in the one sentence the tenant actually reads.
    $mail = (new InvoiceOverdueTenantNotification($invoice))
        ->toMail($this->tenant);

    $body = collect($mail->introLines)->concat($mail->outroLines)->implode(' ');

    expect($body)->toContain('10,000.00')
        ->and($body)->not->toContain('11,400.00');
});

it('still chases a debt nobody has forgiven — the control', function () {
    // A predicate that hid everything would satisfy every assertion above and read as a pass.
    $invoice = makeInvoice($this->lease, [
        'issue_date' => $this->today->subDays(60)->toDateString(),
        'due_date' => $this->today->subDays(45)->toDateString(),
    ]);
    $invoice->update(['status' => 'overdue']);

    expect($invoice->fresh()->collectableBalance())->toEqual(11400.0)
        ->and(Invoice::query()->whereCollectable()->pluck('id'))->toContain($invoice->id)
        ->and($this->tenant->fresh()->outstandingBalance())->toEqual(11400.0)
        ->and($this->tenant->fresh()->isDelinquent())->toBeTrue();
});

it('recovers the whole debt when the write-off is reversed', function () {
    $invoice = partlyForgivenInvoice(1400);

    $writeOff = $invoice->writeOffs()->firstOrFail();
    app(WriteOffInvoiceService::class)->reverse($writeOff, 'Tenant agreed a payment plan.');

    // A reversal SOFT-DELETES, so the relation's default scope drops it and the debt becomes
    // collectable again with no second rule to keep in step.
    expect($invoice->fresh()->collectableBalance())->toEqual(11400.0)
        ->and($this->tenant->fresh()->outstandingBalance())->toEqual(11400.0);
});
