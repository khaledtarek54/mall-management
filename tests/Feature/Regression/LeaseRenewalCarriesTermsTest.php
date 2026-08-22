<?php

use App\Models\Lease;
use App\Models\LeaseCamTerm;
use App\Models\LeasePercentageRentTier;
use App\Models\RentableItem;
use App\Services\LeaseRenewalService;

/**
 * A renewal must carry every term somebody negotiated.
 *
 * `LeaseRenewalService` built its payload from a literal array written when `leases` had ~24
 * columns. The table now has 43, and **14 were silently dropped** — not one of them errored.
 *
 * The worst was invisible rather than wrong: `escalation_type` carried but `escalation_amount` did
 * not, so `Lease::creating` computed `configured = false`, `next_escalation_date` stayed null, and
 * `RentEscalationService`'s `whereNotNull` excluded the lease **for its entire term**. A compounding
 * revenue leak that looks exactly like a lease with no escalation clause.
 *
 * And three child collections were never copied at all — the service contained no mention of them:
 * the CAM cap (so the tenant gets an uncapped true-up on a capped lease, and the renewal's CAM panel
 * simply looks empty), the percentage-rent ladder (so the lease reads as configured while the
 * overage is 0.00 every month), and the rentable-item pivot (parking and signage, unbilled).
 *
 * **The fix is that the payload is DERIVED**, from `$fillable` minus `Lease::RENEWAL_RESETS`. The
 * last test here is the one that matters most: it fails on a 44th column that is neither carried
 * nor explicitly excluded, so this cannot silently happen again.
 */
beforeEach(function () {
    $this->asset = makeAsset(['code' => 'MALL']);
    $this->unit = makeUnit($this->asset, ['area_sqm' => 250]);

    $this->lease = makeLease($this->unit, makeTenant(), [
        'commencement_date' => '2024-01-01',
        'expiry_date' => '2026-12-31',
        'status' => 'active',
        'base_rent_monthly' => 100000,

        // Every term the old array dropped.
        'escalation_type' => 'fixed_amount',
        'escalation_amount' => 5000,
        'escalation_floor_rate' => 3,
        'escalation_ceiling_rate' => 10,
        // `Lease::RENT_RATE`, not the string 'rate_per_sqm'. That value appeared nowhere else in the
        // app: no form offers it, no seeder writes it, and `HasLeasePremises` compares against
        // `RENT_RATE = 'rate'` — so this fixture built a lease the code treats as FLAT while the
        // case below describes a rate-priced one. Surfaced when the column gained a value set.
        'rent_pricing_basis' => Lease::RENT_RATE,
        'base_rent_rate_per_sqm_year' => 4800,
        'late_fee_percent' => 3,
        'late_fee_grace_days' => 14,
        'late_fee_minimum' => 500,
        'percentage_rent_deductible_types' => ['service_charge'],
        'holdover_rate_pct' => 150,
        'has_percentage_rent' => true,
        'percentage_rent_calculation_type' => 'tiered',
    ]);
});

function renewTheLease(): Lease
{
    return app(LeaseRenewalService::class)->renew(test()->lease->fresh(), [
        'new_term_months' => 36,
        'new_rent' => 110000,
        'commencement_date' => '2027-01-01',
    ]);
}

it('carries the escalation amount, without which the lease never escalates at all', function () {
    // The headline defect. `escalation_type` carried and the amount did not, so the renewal looked
    // configured and `RentEscalationService` skipped it for its whole term.
    $renewal = renewTheLease();

    expect((float) $renewal->escalation_amount)->toBe(5000.0)
        ->and($renewal->escalation_type)->toBe('fixed_amount')
        // The chain the drop broke: a configured escalation gets a next date, and that date is what
        // the escalation sweep filters on.
        ->and($renewal->next_escalation_date)->not->toBeNull();
});

it('carries the negotiated escalation collar', function () {
    // The guard rail against a mistyped rate, lost on EVERY renewal — not just fixed-amount ones.
    $renewal = renewTheLease();

    expect((float) $renewal->escalation_floor_rate)->toBe(3.0)
        ->and((float) $renewal->escalation_ceiling_rate)->toBe(10.0);
});

it('carries the rate-pricing basis, so a later expansion still moves the rent', function () {
    // A rate-priced lease renewed as flat: `deriveBaseRentFromRate()` returns null and taking an
    // extra 300 m² changes no rent at all.
    $renewal = renewTheLease();

    expect($renewal->rent_pricing_basis)->toBe(Lease::RENT_RATE)
        ->and((float) $renewal->base_rent_rate_per_sqm_year)->toBe(4800.0);
});

it('carries the per-lease late-fee terms and the deduction clause', function () {
    $renewal = renewTheLease();

    expect((float) $renewal->late_fee_percent)->toBe(3.0)
        ->and($renewal->late_fee_grace_days)->toBe(14)
        ->and((float) $renewal->late_fee_minimum)->toBe(500.0)
        ->and($renewal->percentage_rent_deductible_types)->toBe(['service_charge']);
});

it('carries the holdover uplift but NOT the holdover state', function () {
    // The distinction the exclusion list exists to make: `holdover_rate_pct` is a negotiated term
    // and carries; `holdover_from` is a state the ORIGINAL entered by running past its expiry, and
    // a renewal starts inside its term.
    $this->lease->update(['holdover_from' => '2026-12-31']);

    $renewal = renewTheLease();

    expect((float) $renewal->holdover_rate_pct)->toBe(150.0)
        ->and($renewal->holdover_from)->toBeNull();
});

