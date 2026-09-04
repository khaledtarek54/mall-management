<?php

use App\Models\DepositTransaction;
use App\Services\DepositService;
use App\Services\SettleMoveOutService;
use Database\Seeders\AccountMappingSeeder;
use Database\Seeders\ChartOfAccountsSeeder;

/**
 * A REFUND OR FORFEIT IS FIXED ONCE THE FINAL ACCOUNT HAS BEEN SETTLED.
 *
 * The receipt freeze (`DepositReceiptFrozenOnceUsedTest`) asks `$wasOrIsReceipt`, so it has never
 * covered the two rows a MOVE-OUT writes — and `ChangeImpact` recorded this model as "already had
 * the freeze, on a BETTER predicate", which was true of receipts only.
 *
 * Measured at HEAD: 100,000 received, lease terminated, `SettleMoveOutService::settle()` with no
 * arrears and no deductions writes a 100,000 refund and `depositHeld()` reads 0. Retyping that
 * refund to 10,000 was ACCEPTED, `depositHeld()` climbed back to 90,000, and a second 90,000 refund
 * against that phantom pot was accepted too — while the first 100,000 has already left the bank. So
 * the register reports 100,000 of refunds against 190,000 actually paid, the ledger entry re-derives
 * to the smaller figure against a bank statement showing the larger one, and the immutable statement
 * the tenant signed still says 100,000.
 *
 * The predicate is the SETTLEMENT and deliberately not `hasBeenDrawnOn()`: a recorded refund IS a
 * draw, so that query finds itself and would freeze every refund at birth — which the first test
 * below is the control for, because `potContributionAsPersisted()` exists precisely to let an
 * un-depended-on refund be corrected.
 *
 * `status` and `notes` stay editable so `cancel_deposit` remains the way out. A refusal with no
 * escape is worse than the bug, and correcting through a named act — which records why and reverses
 * the entry — is the discipline every money document here follows.
 */
beforeEach(function () {
    $this->seed(ChartOfAccountsSeeder::class);
    $this->seed(AccountMappingSeeder::class);

    $this->asset = makeAsset(['code' => 'MVOUT']);
    $this->lease = makeLease(makeUnit($this->asset));

    DepositTransaction::create([
        'lease_id' => $this->lease->id,
        'type' => 'receipt',
        'amount' => 100000,
        'transaction_date' => '2026-01-05',
        'method' => 'bank',
        'status' => 'recorded',
    ]);
});

it('still lets a refund nothing depends on be corrected — the window this must not close', function () {
    // The CONTROL, and the reason the predicate cannot be `hasBeenDrawnOn()`. A refund keyed at the
    // wrong figure on a live tenancy is fixable, exactly as a receipt is until something uses it;
    // `potContributionAsPersisted()` is what makes the over-refund cap measure the correction
    // against the whole 100,000 rather than against the 70,000 left after itself.
    $refund = DepositTransaction::create([
        'lease_id' => $this->lease->id,
        'type' => 'refund',
        'amount' => 30000,
        'transaction_date' => '2026-03-01',
        'method' => 'bank',
        'status' => 'recorded',
    ]);

    $refund->update(['amount' => 40000]);

    expect(round((float) $refund->fresh()->amount, 2))->toEqual(40000.0)
        ->and(round((float) $this->lease->fresh()->depositHeld(), 2))->toEqual(60000.0);
});

it('refuses to retype the refund the settled statement quotes', function () {
    $this->lease->update(['status' => 'terminated']);

    $result = app(SettleMoveOutService::class)->settle($this->lease->fresh(), [
        'settlement_date' => '2026-06-30',
        'deductions' => [],
        'settle_arrears' => false,
        'reason' => 'Move out',
    ]);

    $refund = $result['refund'];
    expect(round((float) $refund->amount, 2))->toEqual(100000.0)
        ->and(round((float) $this->lease->fresh()->depositHeld(), 2))->toEqual(0.0);

    expect(fn () => $refund->fresh()->update(['amount' => 10000]))
        ->toThrow(DomainException::class);

    // The pot did not move — which is the whole point: a second payout cannot be funded from a
    // deposit that was already paid out.
    expect(round((float) DepositTransaction::find($refund->id)->amount, 2))->toEqual(100000.0)
        ->and(round((float) $this->lease->fresh()->depositHeld(), 2))->toEqual(0.0);
});

