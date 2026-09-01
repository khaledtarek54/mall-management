<?php

use App\Models\Invoice;
use App\Models\Payment;
use App\Services\ApplyTenantCreditService;
use Carbon\CarbonImmutable;
use Database\Seeders\AccountMappingSeeder;
use Database\Seeders\ChartOfAccountsSeeder;

/**
 * A receipt's surplus funds the tenant's credit — re-allocating the receipt must not spend it again.
 *
 * `EditPayment` capped a re-allocation at the receipt's own `amount`, which asks *"does this add up
 * to the receipt"* and cannot see that part of it has already left. A receipt's UNALLOCATED
 * remainder is exactly what funds `Tenant::creditBalance()`, so once some of that has been applied
 * to another invoice, the receipt has less than its face value left to give:
 *
 *   10,000 received · 4,000 allocated · 6,000 drawn as credit against invoice B
 *   → re-allocate the receipt to 10,000 against invoice A
 *   → the pivot says 10,000 settled A, the application says 6,000 settled B, out of one 10,000
 *     receipt. The same money twice, surfacing only as a negative credit balance nobody reads.
 *
 * Nothing links an application back to the receipt that funded it — the credit is a POOL — so the
 * only honest question is whether that pool is still solvent afterwards, which is what
 * `Payment::assertCreditNotOverdrawn()` asks. Scoped by property exactly as `VoidPaymentService`'s
 * sibling guard scopes it, because a global balance would let an unrelated credit at another mall
 * stand in for this one.
 */
beforeEach(function () {
    $this->seed(ChartOfAccountsSeeder::class);
    $this->seed(AccountMappingSeeder::class);

    $this->asset = makeAsset();
    $this->lease = makeLease(makeUnit($this->asset), makeTenant());
});

function openInvoice(float $total): Invoice
{
    return makeInvoice(test()->lease, [
        'status' => 'issued', 'subtotal' => $total, 'vat_amount' => 0, 'total' => $total,
        'paid_amount' => 0, 'balance' => $total,
    ]);
}

it('refuses to re-allocate a receipt whose surplus is already spent', function () {
    $a = openInvoice(10000);
    $b = openInvoice(6000);

    // 10,000 received, only 4,000 put against invoice A — 6,000 of surplus.
    $payment = Payment::create([
        'tenant_id' => $this->lease->tenant_id, 'amount' => 10000, 'currency' => 'EGP',
        'method' => 'bank_transfer', 'status' => 'captured', 'payment_date' => CarbonImmutable::now(),
    ]);
    $payment->invoices()->attach($a->id, ['allocated_amount' => 4000]);
    $payment->recomputeAllocatedInvoices();

    // The surplus is then drawn down against invoice B as on-account credit.
    app(ApplyTenantCreditService::class)->applyToInvoice($b->fresh());

    // The premise: the credit really did move, and the pool is now empty.
    expect(round((float) $b->fresh()->paid_amount, 2))->toEqual(6000.0)
        ->and(round((float) $this->lease->tenant->fresh()->creditBalance([$this->asset->id]), 2))->toEqual(0.0);

    // Now re-allocate the whole receipt to invoice A. The total-vs-amount cap is satisfied — 10,000
    // against a 10,000 receipt — and this is the money that has already settled B.
    expect(function () use ($payment, $a) {
        DB::transaction(function () use ($payment, $a) {
            $payment->invoices()->sync([$a->id => ['allocated_amount' => 10000]]);
            $payment->recomputeAllocatedInvoices();
            $payment->assertCreditNotOverdrawn();
        });
    })->toThrow(DomainException::class);

    // …and nothing moved: B keeps its settlement, A keeps its original allocation.
    expect(round((float) $b->fresh()->paid_amount, 2))->toEqual(6000.0)
        ->and(round((float) $a->fresh()->paid_amount, 2))->toEqual(4000.0);
});

it('still allows a re-allocation the surplus can afford — the control', function () {
    // Without this, a guard that refused every re-allocation would satisfy the test above.
    $a = openInvoice(10000);
    $c = openInvoice(3000);

    $payment = Payment::create([
        'tenant_id' => $this->lease->tenant_id, 'amount' => 10000, 'currency' => 'EGP',
        'method' => 'bank_transfer', 'status' => 'captured', 'payment_date' => CarbonImmutable::now(),
    ]);
    $payment->invoices()->attach($a->id, ['allocated_amount' => 4000]);
    $payment->recomputeAllocatedInvoices();

    // No credit has been drawn, so the whole receipt is still the receipt's to give.
    DB::transaction(function () use ($payment, $a, $c) {
        $payment->invoices()->sync([
            $a->id => ['allocated_amount' => 7000],
            $c->id => ['allocated_amount' => 3000],
        ]);
        $payment->recomputeAllocatedInvoices();
        $payment->assertCreditNotOverdrawn();
    });

    expect(round((float) $a->fresh()->paid_amount, 2))->toEqual(7000.0)
        ->and(round((float) $c->fresh()->paid_amount, 2))->toEqual(3000.0);
});

it('scopes the question to the receipt own mall, not the tenant global credit', function () {
    // The same reasoning `VoidPaymentService` carries: an unrelated surplus at another mall must not
    // stand in for the one this receipt is spending.
    $other = makeAsset(['code' => 'BB']);
    $otherLease = makeLease(makeUnit($other), $this->lease->tenant);

    $a = openInvoice(10000);
    $b = openInvoice(6000);

    $payment = Payment::create([
        'tenant_id' => $this->lease->tenant_id, 'amount' => 10000, 'currency' => 'EGP',
        'method' => 'bank_transfer', 'status' => 'captured', 'payment_date' => CarbonImmutable::now(),
    ]);
    $payment->invoices()->attach($a->id, ['allocated_amount' => 4000]);
    $payment->recomputeAllocatedInvoices();
    app(ApplyTenantCreditService::class)->applyToInvoice($b->fresh());

    // A large untouched surplus at the OTHER mall.
    $elsewhere = Payment::create([
        'tenant_id' => $this->lease->tenant_id, 'amount' => 50000, 'currency' => 'EGP',
        'method' => 'bank_transfer', 'status' => 'captured', 'payment_date' => CarbonImmutable::now(),
    ]);
    $elsewhere->invoices()->attach(
        makeInvoice($otherLease, [
            'status' => 'issued', 'subtotal' => 1000, 'vat_amount' => 0, 'total' => 1000,
            'paid_amount' => 0, 'balance' => 1000,
        ])->id,
        ['allocated_amount' => 1000],
    );
    $elsewhere->recomputeAllocatedInvoices();

    expect(round((float) $this->lease->tenant->fresh()->creditBalance(null), 2))->toBeGreaterThan(0.0);

    // Still refused: the other mall's money is not this receipt's to spend.
    expect(function () use ($payment, $a) {
        DB::transaction(function () use ($payment, $a) {
            $payment->invoices()->sync([$a->id => ['allocated_amount' => 10000]]);
            $payment->recomputeAllocatedInvoices();
            $payment->assertCreditNotOverdrawn();
        });
    })->toThrow(DomainException::class);
});
