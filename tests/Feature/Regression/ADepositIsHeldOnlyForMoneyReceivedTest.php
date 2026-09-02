<?php

use App\Models\CreditNote;
use App\Models\Lease;
use App\Models\Payment;
use App\Services\CreditNoteService;
use App\Services\WriteOffInvoiceService;
use App\Support\DepositHoldings;
use Database\Seeders\AccountMappingSeeder;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\RolesPermissionsSeeder;

/**
 * A DEPOSIT IS HELD FOR MONEY RECEIVED — NOT FOR AN INVOICE STATUS.
 *
 * Four questions were answered from `invoices.status`, and status is a coarse proxy for what is an
 * AMOUNT question: the figures underneath come from `InvoiceItemSettlement`, which derives every
 * per-line number from `paid_amount`. So the status list caught the terminal cases and missed every
 * partial one, in both directions and both of them money. All four are asserted here.
 *
 * A WRITE-OFF FORGIVES WHAT WAS NOT PAID; IT DOES NOT UN-PAY WHAT WAS.
 *
 * `Lease::depositHeld()` counts a BILLED deposit only to the extent the tenant settled the line —
 * Voyager's model, and the right one, because an unpaid deposit invoice is a receivable and not
 * money in the bank. It read that through ONE status exclusion list which also served the opposite
 * question (*"what have we already asked for?"*), and that list carried `written_off`.
 *
 * So: a deposit invoice for 100,000, the tenant pays 60,000, the operator writes off the
 * uncollectable 40,000 as bad debt — an ordinary, correct act. The invoice's status becomes
 * `written_off`, it drops out of `depositBillings` **entirely**, and the 60,000 the tenant really
 * paid vanishes from the pot. The lease reads 0.00 held, the move-out statement refunds nothing,
 * and the mall keeps 60,000 of somebody's security deposit. Nothing on any screen says so: the
 * write-off is right, the invoice is right, and the only wrong figure is the one derived from both.
 *
 * `written_off` still belongs in the CLAIM list, which is why there are now two: a forgiven amount
 * will never arrive, so counting it as already-billed would leave the shortfall permanently
 * un-re-billable. Both halves are asserted here, because fixing one by breaking the other is the
 * shape this codebase keeps finding.
 */
beforeEach(function () {
    $this->seed(ChartOfAccountsSeeder::class);
    $this->seed(AccountMappingSeeder::class);
    $this->seed(RolesPermissionsSeeder::class);

    $this->asset = makeAsset();
    $this->actingAs(makeUser('super_admin', [$this->asset->id]));

    $this->lease = makeLease(makeUnit($this->asset), null, ['security_deposit' => 100000]);

    $this->invoice = makeInvoice($this->lease, [
        'status' => 'issued', 'subtotal' => 100000, 'vat_amount' => 0,
        'total' => 100000, 'balance' => 100000,
    ]);
    $this->invoice->items()->create([
        'type' => 'security_deposit', 'description' => 'Security deposit', 'quantity' => 1,
        'unit_price' => 100000, 'amount' => 100000, 'tax_amount' => 0, 'total' => 100000,
    ]);
    $this->invoice->recomputeTotals();

    // 60,000 of real money arrives.
    $payment = Payment::create([
        'tenant_id' => $this->invoice->tenant_id,
        'payment_date' => now(),
        'amount' => 60000,
        'method' => 'bank_transfer',
        'status' => 'captured',
        'currency' => 'EGP',
    ]);
    $payment->invoices()->attach($this->invoice->id, ['allocated_amount' => 60000]);
    $this->invoice->recomputeTotals();
});