it('refuses to restate the forfeit either — that is recognised income', function () {
    $this->lease->update(['status' => 'terminated']);

    $result = app(SettleMoveOutService::class)->settle($this->lease->fresh(), [
        'settlement_date' => '2026-06-30',
        'deductions' => [['description' => 'Damage to shopfront', 'amount' => 20000]],
        'settle_arrears' => false,
        'reason' => 'Move out',
    ]);

    expect(round((float) $result['forfeit']->amount, 2))->toEqual(20000.0)
        ->and(round((float) $result['refund']->amount, 2))->toEqual(80000.0);

    expect(fn () => $result['forfeit']->fresh()->update(['amount' => 50000]))
        ->toThrow(DomainException::class);
});

it('refuses to re-point a settled refund at another lease', function () {
    // The other shape of the same restatement, and the one no existing guard caught: the over-refund
    // cap passes, because the row's own persisted contribution is added back and the destination pot
    // is empty. Moving it strips 100,000 out of the settled lease's pot without touching the figure.
    $this->lease->update(['status' => 'terminated']);

    $refund = app(SettleMoveOutService::class)->settle($this->lease->fresh(), [
        'settlement_date' => '2026-06-30',
        'deductions' => [],
        'settle_arrears' => false,
        'reason' => 'Move out',
    ])['refund'];

    $elsewhere = makeLease(makeUnit($this->asset));

    expect(fn () => $refund->fresh()->update(['lease_id' => $elsewhere->id]))
        ->toThrow(DomainException::class);

    expect(DepositTransaction::find($refund->id)->lease_id)->toBe($this->lease->id);
});

it('leaves Cancel as the way out, which is what the refusal names', function () {
    // The ESCAPE. `cancel_deposit` dirties `status` and `notes` only, and neither is frozen — so the
    // operator can undo the movement with a recorded reason and a reversed ledger entry, then record
    // the corrected one. Without this the freeze would be a dead end.
    $this->lease->update(['status' => 'terminated']);

    $refund = app(SettleMoveOutService::class)->settle($this->lease->fresh(), [
        'settlement_date' => '2026-06-30',
        'deductions' => [],
        'settle_arrears' => false,
        'reason' => 'Move out',
    ])['refund'];

    app(DepositService::class)->cancel($refund->fresh(), 'Paid to the wrong account');

    expect(DepositTransaction::find($refund->id)->status)->toBe('cancelled')
        ->and(round((float) $this->lease->fresh()->depositHeld(), 2))->toEqual(100000.0);
});

it('still lets a late movement be RECORDED on a settled lease', function () {
    // The guard is about editing a row the statement quotes, not about closing the lease to new
    // facts: a cheque that lands after settlement is still a real receipt. `$deposit->exists` is
    // what draws that line.
    $this->lease->update(['status' => 'terminated']);

    app(SettleMoveOutService::class)->settle($this->lease->fresh(), [
        'settlement_date' => '2026-06-30',
        'deductions' => [],
        'settle_arrears' => false,
        'reason' => 'Move out',
    ]);

    $late = DepositTransaction::create([
        'lease_id' => $this->lease->id,
        'type' => 'receipt',
        'amount' => 5000,
        'transaction_date' => '2026-07-05',
        'method' => 'bank',
        'status' => 'recorded',
    ]);

    expect($late->exists)->toBeTrue()
        ->and(round((float) $this->lease->fresh()->depositHeld(), 2))->toEqual(5000.0);
});
