<?php

use App\Models\DepositTransaction;
use App\Models\Invoice;
use App\Services\ApplyDepositToInvoiceService;
use App\Services\MoveOutStatementService;
use Database\Seeders\AccountMappingSeeder;
use Database\Seeders\ChartOfAccountsSeeder;

/**
 * A deposit receipt is fixed once the deposit has been drawn on.
 *
 * THE GAP (deposits close-out, 2026-08-11). The module is otherwise the best-built money path in
 * this batch: the held balance is DERIVED (`MoveOutStatementService::depositHeld()` = receipts −
 * refunds − forfeits − applications, `recorded` only), and `ApplyDepositToInvoiceService` locks the
 * invoice and caps at `min(balance, held, requested)`. There is no stored balance to drift.
 *
 * What was missing is the other end. `DepositTransaction` had no immutability guard at all, and its
 * own comment records the intent — *"the edit form leaves lease_id editable while status is
 * 'recorded'"* — which is the layer-3 gate again. A receipt STAYS `recorded` after it has been
 * applied to an invoice (applying writes a `DepositApplication`; it does not change the receipt), so
 * the editable window never closes:
 *
 *   receive 10,000  →  net 8,000 against the tenant's arrears (the fourth AR settlement channel)
 *   edit the receipt down to 2,000
 *   depositHeld = 2,000 − 8,000 = **−6,000**
 *
 * The tenant's AR was settled by money the landlord no longer records receiving, the move-out
 * statement owes them a negative deposit, and the receipt's GL entry (Dr Cash / Cr Deposits Held)
 * re-derives at the new figure while the application's Dr Deposits Held does not move.
 *
 * Frozen only once the deposit has been USED — a receipt keyed at the wrong figure must stay
 * fixable until something depends on it, the same rule as the عهدة in module 25. `notes` stays
 * editable throughout: it carries no money and no dimension.
 */
beforeEach(function () {
    $this->seed(ChartOfAccountsSeeder::class);
    $this->seed(AccountMappingSeeder::class);

    $this->asset = makeAsset(['code' => 'DEP1']);
    $this->lease = makeLease(makeUnit($this->asset));

    $this->receipt = DepositTransaction::create([
        'lease_id' => $this->lease->id,
        'type' => 'receipt',
        'amount' => 10000,
        'transaction_date' => '2026-06-01',
        'method' => 'bank',
        'status' => 'recorded',
    ]);
});

/** An issued invoice with a real balance for the deposit to be netted against. */
function depInvoice(float $total = 8000): Invoice
{
    return makeInvoice(test()->lease, [
        'subtotal' => $total, 'vat_amount' => 0, 'total' => $total,
        'paid_amount' => 0, 'balance' => $total, 'status' => 'issued',
    ]);
}

it('refuses to reduce a receipt the tenant has already been credited against', function () {
    app(ApplyDepositToInvoiceService::class)->apply($this->lease, depInvoice(8000));

    expect(round(app(MoveOutStatementService::class)->depositHeld($this->lease->fresh()), 2))->toBe(2000.0);

    expect(fn () => $this->receipt->fresh()->update(['amount' => 2000]))
        ->toThrow(DomainException::class);

    // The held balance never goes negative — the whole point.
    expect(round(app(MoveOutStatementService::class)->depositHeld($this->lease->fresh()), 2))->toBe(2000.0);
});

it('refuses to re-point a used receipt at another lease', function () {
    // lease_id is what `depositHeld` groups by, so moving it takes the money away from the tenant
    // whose arrears it already settled — and takes asset_id (the books dimension) with it.
    app(ApplyDepositToInvoiceService::class)->apply($this->lease, depInvoice(8000));

    $other = makeLease(makeUnit($this->asset));

    expect(fn () => $this->receipt->fresh()->update(['lease_id' => $other->id]))
        ->toThrow(DomainException::class);
});

it('refuses to move a used receipt\'s date, which is its GL entry date', function () {
    app(ApplyDepositToInvoiceService::class)->apply($this->lease, depInvoice(8000));

    expect(fn () => $this->receipt->fresh()->update(['transaction_date' => '2026-01-01']))
        ->toThrow(DomainException::class);
});