it('keeps the paid portion in the pot after the rest is written off', function () {
    // The control: before the write-off the pot is the 60,000 that arrived.
    expect(Lease::query()->findOrFail($this->lease->id)->depositHeld())->toEqual(60000.0);

    app(WriteOffInvoiceService::class)->write($this->invoice->fresh(), [
        'amount' => 40000,
        'reason' => 'bad_debt',
        'entry_date' => now()->toDateString(),
    ]);

    expect($this->invoice->fresh()->status)->toBe('written_off');

    // All three reads of the pot — the display one, the eager-loaded one and the locking twin a
    // refund is actually written from — must still see the tenant's money.
    $fresh = Lease::query()->findOrFail($this->lease->id);
    $loaded = Lease::query()
        ->with(['deposits', 'depositApplications', 'depositBillings'])
        ->findOrFail($this->lease->id);

    expect($fresh->depositHeld())->toEqual(60000.0)
        ->and($loaded->depositHeld())->toEqual(60000.0)
        ->and(Lease::query()->findOrFail($this->lease->id)->depositHeldForUpdate())->toEqual(60000.0);
});

it('stops counting the forgiven part as already asked for', function () {
    // The other half. 40,000 is short and it will never arrive on that invoice, so the operator
    // must be able to raise a fresh deposit invoice for it. Both paths, loaded and not.
    app(WriteOffInvoiceService::class)->write($this->invoice->fresh(), [
        'amount' => 40000,
        'reason' => 'bad_debt',
        'entry_date' => now()->toDateString(),
    ]);

    $fresh = Lease::query()->findOrFail($this->lease->id);
    $loaded = Lease::query()
        ->with(['deposits', 'depositApplications', 'depositBillings'])
        ->findOrFail($this->lease->id);

    expect($fresh->depositBilledOutstanding())->toEqual(0.0)
        ->and($loaded->depositBilledOutstanding())->toEqual(0.0)
        ->and($fresh->depositShortfall())->toEqual(40000.0)
        ->and($fresh->depositUnbilledShortfall())->toEqual(40000.0)
        ->and($loaded->depositUnbilledShortfall())->toEqual(40000.0)
        ->and(Lease::query()->findOrFail($this->lease->id)->depositUnbilledShortfallForUpdate())
        ->toEqual(40000.0);
});

it('still treats an open deposit invoice as already asked for', function () {
    // The control for the control: with no write-off, the unpaid 40,000 IS on an open invoice and
    // must not be billed a second time. A fix that simply stopped counting claims would satisfy the
    // test above and re-open the double-billing `BillSecurityDepositService` exists to prevent.
    $fresh = Lease::query()->findOrFail($this->lease->id);

    $loaded = Lease::query()
        ->with(['deposits', 'depositApplications', 'depositBillings'])
        ->findOrFail($this->lease->id);

    // BOTH paths: the proved mutation was the under-filtering direction, and a loaded branch that
    // returned nothing would satisfy every refusal above while silently re-opening the double ask.
    expect($fresh->depositBilledOutstanding())->toEqual(40000.0)
        ->and($loaded->depositBilledOutstanding())->toEqual(40000.0)
        ->and($fresh->depositUnbilledShortfall())->toEqual(0.0)
        ->and($loaded->depositUnbilledShortfall())->toEqual(0.0);
});

it('keeps the portfolio register in step with the lease page', function () {
    // **The aggregate is the sum of the parts, and for one commit it was not.** The status filter
    // was written FOUR times — a constant on `Lease`, two literals in `DepositHoldings` — so fixing
    // the model alone left the widget above the deposit register saying "held 0.00" and a red GL-gap
    // stat, while the lease page beside it said 60,000. Both read one seam now, and this is the
    // assertion that says so.
    app(WriteOffInvoiceService::class)->write($this->invoice->fresh(), [
        'amount' => 40000, 'reason' => 'bad_debt', 'entry_date' => now()->toDateString(),
    ]);

    $lease = Lease::query()->findOrFail($this->lease->id);

    expect(DepositHoldings::billedAndSettled())->toEqual($lease->settledDepositBillings())
        ->and(DepositHoldings::billedAndSettled())->toEqual(60000.0)
        ->and(DepositHoldings::held())->toEqual($lease->depositHeld())
        // …and the forgiven part stops being reported as a deposit still in flight.
        ->and(DepositHoldings::billedAndOutstanding())->toEqual(0.0);
});

