<?php

use App\Enums\PartyType;
use App\Models\Charge;
use App\Models\CreditNote;
use App\Models\UnitOwnership;
use App\Services\BillUnitOwnershipsService;
use App\Services\TransferUnitOwnershipService;
use Carbon\CarbonImmutable;

/**
 * A unit sold mid-month is billed exactly one month of assessment, split between the two owners.
 *
 * **F-02, pre-staging QA 2026-08-19.** `BillUnitOwnershipsService::billOne()` prorates on tenure and
 * its docblock claimed "a resale on the 10th bills the seller 10/30 and the buyer the rest". That is
 * only true if the month is billed AFTER the transfer is recorded. In the real sequence — the
 * scheduled run raises the assessment on the 1st, the sale completes on the 11th — measured:
 *
 *   - the seller stood billed 3,000.00 for a month they owned 10 of 31 days of (967.74 owed);
 *   - nothing corrected it, ever: `UnitOwnershipStatus::Transferred` is not `isBillable()`, so a
 *     re-run skips the seller by design;
 *   - the buyer was billed NOTHING, then or later — the transfer carried the terms but not the
 *     assessment schedule, so the buyer had nothing to bill for the rest of their holding either.
 *
 * The lease side has had `CreditUnearnedBillingService` for this since MF-02. The ownership side had
 * no equivalent, and that asymmetry is the whole defect.
 *
 * The credit deliberately reuses the lease instrument rather than a second implementation: a
 * mid-month move-out and a mid-month resale must give back the same amount for the same shape of
 * month, which two day-count implementations would not.
 */
beforeEach(function () {
    seedRoles();

    $this->asset = makeAsset();
    $this->unit = makeUnit($this->asset, ['code' => 'C-91']);
    $this->seller = makeTenant(['party_type' => PartyType::UnitOwner->value]);
    $this->buyer = makeTenant(['party_type' => PartyType::UnitOwner->value]);

    $this->ownership = UnitOwnership::create([
        'asset_id' => $this->asset->id,
        'unit_id' => $this->unit->id,
        'tenant_id' => $this->seller->id,
        'tenure_type' => 'freehold',
        'status' => 'handed_over',
        'assessment_basis' => 'stated',
        'ownership_share_pct' => 100,
        'started_at' => '2026-01-01',
        'handover_date' => '2026-01-01',
        'payment_terms_days' => 15,
        'currency' => 'EGP',
    ]);

    Charge::create([
        'unit_ownership_id' => $this->ownership->id,
        'name' => 'صيانة',
        'type' => 'service_charge',
        'amount' => 3000,
        'currency' => 'EGP',
        'frequency' => 'monthly',
        'vat_applicable' => false,
        'vat_rate' => 0,
        'start_date' => '2026-01-01',
        'is_active' => true,
    ]);

    // October is billed to the seller on the 1st, as the scheduled run does.
    $this->october = CarbonImmutable::parse('2026-10-01');
    $this->octoberInvoice = app(BillUnitOwnershipsService::class)
        ->billOne($this->ownership->fresh(), $this->october, $this->october->endOfMonth());

    expect((float) $this->octoberInvoice->total)->toBe(3000.0);
});

it('credits the seller the days they did not own', function () {
    app(TransferUnitOwnershipService::class)->transfer(
        $this->ownership->fresh(),
        $this->buyer,
        CarbonImmutable::parse('2026-10-11'),
        allowOutstanding: true,
    );

    $notes = CreditNote::where('invoice_id', $this->octoberInvoice->id)->get();

    // 1–10 October inclusive is what they owned; 11–31 is what comes back.
    $owed = round(3000 * 10 / 31, 2);

    expect($notes)->toHaveCount(1)
        ->and(round((float) $notes->sum('total'), 2))->toBe(round(3000 - $owed, 2))
        // Applied to its own invoice, so the receivable now states what is actually owed.
        ->and(round((float) $this->octoberInvoice->fresh()->balance, 2))->toBe($owed);
});

it('carries the assessment schedule to the buyer and bills their part of the month', function () {
    $result = app(TransferUnitOwnershipService::class)->transfer(
        $this->ownership->fresh(),
        $this->buyer,
        CarbonImmutable::parse('2026-10-11'),
        allowOutstanding: true,
    );

    $buyer = $result['buyer'];

    expect($buyer->charges()->where('is_active', true)->count())->toBe(1)
        ->and($buyer->charges()->first()->start_date->toDateString())->toBe('2026-10-11')
        // The seller's row is CLOSED on their last owned day, not deleted — the months it billed
        // are part of their account and every assessment points at it.
        ->and($result['seller']->charges()->first()->end_date->toDateString())->toBe('2026-10-10');

    $buyerInvoice = app(BillUnitOwnershipsService::class)
        ->billOne($buyer->fresh(), $this->october, $this->october->endOfMonth());

    expect($buyerInvoice)->not->toBeNull()
        ->and(round((float) $buyerInvoice->subtotal, 2))->toBe(round(3000 * 21 / 31, 2));
});

it('bills the unit exactly one month of assessment across the two owners', function () {
    $result = app(TransferUnitOwnershipService::class)->transfer(
        $this->ownership->fresh(),
        $this->buyer,
        CarbonImmutable::parse('2026-10-11'),
        allowOutstanding: true,
    );

    $credited = (float) CreditNote::where('invoice_id', $this->octoberInvoice->id)->sum('total');
    $sellerNet = round((float) $this->octoberInvoice->total - $credited, 2);

    $buyerInvoice = app(BillUnitOwnershipsService::class)
        ->billOne($result['buyer']->fresh(), $this->october, $this->october->endOfMonth());

    // The property the whole fix exists for: one unit, one month, one assessment — however the
    // days fall between the two holdings.
    expect(round($sellerNet + (float) $buyerInvoice->subtotal, 2))->toBe(3000.0);
});

it('does not carry a one-off charge onto the buyer', function () {
    // A one-off was an event on the seller's holding — a special levy, a fit-out contribution.
    // Re-opening it on the buyer would bill the same one-off twice for one unit.
    Charge::create([
        'unit_ownership_id' => $this->ownership->id,
        'name' => 'Special levy',
        'type' => 'other',
        'amount' => 5000,
        'currency' => 'EGP',
        'frequency' => 'one_time',
        'vat_applicable' => false,
        'vat_rate' => 0,
        'start_date' => '2026-03-01',
        'is_active' => true,
    ]);

    $result = app(TransferUnitOwnershipService::class)->transfer(
        $this->ownership->fresh(),
        $this->buyer,
        CarbonImmutable::parse('2026-10-11'),
        allowOutstanding: true,
    );

    expect($result['buyer']->charges()->pluck('frequency')->all())->toBe(['monthly']);
});
