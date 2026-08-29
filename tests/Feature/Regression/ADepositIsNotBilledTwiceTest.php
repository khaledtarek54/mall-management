<?php

declare(strict_types=1);

use App\Models\Charge;
use App\Models\Lease;
use App\Services\BillSecurityDepositService;

/**
 * A DEPOSIT ALREADY ON AN OPEN INVOICE MUST NOT BE ASKED FOR AGAIN.
 *
 * `BillSecurityDepositService` gated on `depositShortfall()`, which is `agreed − held` — and an
 * unpaid deposit invoice is a receivable, not money in the bank, so it correctly stays in the
 * shortfall. That makes it the right answer to *"are we short?"* and the wrong one to *"should we
 * ask again?"*.
 *
 * Measured on the demo books: lease #3 carried an open 164,999.91 deposit invoice, the modal
 * reported "held 0.00 of 164,999.91" — true, and read as "nobody has asked for this yet" — and
 * pressing the button produced a SECOND invoice for the same deposit. The tenant then owes
 * 329,999.82 of security and the GL credits `deposits_held` twice. That is exactly the outcome the
 * service's own docblock says it exists to prevent (*"no double count, and no second billing
 * path"*), one step earlier in the flow than the guard it wrote.
 *
 * `depositUnbilledShortfall()` is the second question. The PAID path was already correct and the
 * control tests keep it that way: settling the invoice raises `depositHeld()` and closes the
 * shortfall, so the two together say the deposit is asked for exactly once.
 */
/**
 * A lease whose deposit has been agreed and NOT yet received — deliberately not the existing
 * `depositLease()` in DepositAppliedToArTest, which records the receipt as part of its setup and
 * therefore cannot express the case this file is about. A distinct name, because two test files
 * declaring one file-scope helper is a FATAL redeclaration that `--parallel` hides.
 */
function leaseAwaitingItsDeposit(float $deposit): Lease
{
    $lease = Lease::factory()->create([
        'status' => 'active',
        'commencement_date' => now()->subMonths(3)->startOfMonth(),
        'expiry_date' => now()->addYear()->endOfMonth(),
        'base_rent_monthly' => 50_000,
        'security_deposit' => $deposit,
        'escalation_type' => 'none',
    ]);

    Charge::create([
        'lease_id' => $lease->id,
        'name' => 'Base Rent',
        'type' => 'base_rent',
        'amount' => 50_000,
        'currency' => 'EGP',
        'frequency' => 'monthly',
        'start_date' => $lease->commencement_date,
        'is_active' => true,
    ]);

    return $lease->fresh();
}

it('bills a deposit that has never been asked for', function (): void {
    $lease = leaseAwaitingItsDeposit(150_000);

    expect($lease->depositUnbilledShortfall())->toBe(150_000.0);

    $invoice = app(BillSecurityDepositService::class)->bill($lease);

    expect((float) $invoice->total)->toBe(150_000.0);
});

it('refuses a second invoice while the first is still open', function (): void {
    $lease = leaseAwaitingItsDeposit(150_000);
    app(BillSecurityDepositService::class)->bill($lease);

    $lease = $lease->fresh();

    // Still short — the money has not arrived — but nothing left to ASK for.
    expect($lease->depositShortfall())->toBe(150_000.0)
        ->and($lease->depositBilledOutstanding())->toBe(150_000.0)
        ->and($lease->depositUnbilledShortfall())->toBe(0.0);

    expect(fn () => app(BillSecurityDepositService::class)->bill($lease))
        ->toThrow(DomainException::class);
});

it('bills only the part that was never asked for', function (): void {
    $lease = leaseAwaitingItsDeposit(150_000);
    app(BillSecurityDepositService::class)->bill($lease, ['amount' => 60_000]);

    $lease = $lease->fresh();

    expect($lease->depositUnbilledShortfall())->toBe(90_000.0);

    $second = app(BillSecurityDepositService::class)->bill($lease);

    expect((float) $second->total)->toBe(90_000.0);
});

it('lets a CANCELLED deposit invoice be re-billed', function (): void {
    $lease = leaseAwaitingItsDeposit(150_000);
    $invoice = app(BillSecurityDepositService::class)->bill($lease);

    // A cancelled invoice claims nothing — the same rule `settledDepositBillings()` applies, so a
    // deposit invoice raised in error does not lock the lease out of ever billing its deposit.
    $invoice->update(['status' => 'cancelled', 'balance' => 0]);

    expect($lease->fresh()->depositUnbilledShortfall())->toBe(150_000.0);
});