it('does not count a CREDIT NOTE as deposit money received', function () {
    // The mirror of the bug, one channel over, and the worse direction: `credit_applied_amount` is
    // settlement channel two and feeds `paid_amount`, so a PARTIAL credit note left the invoice
    // `paid` — never `credited` — and the pot read the full 100,000 with only 60,000 of cash in it.
    // A move-out then refunded 40,000 the mall never received, outbound, with no recovery path.
    $note = CreditNote::create([
        'number' => 'CN-'.uniqid(),
        'tenant_id' => $this->invoice->tenant_id,
        'asset_id' => $this->asset->id,
        'status' => 'issued',
        'issue_date' => now(),
        'reason' => 'adjustment',
        'subtotal' => 40000, 'vat_amount' => 0, 'total' => 40000,
        'applied_amount' => 0, 'balance' => 40000, 'currency' => 'EGP',
    ]);

    app(CreditNoteService::class)->applyToInvoice($note, $this->invoice->fresh());

    $invoice = $this->invoice->fresh();

    // The invoice really is settled in full, and really is still `paid` — no status says otherwise.
    expect((float) $invoice->credit_applied_amount)->toEqual(40000.0)
        ->and((float) $invoice->balance)->toEqual(0.0)
        ->and($invoice->status)->toBe('paid');

    $lease = Lease::query()->findOrFail($this->lease->id);
    $loaded = Lease::query()
        ->with(['deposits', 'depositApplications', 'depositBillings'])
        ->findOrFail($this->lease->id);

    expect($lease->depositHeld())->toEqual(60000.0)
        ->and($loaded->depositHeld())->toEqual(60000.0)
        ->and(Lease::query()->findOrFail($this->lease->id)->depositHeldForUpdate())->toEqual(60000.0)
        ->and(DepositHoldings::billedAndSettled())->toEqual(60000.0)
        // …and the 40,000 the landlord gave up shows as a shortfall an operator can see and chase,
        // which is the direction `InvoiceItemSettlement::TYPE_PRIORITY` already chose for deposits.
        ->and($lease->depositShortfall())->toEqual(40000.0);
});

it('lets a PARTIALLY written-off deposit be asked for again', function () {
    // `WriteOffInvoiceService` retires an invoice only when the write-off clears the remainder, so a
    // PARTIAL one changes no status and a status filter can never see it. Measured before the fix:
    // deposit 100,000, paid 60,000, 10,000 forgiven, the collectable 30,000 then paid — and
    // `depositBilledOutstanding()` still reported the forgiven 10,000 as already asked for. The
    // *Bill deposit* button stayed hidden and the service refused with "already billed 10,000.00",
    // quoting money the operator themselves had forgiven. No path existed to ask for it again.
    app(WriteOffInvoiceService::class)->write($this->invoice->fresh(), [
        'amount' => 10000, 'reason' => 'bad_debt', 'entry_date' => now()->toDateString(),
    ]);

    $payment = Payment::create([
        'tenant_id' => $this->invoice->tenant_id,
        'payment_date' => now(), 'amount' => 30000,
        'method' => 'bank_transfer', 'status' => 'captured', 'currency' => 'EGP',
    ]);
    $payment->invoices()->attach($this->invoice->id, ['allocated_amount' => 30000]);
    $this->invoice->fresh()->recomputeTotals();

    $lease = Lease::query()->findOrFail($this->lease->id);
    $loaded = Lease::query()
        ->with(['deposits', 'depositApplications', 'depositBillings'])
        ->findOrFail($this->lease->id);

    expect($this->invoice->fresh()->status)->not->toBe('written_off')   // still live, still partial
        ->and($lease->depositHeld())->toEqual(90000.0)
        ->and($lease->depositShortfall())->toEqual(10000.0)
        ->and($lease->depositBilledOutstanding())->toEqual(0.0)
        ->and($loaded->depositBilledOutstanding())->toEqual(0.0)
        // …so the operator can raise a fresh invoice for the part they forgave.
        ->and($lease->depositUnbilledShortfall())->toEqual(10000.0)
        ->and($loaded->depositUnbilledShortfall())->toEqual(10000.0);
});