it('still lets an UNUSED receipt be corrected', function () {
    // The control, and a real need: a deposit keyed at the wrong figure must be fixable until
    // something depends on it. Same rule as the عهدة in module 25.
    expect(fn () => $this->receipt->fresh()->update(['amount' => 12000]))
        ->not->toThrow(DomainException::class);

    expect(round(app(MoveOutStatementService::class)->depositHeld($this->lease->fresh()), 2))->toBe(12000.0);
});

it('still allows notes on a used receipt', function () {
    // The other control: notes carry no money and no dimension, so freezing them would stop an
    // operator recording what the deposit turned out to cover.
    app(ApplyDepositToInvoiceService::class)->apply($this->lease, depInvoice(8000));

    expect(fn () => $this->receipt->fresh()->update(['notes' => 'Netted against June arrears']))
        ->not->toThrow(DomainException::class);
});

it('treats a refund as a use too, so the receipt behind it cannot shrink', function () {
    // A refund is drawn from the receipt just as an application is; reducing the receipt after
    // refunding leaves the landlord recording that it paid out more than it took in.
    DepositTransaction::create([
        'lease_id' => $this->lease->id,
        'type' => 'refund', 'amount' => 4000,
        'transaction_date' => '2026-07-01', 'method' => 'bank', 'status' => 'recorded',
    ]);

    expect(fn () => $this->receipt->fresh()->update(['amount' => 1000]))
        ->toThrow(DomainException::class);
});

it('refuses to turn a used receipt into a refund — the door the freeze read past', function () {
    // **SW-017.** The guard asked `$deposit->type === 'receipt'`, which is the value being SAVED. So
    // flipping the type walked straight past a freeze whose own dirty list names `type` — and it is
    // the worst way through, because the row stops being a receipt AND takes its money out of the
    // pot at the same time.
    // **A SECOND receipt, so the pot stays positive after the flip.** Without it the over-refund cap
    // refuses first and this case proves nothing about the freeze — the trap this codebase records
    // for a lock spy seeing another service's lock. With 50,000 also on the pot, flipping the drawn-
    // on 10,000 receipt to a refund leaves 50,000 − 10,000 − 8,000 = 32,000, which the cap is
    // perfectly happy with. Only the freeze can refuse it.
    DepositTransaction::create([
        'lease_id' => $this->lease->id,
        'tenant_id' => $this->lease->tenant_id,
        'asset_id' => $this->lease->unit?->asset_id,
        'type' => 'receipt',
        'amount' => 50000,
        'transaction_date' => now()->toDateString(),
        'status' => 'recorded',
    ]);

    app(ApplyDepositToInvoiceService::class)->apply($this->lease, depInvoice(8000));

    expect(round(app(MoveOutStatementService::class)->depositHeld($this->lease->fresh()), 2))->toBe(52000.0);

    // Measured before: accepted, and the pot fell by the receipt twice over — the money left it as a
    // receipt and left again as a refund, against arrears already settled with it.
    expect(fn () => $this->receipt->fresh()->update(['type' => 'refund']))
        ->toThrow(DomainException::class);

    expect($this->receipt->fresh()->type)->toBe('receipt')
        ->and(round(app(MoveOutStatementService::class)->depositHeld($this->lease->fresh()), 2))->toBe(52000.0);
});

it('refuses the other direction too, while the pot is depended on', function () {
    // Either way round changes what the pot is made of while something already rests on it. Asked of
    // what the row WAS or IS, so neither edit can slip through on the value the other side reads.
    $refund = DepositTransaction::create([
        'lease_id' => $this->lease->id,
        'tenant_id' => $this->lease->tenant_id,
        'asset_id' => $this->lease->unit?->asset_id,
        'type' => 'refund',
        'amount' => 500,
        'transaction_date' => now()->toDateString(),
        'status' => 'recorded',
    ]);

    app(ApplyDepositToInvoiceService::class)->apply($this->lease, depInvoice(8000));

    expect(fn () => $refund->fresh()->update(['type' => 'receipt']))
        ->toThrow(DomainException::class);
});
