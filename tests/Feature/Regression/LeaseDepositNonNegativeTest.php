<?php

/**
 * A lease's security deposit cannot be negative.
 *
 * Enforced at layer 3 only — `minValue(0)` on the lease form, nothing behind it (validation sweep,
 * leasing, 2026-08-11).
 *
 * Stated honestly, because the severity matters: `security_deposit` is the CONTRACTUAL figure. The
 * money that actually moves comes from `deposit_transactions`, and `MoveOutStatementService::for()`
 * computes the refund from `depositHeld()`, not from this column — so a negative one cannot mis-pay
 * anybody. What it can do is print a nonsense "contractual deposit" line on the final account the
 * operator hands the tenant at move-out. That is worth one guard and no more than that.
 *
 * Refused rather than clamped: silently turning -5,000 into 0 hides the typo instead of reporting
 * it, and the operator would never learn the deal terms they entered were wrong.
 */
beforeEach(function () {
    $this->lease = makeLease(makeUnit(makeAsset()));
});

it('refuses a negative security deposit', function () {
    expect(fn () => $this->lease->update(['security_deposit' => -5000]))
        ->toThrow(DomainException::class);

    expect((float) $this->lease->fresh()->security_deposit)->not->toBeLessThan(0.0);
});

it('allows zero — a deposit-free deal is a real deal', function () {
    expect(fn () => $this->lease->update(['security_deposit' => 0]))->not->toThrow(DomainException::class);
});

it('allows an ordinary positive deposit', function () {
    // The control: without it the refusal above passes just as happily if every save threw.
    $this->lease->update(['security_deposit' => 30000]);

    expect((float) $this->lease->fresh()->security_deposit)->toBe(30000.0);
});
