<?php

use App\Models\DepositTransaction;

/**
 * Regression (deposit-phase review):
 *  1. tenant_id/asset_id were derived only in the `creating` hook, so re-pointing a
 *     recorded deposit to a different lease left stale dimensions → the GL attributed
 *     the deposit to the wrong property/tenant. Derivation now runs on every save.
 *  2. the number helper used a bare max+1 with no unique-retry (race-prone), unlike
 *     the hardened Invoice/Payment helpers. It now bumps the suffix until free.
 */
it('re-derives tenant + asset when the lease is changed on an existing deposit', function () {
    $leaseA = makeLease(makeUnit(makeAsset()));
    $leaseB = makeLease(makeUnit(makeAsset()));

    $deposit = DepositTransaction::create([
        'lease_id' => $leaseA->id, 'type' => 'receipt', 'amount' => 5000,
        'transaction_date' => now()->toDateString(), 'method' => 'bank', 'status' => 'recorded',
    ]);
    expect($deposit->tenant_id)->toBe($leaseA->tenant_id);
    expect($deposit->asset_id)->toBe($leaseA->unit->asset_id);

    $deposit->update(['lease_id' => $leaseB->id]);
    $deposit->refresh();

    expect($deposit->tenant_id)->toBe($leaseB->tenant_id);
    expect($deposit->asset_id)->toBe($leaseB->unit->asset_id);
    // the two leases genuinely differ, so this is a real re-derivation
    expect($leaseB->tenant_id)->not->toBe($leaseA->tenant_id);
});

it('bumps the deposit number past an existing collision (unique-retry)', function () {
    $lease = makeLease(makeUnit(makeAsset()));
    $existing = DepositTransaction::create([
        'lease_id' => $lease->id, 'type' => 'receipt', 'amount' => 1000,
        'transaction_date' => now()->toDateString(), 'method' => 'bank', 'status' => 'recorded',
    ]);

    // Force the raw generator to hand back an already-taken number (simulating the
    // read-max-then-insert race window); the unique wrapper must skip past it.
    $probe = new class extends DepositTransaction
    {
        protected $table = 'deposit_transactions';

        public static ?string $forced = null;

        public static function generateNumber(string $assetCode = 'GEN', ?DateTimeInterface $date = null): string
        {
            return self::$forced;
        }

        public static function unique(string $code): string
        {
            return self::generateUniqueNumber($code);
        }
    };
    $probe::$forced = $existing->number;

    expect($probe::unique('AW'))->not->toBe($existing->number);
});
