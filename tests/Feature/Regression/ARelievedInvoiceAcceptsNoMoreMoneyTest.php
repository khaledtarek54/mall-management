<?php

use App\Models\Invoice;
use App\Models\Payment;
use App\Models\PostDatedCheque;
use App\Models\User;
use App\Services\ApplyTenantCreditService;
use App\Services\PostDatedChequeService;
use App\Services\WriteOffInvoiceService;
use App\Support\InvoiceSettlement;
use App\Support\ValueSets;
use Database\Seeders\AccountMappingSeeder;
use Database\Seeders\ChartOfAccountsSeeder;

/**
 * An invoice whose AR has already been relieved accepts no more money — through ANY door.
 *
 * Four channels settle an invoice, and five call sites each carried their own opinion of which
 * invoices are eligible. They had drifted into five different answers: an allowlist here, a denylist
 * there, a lone `cancelled` test in a third, and — on the PDC clearing path — nothing at all.
 *
 * What makes this a registry rather than a tidy-up is that a **WRITE-OFF deliberately leaves
 * `balance` standing**. Balance is derived from the four channels and a write-off is not one of
 * them, so every guard capping at `balance` alone caps at a number the write-off never moved. And
 * `cancelled` was safe only by ACCIDENT — `recomputeTotals()` forces its balance to zero, so
 * `min($amount, $balance)` is zero with nobody asking about the status. `written_off` is the one
 * relieved status where that accident does not happen.
 *
 * The cost, measured: write off an invoice, then clear the cheque lodged against it months earlier.
 * AR is debited at issue, relieved by the write-off (Dr Bad Debt / Cr AR) and relieved AGAIN by the
 * receipt — ending negative for one debt, with the bad-debt expense standing for money that was in
 * fact collected, and `billing:reconcile --deep` permanently red.
 *
 * Every door is tested, because closing one is what produced this in the first place.
 */
beforeEach(function () {
    $this->seed(ChartOfAccountsSeeder::class);
    $this->seed(AccountMappingSeeder::class);

    $this->asset = makeAsset();
    $this->lease = makeLease(makeUnit($this->asset), null, ['status' => 'active']);
    $this->tenant = $this->lease->tenant;
    $this->actor = User::factory()->create();
});

/** An issued invoice, written off in full — the shape a balance-only cap cannot see. */
function writtenOffInvoice(): Invoice
{
    $invoice = makeInvoice(test()->lease);
    $invoice->update(['status' => 'issued']);
    app(WriteOffInvoiceService::class)->write($invoice, ['reason' => 'tenant_insolvent']);

    $fresh = $invoice->fresh();

    // The premise every refusal below rests on: the balance really does still stand.
    expect($fresh->status)->toBe('written_off')
        ->and((float) $fresh->balance)->toBeGreaterThan(0.0);

    return $fresh;
}

/**
 * Real, drawable on-account credit for this tenant in this property.
 *
 * A bare unallocated receipt is NOT enough: `Tenant::creditBalance([$assetId])` attributes credit
 * through the invoices a payment settles, and a receipt with no allocations takes its property from
 * a cleared CHEQUE or from nothing at all. A fixture built that way yields zero credit — so a
 * refusal test written on it passes because there was nothing to apply, not because the status
 * refused it. An overpayment against a live invoice is the realistic shape and the honest one.
 */
function drawableCredit(float $surplus): void
{
    $settled = makeInvoice(test()->lease);
    $settled->update(['status' => 'issued']);

    $payment = Payment::create([
        'asset_id' => test()->asset->id,
        'tenant_id' => test()->tenant->id,
        'amount' => (float) $settled->balance + $surplus,
        'currency' => 'EGP',
        'method' => 'cash',
        'status' => 'captured',
        'payment_date' => '2026-02-01',
    ]);

    $payment->invoices()->sync([$settled->id => ['allocated_amount' => (float) $settled->balance]]);
    $payment->recomputeAllocatedInvoices();

    expect(test()->tenant->fresh()->creditBalance([test()->asset->id]))->toEqual($surplus);
}