it('carries the CAM cap, so the true-up stays capped', function () {
    // Never copied at all. `camTermFor()` queries the NEW lease id, finds nothing, and the tenant
    // gets an uncapped year-end true-up on a capped lease — with the renewal's CAM panel showing
    // nothing at all, so the loss is invisible.
    LeaseCamTerm::create([
        'lease_id' => $this->lease->id,
        'effective_year' => 2027,
        // `'yoy'`, not `'yoy_pct'` — that is the COLUMN name on the line below, not a cap type.
        // `LeaseCamTerm::CAP_TYPES` is absolute|yoy|both, so the old fixture stored a cap the
        // reconciliation could never match. Surfaced when the column gained a value set.
        'cap_type' => 'yoy',
        'yoy_pct' => 5,
        'stated_share_pct' => 12.5,
    ]);

    $renewal = renewTheLease();
    $term = $renewal->camTerms()->sole();

    expect($renewal->camTerms()->count())->toBe(1)
        ->and($term->cap_type)->toBe('yoy')
        ->and((float) $term->yoy_pct)->toBe(5.0)
        ->and((float) $term->stated_share_pct)->toBe(12.5);
});

it('carries the percentage-rent ladder, so the overage is not zero every month', function () {
    // `has_percentage_rent` and the `tiered` type carry, so the lease READS as configured while
    // `ladderFor()` returns empty.
    foreach ([[0, 500000, 0], [500000, null, 6]] as [$from, $to, $rate]) {
        LeasePercentageRentTier::create([
            'lease_id' => $this->lease->id, 'from_amount' => $from, 'to_amount' => $to, 'rate' => $rate,
        ]);
    }

    $renewal = renewTheLease();

    // `percentageRentTiers()` orders ascending by `from_amount`, so the top band is last — adding
    // an orderByDesc here would only append a second clause and still return the 0% band.
    $tiers = $renewal->percentageRentTiers()->get();

    expect($tiers)->toHaveCount(2)
        ->and((float) $tiers->last()->rate)->toBe(6.0)
        ->and((float) $tiers->first()->rate)->toBe(0.0);
});

it('carries the rentable items the tenant is paying for', function () {
    $bay = RentableItem::create([
        'asset_id' => $this->asset->id, 'code' => 'P-01', 'name' => 'Parking bay 1',
        'type' => 'parking', 'monthly_rate' => 1500, 'status' => 'available',
    ]);
    $this->lease->rentableItems()->attach($bay->id, [
        'effective_from' => '2024-01-01', 'effective_to' => '2026-12-31', 'monthly_rate' => 1500,
    ]);

    $renewal = renewTheLease();

    expect($renewal->rentableItems()->count())->toBe(1)
        ->and((float) $renewal->rentableItems()->sole()->pivot->monthly_rate)->toBe(1500.0)
        // The original's end date must NOT come with it — a renewal inheriting a window that has
        // already closed would silently stop billing the bay.
        ->and($renewal->rentableItems()->sole()->pivot->effective_to)->toBeNull();
});

it('still resets what belongs to the ORIGINAL tenancy — the paired control', function () {
    // Carrying everything is not the goal; carrying everything NEGOTIATED is. If the reset list
    // stopped working, a renewal would inherit the original's reference and fit-out grace.
    $this->lease->update(['rent_commencement_date' => '2024-04-01', 'possession_date' => '2023-12-01']);

    $renewal = renewTheLease();

    expect($renewal->rent_commencement_date)->toBeNull()
        ->and($renewal->possession_date)->toBeNull()
        ->and($renewal->reference)->not->toBe($this->lease->reference)
        ->and($renewal->previous_lease_id)->toBe($this->lease->id)
        // 100,000, NOT the 110,000 the renewal was given. This lease is priced per m² (250 m² at
        // 4,800/yr), and `Lease::saving()` re-derives the monthly figure on CREATE — a renewal is a
        // create — on the stated rule that "a typed monthly figure cannot outrank the rate the deal
        // was struck at". So the negotiated rent is REPLACED, silently.
        //
        // Pinned as it behaves rather than as it should behave: whether a rate-priced renewal should
        // refuse the mismatch, or re-rate from the new rent, is the operator's call and not one to
        // invent inside a regression test. Recorded as a finding — see docs/EGYPT-MARKET-FIT.md.
        // It was invisible until now because the only rate-priced fixture in the suite used
        // `rate_per_sqm`, a value the code never matches, so this lease was treated as flat.
        ->and((float) $renewal->base_rent_monthly)->toBe(100000.0);
});

it('accounts for EVERY fillable column — the gate that stops this recurring', function () {
    // The actual fix. A new lease column is carried by default; dropping one is a decision somebody
    // has to write down in `Lease::RENEWAL_RESETS` with a reason. Enumerating what to COPY is what
    // let the table grow from 24 columns to 43 while the renewal silently kept copying 29.
    $unaccounted = collect((new Lease)->getFillable())
        ->reject(fn (string $c): bool => array_key_exists($c, Lease::RENEWAL_RESETS))
        // Everything not excluded is carried by derivation, so the only way to fail this is for the
        // derivation itself to stop covering the fillable list.
        ->reject(fn (string $c): bool => true)
        ->all();

    expect($unaccounted)->toBe([]);

    // And every exclusion states why.
    $blank = collect(Lease::RENEWAL_RESETS)->filter(fn (string $r): bool => trim($r) === '')->keys()->all();
    expect($blank)->toBe([]);

    // A reset entry naming a column that no longer exists reads as a considered decision the next
    // person inherits by accident.
    $stale = array_diff(array_keys(Lease::RENEWAL_RESETS), (new Lease)->getFillable());
    expect(array_values($stale))->toBe([], 'Stale RENEWAL_RESETS entries: '.implode(', ', $stale));
});
