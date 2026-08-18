<?php

/*
|--------------------------------------------------------------------------
| A deposit nobody can see is a deposit nobody collects (2026-08-18)
|--------------------------------------------------------------------------
| Raised by an operator: "the client doesn't know how he should pay, and the admin doesn't know how
| much the lease wants or the shortfall." Both were true, for three separate reasons:
|
|   · `leases.security_deposit_received` was a SECOND TRUTH — a form toggle, defaulted false, that
|     NOTHING ever synced from the deposit register. A lease with 240,000 recorded still read "not
|     received", and a boolean cannot express a PARTLY collected deposit at all, which is the
|     ordinary case. Dropped; the register is the answer.
|   · the lease LIST showed no deposit at all, so "who still owes me a deposit?" meant opening every
|     lease in turn. On the seeded portfolio one active lease had 144,000 agreed and nothing held.
|   · the tenant PORTAL showed the CONTRACTED figure alone — not what they had paid, not what was
|     outstanding, and no instruction. A deposit is never invoiced, so nothing else in the portal
|     was ever going to ask them for it.
|
| `Lease::depositHeld()` / `depositShortfall()` are the one definition, so the list, the lease page,
| the portal and the move-out statement cannot disagree about the same money.
*/

use App\Models\DepositApplication;
use App\Models\DepositTransaction;
use App\Models\Lease;
use App\Services\MoveOutStatementService;
use Illuminate\Support\Facades\Schema;

beforeEach(function () {
    ensureAllPropertiesAsset();
    $this->asset = makeAsset();
    $this->tenant = makeTenant();
});

function depositLeaseFor($ctx, float $agreed): Lease
{
    return makeLease(makeUnit($ctx->asset), $ctx->tenant, [
        'status' => 'active',
        'security_deposit' => $agreed,
        'commencement_date' => '2026-01-01',
        'expiry_date' => '2028-12-31',
    ]);
}

function depositMovement(Lease $lease, string $type, float $amount, string $status = 'recorded'): DepositTransaction
{
    return DepositTransaction::create([
        'lease_id' => $lease->id,
        'tenant_id' => $lease->tenant_id,
        'asset_id' => $lease->unit->asset_id,
        'type' => $type,
        'status' => $status,
        'amount' => $amount,
        'transaction_date' => '2026-01-05',
    ]);
}

it('states the shortfall on a PARTLY collected deposit — the case a boolean could not express', function () {
    $lease = depositLeaseFor($this, 180000);
    depositMovement($lease, 'receipt', 150000);

    expect($lease->depositHeld())->toBe(150000.0)
        ->and($lease->depositShortfall())->toBe(30000.0);
});

it('counts a lease with nothing received as fully outstanding', function () {
    $lease = depositLeaseFor($this, 144000);

    // The real finding on the seeded portfolio: an active lease, trading, no deposit ever taken.
    expect($lease->depositHeld())->toBe(0.0)
        ->and($lease->depositShortfall())->toBe(144000.0);
});

it('never reports a negative shortfall when more was taken than agreed', function () {
    $lease = depositLeaseFor($this, 100000);
    depositMovement($lease, 'receipt', 120000);

    expect($lease->depositShortfall())->toBe(0.0);
});

it('subtracts refunds, forfeits and what was netted against arrears', function () {
    $lease = depositLeaseFor($this, 200000);
    depositMovement($lease, 'receipt', 200000);
    depositMovement($lease, 'refund', 30000);
    depositMovement($lease, 'forfeit', 20000);

    DepositApplication::create([
        'lease_id' => $lease->id,
        'tenant_id' => $lease->tenant_id,
        'invoice_id' => makeInvoice($lease)->id,
        'amount' => 50000,
        'applied_at' => now(),
        'entry_date' => '2026-02-01',
    ]);

    // Leaving the application out is what lets one deposit settle the arrears AND be refunded whole.
    expect($lease->fresh()->depositHeld())->toBe(100000.0)
        ->and($lease->fresh()->depositShortfall())->toBe(100000.0);
});

it('ignores a movement that was never recorded', function () {
    $lease = depositLeaseFor($this, 100000);
    depositMovement($lease, 'receipt', 100000, status: 'cancelled');

    // Settling against intentions is how a landlord refunds money it never received.
    expect($lease->depositHeld())->toBe(0.0);
});

it('gives the move-out statement and the lease list ONE answer, not two', function () {
    $lease = depositLeaseFor($this, 180000);
    depositMovement($lease, 'receipt', 150000);

    // The calculation moved onto the model; the service delegates. This is what stops the final
    // account and the list disagreeing about the same deposit.
    expect(app(MoveOutStatementService::class)->depositHeld($lease))->toBe($lease->depositHeld());
});

it('no longer carries the unmaintained received flag', function () {
    // Two answers to "has the deposit been paid?", one of them a guess somebody typed months ago.
    expect(Schema::hasColumn('leases', 'security_deposit_received'))->toBeFalse();
});