it('classifies every invoice status as relieved or live, with a reason', function () {
    // The partition gate. A new status must not inherit a default on a question about money — the
    // idiom DeletionPolicy and ChangeImpact already use, for the same reason.
    $classified = array_merge(array_keys(InvoiceSettlement::RELIEVED), array_keys(InvoiceSettlement::LIVE));
    $allowed = ValueSets::allowed('invoices', 'status');

    expect(array_diff($allowed, $classified))->toBe([], 'an invoice status is classified neither relieved nor live')
        ->and(array_diff($classified, $allowed))->toBe([], 'InvoiceSettlement classifies a status the column cannot hold')
        // No status may sit on both sides.
        ->and(array_intersect(array_keys(InvoiceSettlement::RELIEVED), array_keys(InvoiceSettlement::LIVE)))->toBe([]);

    foreach (array_merge(InvoiceSettlement::RELIEVED, InvoiceSettlement::LIVE) as $status => $reason) {
        expect(strlen($reason))->toBeGreaterThan(25, "the reason for '{$status}' is too thin to review");
    }
});

it('refuses a cleared cheque against an invoice written off after it was lodged', function () {
    $invoice = writtenOffInvoice();

    $cheque = PostDatedCheque::create([
        'reference' => PostDatedCheque::generateReference(),
        'asset_id' => $this->asset->id,
        'tenant_id' => $this->tenant->id,
        'lease_id' => $this->lease->id,
        'invoice_id' => $invoice->id,          // linked when it was still collectable
        'cheque_number' => 'CHQ-'.uniqid(),
        'bank_name' => 'CIB',
        'amount' => 11400,
        'currency' => 'EGP',
        'cheque_date' => '2026-02-05',
        'received_date' => '2026-01-05',
        'status' => PostDatedCheque::STATUS_HELD,
    ]);

    app(PostDatedChequeService::class)->clear($cheque, $this->actor, '2026-02-05');

    // The cash is real and is still captured — it becomes the tenant's credit, which is the honest
    // answer. What must NOT happen is it landing on AR the write-off already relieved.
    $payment = Payment::where('tenant_id', $this->tenant->id)->firstOrFail();

    expect((float) $payment->invoices()->sum('invoice_payment.allocated_amount'))->toEqual(0.0)
        ->and((float) $invoice->fresh()->paid_amount)->toEqual(0.0);
});

it('refuses tenant credit against a written-off invoice', function () {
    $invoice = writtenOffInvoice();

    drawableCredit(5000);

    $applied = app(ApplyTenantCreditService::class)->applyToInvoice($invoice);

    expect($applied)->toEqual(0.0)
        ->and((float) $invoice->fresh()->paid_amount)->toEqual(0.0);
});

it('still settles a live invoice through both doors — the control', function () {
    // Without this, a predicate that refused everything would satisfy every refusal above.
    $invoice = makeInvoice($this->lease);
    $invoice->update(['status' => 'issued']);

    drawableCredit(5000);

    expect(app(ApplyTenantCreditService::class)->applyToInvoice($invoice))->toEqual(5000.0)
        ->and((float) $invoice->fresh()->paid_amount)->toEqual(5000.0);
});

it('caps a settlement at the un-forgiven part of a PARTIAL write-off', function () {
    $invoice = makeInvoice($this->lease);                        // 11,400
    $invoice->update(['status' => 'issued']);

    app(WriteOffInvoiceService::class)->write($invoice, ['amount' => 1400, 'reason' => 'tenant_insolvent']);

    $partial = $invoice->fresh();

    // A partial write-off deliberately leaves the invoice LIVE and collectable for the rest.
    expect($partial->status)->not->toBe('written_off')
        ->and(InvoiceSettlement::settleableAmount($partial))->toEqual(10000.0);
});

