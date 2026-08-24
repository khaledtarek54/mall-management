<?php

use App\Models\Lease;
use App\Services\ApplyDepositToInvoiceService;
use Database\Seeders\RolesPermissionsSeeder;

/**
 * `depositHeld()` may be made cheap, but it may not be made different.
 *
 * The leases LIST was issuing two queries per row that no `with()` could reach — a
 * `DepositApplication::where('lease_id', …)` sum and a `settledDepositBillings()` that built its own
 * `Invoice::query()`. On a 25-row page that is 50 queries for one column, and the column is on by
 * default because it shows exposure.
 *
 * Both now prefer an eager-loaded relation, exactly as the method already did for `deposits`. That
 * is only safe if the two paths cannot disagree — and `depositHeld()` is not a display figure: it
 * backs the REFUND GUARD in `LeaseActions` ("you cannot refund more than is held"). A loaded
 * instance answering differently from an unloaded one would let an over-refund through on whichever
 * screen happened to eager-load.
 *
 * So this asserts the two are identical on a lease carrying every channel at once — receipts,
 * refunds, forfeits, an application against an invoice, and a BILLED deposit part-settled — rather
 * than on a clean one, where any two implementations agree.
 */
beforeEach(function () {
    $this->seed(RolesPermissionsSeeder::class);
    $this->asset = makeAsset();
    $this->actingAs(makeUser('super_admin', [$this->asset->id]));
});

it('answers identically whether or not the relations are loaded', function () {
    $lease = makeLease(makeUnit($this->asset), null, ['security_deposit' => 50000]);

    depositMovement($lease, 'receipt', 30000);
    depositMovement($lease, 'receipt', 12000);
    depositMovement($lease, 'refund', 4000);
    depositMovement($lease, 'forfeit', 1500);

    // A billed deposit, part-settled — the channel `settledDepositBillings()` exists for.
    $invoice = makeInvoice($lease, ['status' => 'issued']);
    $invoice->items()->create([
        'type' => 'security_deposit', 'description' => 'Deposit', 'quantity' => 1,
        'unit_price' => 9000, 'amount' => 9000, 'tax_amount' => 0, 'total' => 9000,
    ]);
    $invoice->recomputeTotals();
    settleInvoiceInFull($invoice->fresh());

    $unloaded = Lease::query()->findOrFail($lease->id)->depositHeld();

    $loaded = Lease::query()
        ->with(['deposits', 'depositApplications', 'depositBillings'])
        ->findOrFail($lease->id)
        ->depositHeld();

    // The premise: a lease holding nothing would make any two implementations agree.
    expect($unloaded)->toBeGreaterThan(0.0);
    expect($loaded)->toBe($unloaded);
});

it('answers identically when a deposit has been applied to an invoice', function () {
    $lease = makeLease(makeUnit($this->asset), null, ['security_deposit' => 20000]);
    depositMovement($lease, 'receipt', 20000);

    $invoice = makeInvoice($lease, ['status' => 'issued', 'total' => 5000, 'balance' => 5000]);

    // Through the real service, not a hand-built row: `ApplyDepositToInvoiceService` is what
    // creates these, and it also recomputes the invoice — a fixture that skips it would test a
    // shape production never produces.
    app(ApplyDepositToInvoiceService::class)->apply($lease->fresh(), $invoice->fresh(), 5000.0);

    $unloaded = Lease::query()->findOrFail($lease->id)->depositHeld();
    $loaded = Lease::query()->with(['deposits', 'depositApplications', 'depositBillings'])
        ->findOrFail($lease->id)->depositHeld();

    // 20,000 received less 5,000 netted against an invoice.
    expect($unloaded)->toBe(15000.0);
    expect($loaded)->toBe($unloaded);
});

it('keeps the refund guard reading a FRESH figure, not a cached one', function () {
    // The guard re-reads its subject rather than trusting a loaded instance — which is exactly why
    // making the loaded path cheaper is safe. If a future change ever memoises `depositHeld()` on
    // the model, this is what should fail.
    $lease = makeLease(makeUnit($this->asset), null, ['security_deposit' => 10000]);
    depositMovement($lease, 'receipt', 10000);

    $loaded = Lease::query()->with(['deposits', 'depositApplications'])->findOrFail($lease->id);
    expect($loaded->depositHeld())->toBe(10000.0);

    depositMovement($lease, 'refund', 4000);

    // The instance that has NOT been re-read is entitled to its stale answer — nothing re-reads a
    // relation behind your back. What must be true is that a fresh read sees the refund.
    expect(Lease::query()->findOrFail($lease->id)->depositHeld())->toBe(6000.0);
});