it('refuses tenant credit against a DRAFT invoice — the case only the status can catch', function () {
    // This is the case that proves `accepts()` rather than the arithmetic. A FULL write-off is
    // already caught by netting (balance − written-off = 0), and `cancelled` and `credited` both
    // carry a zero balance — so for all three the amount cap alone would do. A DRAFT keeps a
    // positive balance and has nothing written off, so only the STATUS can refuse it.
    //
    // Found by mutation: with `accepts()` forced to true every other case in this file still passed.
    // NOT followed by recomputeTotals(): `draft` is not one of its manual overrides, so it would
    // flip the invoice to `issued` on the spot — which is precisely the mechanism the RELIEVED entry
    // for `draft` describes, met here while writing the test for it.
    $draft = makeInvoice($this->lease, ['status' => 'draft']);

    expect((float) $draft->fresh()->balance)->toBeGreaterThan(0.0)   // the premise
        ->and(InvoiceSettlement::settleableAmount($draft->fresh()))->toEqual(0.0);

    drawableCredit(5000);

    expect(app(ApplyTenantCreditService::class)->applyToInvoice($draft->fresh()))->toEqual(0.0)
        // …and it is still a draft: nothing flipped it to partially_paid behind the operator.
        ->and($draft->fresh()->status)->toBe('draft')
        ->and((float) $draft->fresh()->paid_amount)->toEqual(0.0);
});


it('refuses a receipt that would over-relieve the un-forgiven part', function () {
    // The MODEL-LEVEL backstop, which the picker tests above do not reach: `assertInvoicesNotOverAllocated`
    // compared the four channels against the raw `total`, and `total` is another number a write-off
    // never moves. Without the netting a 11,400 receipt fits an invoice with 1,400 already written
    // off — AR relieved 12,800 for an 11,400 debt.
    $invoice = makeInvoice($this->lease);                        // 11,400
    $invoice->update(['status' => 'issued']);
    app(WriteOffInvoiceService::class)->write($invoice, ['amount' => 1400, 'reason' => 'tenant_insolvent']);

    $payment = Payment::create([
        'asset_id' => $this->asset->id,
        'tenant_id' => $this->tenant->id,
        'amount' => 11400,
        'currency' => 'EGP',
        'method' => 'cash',
        'status' => 'captured',
        'payment_date' => '2026-02-01',
    ]);
    $payment->invoices()->sync([$invoice->id => ['allocated_amount' => 11400]]);

    expect(fn () => $payment->assertInvoicesNotOverAllocated([$invoice->id]))
        ->toThrow(DomainException::class);

    // The control: exactly the un-forgiven 10,000 is accepted.
    $payment->invoices()->sync([$invoice->id => ['allocated_amount' => 10000]]);
    $payment->assertInvoicesNotOverAllocated([$invoice->id]);
});

it('refuses a receipt allocated to a relieved invoice on the SAVE path, not only in the picker', function () {
    // The picker narrowing is a UI truth — the ids arrive in a Livewire payload, and CreatePayment's
    // `?invoice=` deep-link prefills from the resource query with no status test at all. The amount
    // check cannot catch a DRAFT either: nothing is written off, so its full total fits.
    $draft = makeInvoice($this->lease, ['status' => 'draft']);

    $payment = Payment::create([
        'asset_id' => $this->asset->id,
        'tenant_id' => $this->tenant->id,
        'amount' => 11400,
        'currency' => 'EGP',
        'method' => 'cash',
        'status' => 'captured',
        'payment_date' => '2026-02-01',
    ]);
    $payment->invoices()->sync([$draft->id => ['allocated_amount' => 11400]]);

    expect(fn () => $payment->assertInvoicesNotOverAllocated([$draft->id]))
        ->toThrow(DomainException::class);
});

it('does not offer a draft invoice to the payment allocation picker', function () {
    // A draft was never posted, so nothing relieved it — it fails a different test from the other
    // three, which is exactly why a denylist grown one incident at a time never mentioned it.
    // Allocating cash to a draft credits AR the journalizer never debited, then flips it to paid.
    $draft = makeInvoice($this->lease, ['status' => 'draft']);
    $live = makeInvoice($this->lease);
    $live->update(['status' => 'issued']);

    $offered = Invoice::query()->acceptingSettlement()->pluck('id');

    expect($offered)->not->toContain($draft->id)
        ->and($offered)->toContain($live->id);       // the control
});
