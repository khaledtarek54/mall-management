<?php

/*
|--------------------------------------------------------------------------
| Lease lifecycle scenarios — creation → renewal → termination/expiry.
|--------------------------------------------------------------------------
| NET-NEW relative to the per-service unit tests (LeaseCreationServiceTest,
| LeaseRenewalServiceTest, LeaseTerminationServiceTest, LeaseRentChangeServiceTest)
| and the MultiUnitLease scenario suites. These exercise the END-TO-END graph:
| charges + VAT flags + unit-status projection + previous_lease_id links +
| escalation-driven rent raises + billing actually stopping after termination,
| plus the state-transition guards on the REAL status enum
| (draft/pending_approval/active/expired/renewed/terminated/cancelled).
*/

use App\Models\Charge;
use App\Models\Invoice;
use App\Models\Lease;
use App\Services\LeaseCreationService;
use App\Services\LeaseRentChangeService;
use App\Services\LeaseRenewalService;
use App\Services\LeaseTerminationService;
use Carbon\CarbonImmutable;

beforeEach(function () {
    $this->asset = makeAsset(['code' => 'LIFE']);
    $this->unit = makeUnit($this->asset, ['code' => 'U-LIFE', 'status' => 'vacant']);
});

/**
 * Convenience: run the creation service for a unit, then return a FRESH
 * model. The reload matters — the creation service omits has_percentage_rent
 * so the column only materialises (to its DB default) on a re-read; the admin
 * panel re-fetches the record before any follow-up action, so fresh() mirrors
 * the supported production path. (The in-memory-instance hazard is pinned by a
 * dedicated skipped bug scenario below.)
 */
function createLeaseVia(int $unitId, array $lease = []): Lease
{
    return app(LeaseCreationService::class)->create([
        'tenant_mode' => 'existing',
        'tenant_id' => makeTenant()->id,
        'lease' => array_merge([
            'unit_id' => $unitId,
            'commencement_date' => '2026-01-01',
            'term_months' => 12,
            'base_rent_monthly' => 10000,
            'service_charge_monthly' => 2000,
        ], $lease),
    ])->fresh();
}

/*
|--------------------------------------------------------------------------
| CREATION — full charge/VAT seeding wired to the unit-status projection
|--------------------------------------------------------------------------
*/

it('creation seeds VAT-exempt rent + 14% service charge and the totals reconcile via Charge::totalWithVat', function () {
    $lease = createLeaseVia($this->unit->id, [
        'base_rent_monthly' => 10000,
        'service_charge_monthly' => 2000,
    ]);

    $rent = $lease->charges()->where('type', 'base_rent')->sole();
    $svc = $lease->charges()->where('type', 'service_charge')->sole();

    // Egypt rule: rent is VAT-exempt, service charge carries 14% VAT.
    expect($rent->vat_applicable)->toBeFalse()
        ->and($rent->calculateVat())->toBe(0.0)
        ->and((float) $rent->totalWithVat())->toBe(10000.0);

    expect($svc->vat_applicable)->toBeTrue()
        ->and((float) $svc->vat_rate)->toBe(14.0)
        // 2000 * 14% = 280 VAT → 2280 gross.
        ->and($svc->calculateVat())->toBe(280.0)
        ->and((float) $svc->totalWithVat())->toBe(2280.0);

    // Both charges start on commencement and are active (so billing picks them up).
    expect($rent->start_date->toDateString())->toBe('2026-01-01')
        ->and($svc->start_date->toDateString())->toBe('2026-01-01')
        ->and($rent->is_active)->toBeTrue()
        ->and($svc->is_active)->toBeTrue();
});

it('creation projects the unit from vacant to occupied through the observer', function () {
    expect($this->unit->fresh()->status)->toBe('vacant');

    $lease = createLeaseVia($this->unit->id);

    // Observer mirrors leases.unit_id into the lease_unit master pivot and
    // recomputes occupancy from the now-active lease.
    expect($this->unit->fresh()->status)->toBe('occupied')
        ->and($lease->units()->wherePivot('is_master', true)->count())->toBe(1);
});

it('the first billing run after creation invoices the three seeded charges with correct VAT split', function () {
    $lease = createLeaseVia($this->unit->id, [
        'commencement_date' => '2026-01-01',
        'base_rent_monthly' => 10000,
        'service_charge_monthly' => 2000,
    ]);

    $svc = app(\App\Services\MonthlyBillingService::class);
    $result = $svc->generateForLease($lease, CarbonImmutable::parse('2026-01-01'));

    expect($result['status'])->toBe('created');
    $invoice = $result['invoice'];

    // subtotal = 10000 (rent) + 2000 (service) + 500 (marketing levy, 5% of rent) = 12500;
    // VAT only on the service charge = 280 (rent + marketing are VAT-exempt); total = 12780.
    expect((float) $invoice->subtotal)->toBe(12500.0)
        ->and((float) $invoice->vat_amount)->toBe(280.0)
        ->and((float) $invoice->total)->toBe(12780.0)
        ->and($invoice->items()->count())->toBe(3);
});

/*
|--------------------------------------------------------------------------
| ESCALATION — a scheduled rent raise, applied via LeaseRentChangeService,
| keeps the Charge.amount in lock-step so the next bill is the raised one.
|--------------------------------------------------------------------------
*/

it('an escalation raises base rent on schedule and the base_rent charge tracks it so the next invoice bills the new rent', function () {
    $lease = createLeaseVia($this->unit->id, [
        'base_rent_monthly' => 10000,
        'service_charge_monthly' => 2000,
        'escalation_rate' => 7,
    ]);

    // Lease was created with a fixed_percent 7% escalation. Simulate the
    // year-2 escalation falling due: raise rent by the escalation_rate.
    $rate = (float) $lease->escalation_rate;
    expect($rate)->toBe(7.0)
        ->and($lease->escalation_type)->toBe('fixed_percent');

    $escalated = round((float) $lease->base_rent_monthly * (1 + $rate / 100), 2); // 10700
    app(LeaseRentChangeService::class)->apply($lease, [
        'base_rent_monthly' => $escalated,
        'reason' => 'Year-2 fixed 7% escalation',
    ]);

    // Lease field AND the base-rent row IN FORCE both move to the new rent — no drift between
    // the widget value and what billing reads.
    $lease->refresh();
    $today = \Carbon\CarbonImmutable::now();
    $inForce = app(\App\Services\ChargeScheduleService::class)->rowInForce($lease, 'base_rent', $today);

    expect((float) $lease->base_rent_monthly)->toBe(10700.0)
        ->and((float) $inForce->amount)->toBe(10700.0)
        // The reason lives on a lease EVENT now, not appended to `leases.notes` (story LE-01).
        ->and($lease->events()->first()?->reason)->toContain('Year-2 fixed 7% escalation');

    // …and the OLD rent is still readable. This is the change: a rent increase closes the
    // previous schedule row rather than overwriting its amount, so what the tenant was billed
    // last year survives (docs/benchmarks/yardi — story LS-03). Before, this row was destroyed.
    $schedule = $lease->charges()->where('type', 'base_rent')->orderBy('start_date')->get();
    expect($schedule)->toHaveCount(2)
        ->and((float) $schedule->first()->amount)->toBe(10000.0)
        ->and($schedule->first()->end_date)->not->toBeNull()
        // Closed on the last day of the PREVIOUS month, and the new row opens on the 1st: a
        // schedule change snaps to the billing month, because the engine bills one amount per
        // charge type per month and a row starting mid-month would leave that month ambiguous.
        ->and($schedule->first()->end_date->toDateString())
        ->toBe($today->startOfMonth()->subDay()->toDateString())
        ->and($schedule->last()->start_date->toDateString())->toBe($today->startOfMonth()->toDateString())
        ->and($schedule->last()->end_date)->toBeNull(); // the open-ended current row

    // The service charge is untouched by a rent-only escalation — still exactly one row.
    expect((float) $lease->charges()->where('type', 'service_charge')->sole()->amount)->toBe(2000.0);

    // A month ON or AFTER the change bills the escalated rent.
    $billing = app(\App\Services\MonthlyBillingService::class);
    $after = $billing->generateForLease($lease, $today->startOfMonth());
    expect((float) $after['invoice']->items()->where('type', 'base_rent')->sole()->amount)->toBe(10700.0);

    // …and a month BEFORE it still bills what was in force then. This is the schedule's whole
    // point, and it is a behaviour change: under the old overwrite model, re-billing a past month
    // charged that month at TODAY's rent, because the only row that existed held today's amount.
    $before = $billing->generateForLease($lease, CarbonImmutable::parse('2026-02-01'));
    expect((float) $before['invoice']->items()->where('type', 'base_rent')->sole()->amount)->toBe(10000.0);
});

it('escalation guard: a fixed_percent raise cannot be applied to a terminated lease', function () {
    $lease = createLeaseVia($this->unit->id);
    app(LeaseTerminationService::class)->terminate($lease, ['reason' => 'closed']);

    expect(fn () => app(LeaseRentChangeService::class)->apply($lease->fresh(), [
        'base_rent_monthly' => 11000,
    ]))->toThrow(InvalidArgumentException::class);
});

/*
|--------------------------------------------------------------------------
| RENEWAL — the previous_lease_id chain, next_escalation_date reset, and a
| renewal-of-a-renewal forming a two-link chain.
|--------------------------------------------------------------------------
*/

it('renewal links previous_lease_id, marks the original renewed, and resets next_escalation_date', function () {
    $original = createLeaseVia($this->unit->id, [
        'commencement_date' => '2026-01-01',
        'term_months' => 12,
    ]);
    // Original carried a pending escalation date.
    $original->update(['next_escalation_date' => '2027-01-01']);

    $renewal = app(LeaseRenewalService::class)->renew($original, [
        'new_term_months' => 12,
        'new_rent' => 11000,
    ]);

    expect($original->fresh()->status)->toBe('renewed')
        ->and($renewal->status)->toBe('active')
        ->and($renewal->previous_lease_id)->toBe($original->id)
        // The renewed lease copies the escalation clause, so its anniversary is re-armed to its OWN
        // commencement + 1 year (previously it was left null, so a "7% escalation" renewal never
        // actually escalated — the dead-escalation bug the Lease::creating hook now fixes).
        ->and($renewal->next_escalation_date?->toDateString())
            ->toBe($renewal->commencement_date->copy()->addYear()->toDateString());

    // Relationship wiring resolves both directions.
    expect($renewal->previousLease->is($original))->toBeTrue()
        ->and($original->fresh()->renewals->pluck('id')->all())->toBe([$renewal->id]);
});

it('renewal keeps the unit occupied throughout — never flickering vacant between original and renewal', function () {
    $original = createLeaseVia($this->unit->id);
    expect($this->unit->fresh()->status)->toBe('occupied');

    app(LeaseRenewalService::class)->renew($original, [
        'new_term_months' => 12,
        'new_rent' => 11000,
    ]);

    // Original → renewed (reserved-tier), renewal → active (occupied-tier).
    // The active renewal wins the projection, so the unit stays occupied.
    expect($this->unit->fresh()->status)->toBe('occupied');
});

it('a renewal can itself be renewed, forming a previous_lease_id chain back to the origin', function () {
    $gen1 = createLeaseVia($this->unit->id, ['commencement_date' => '2026-01-01', 'term_months' => 12]);
    $gen2 = app(LeaseRenewalService::class)->renew($gen1, ['new_term_months' => 12, 'new_rent' => 11000]);
    $gen3 = app(LeaseRenewalService::class)->renew($gen2, ['new_term_months' => 12, 'new_rent' => 12000]);

    expect($gen2->previous_lease_id)->toBe($gen1->id)
        ->and($gen3->previous_lease_id)->toBe($gen2->id)
        ->and($gen1->fresh()->status)->toBe('renewed')
        ->and($gen2->fresh()->status)->toBe('renewed')
        ->and($gen3->status)->toBe('active');

    // Walking the chain back from the newest lease reaches the origin.
    expect($gen3->previousLease->previousLease->is($gen1))->toBeTrue();
});

/*
|--------------------------------------------------------------------------
| TERMINATION — frees the unit AND stops billing for good.
|--------------------------------------------------------------------------
*/

it('termination stamps the end_date on every charge so a later billing run produces nothing', function () {
    $lease = createLeaseVia($this->unit->id, ['commencement_date' => '2026-01-01']);

    app(LeaseTerminationService::class)->terminate($lease, [
        'termination_date' => '2026-03-15',
        'reason' => 'tenant exit',
    ]);

    // Every charge deactivated and end-dated at the termination date.
    $lease->charges()->get()->each(function (Charge $c) {
        expect($c->is_active)->toBeFalse()
            ->and($c->end_date->toDateString())->toBe('2026-03-15');
    });

    // A whole-portfolio run for April finds the lease no longer 'active' and
    // never considers it — zero invoices created for it.
    $stats = app(\App\Services\MonthlyBillingService::class)
        ->runForPeriod(CarbonImmutable::parse('2026-04-01'));

    expect(Invoice::where('lease_id', $lease->id)->count())->toBe(0)
        ->and($stats['created'])->toBe(0);
});

it('a direct single-lease billing call on a terminated lease is refused for BEING terminated', function () {
    $lease = createLeaseVia($this->unit->id, ['commencement_date' => '2026-01-01']);
    app(LeaseTerminationService::class)->terminate($lease, ['termination_date' => '2026-02-10']);

    $result = app(\App\Services\MonthlyBillingService::class)
        ->generateForLease($lease->fresh(), CarbonImmutable::parse('2026-05-01'));

    // This used to assert 'no_applicable_charges', and the reason it passed was an ACCIDENT:
    // termination deactivates the charges, so the single-lease path fell through to "nothing to
    // bill" without ever checking whether the lease itself was billable. A terminated lease that
    // still carried one active charge — added later, or missed by termination — would have been
    // billed. The manual path now applies the same eligibility rule as the scheduled run
    // (Lease::isBillableForPeriod), so the refusal is principled rather than incidental.
    // See ManualBillingEligibilityTest.
    expect($result['status'])->toBe('skipped')
        ->and($result['reason'])->toBe('lease_not_billable')
        ->and($result['invoice'])->toBeNull();
});

it('terminating an active lease frees its unit to vacant when no other lease covers it', function () {
    $lease = createLeaseVia($this->unit->id);
    expect($this->unit->fresh()->status)->toBe('occupied');

    app(LeaseTerminationService::class)->terminate($lease, []);

    expect($this->unit->fresh()->status)->toBe('vacant');
});

it('termination leaves the unit reserved (not vacant) while a draft lease still covers it', function () {
    $active = createLeaseVia($this->unit->id);
    // A draft replacement lease already queued on the same unit (the creation
    // service would block a second ACTIVE lease, so build the draft directly).
    $draft = makeLease($this->unit, attrs: ['status' => 'draft']);
    expect($this->unit->fresh()->status)->toBe('occupied'); // active wins

    app(LeaseTerminationService::class)->terminate($active, []);

    // Active gone, draft remains → projection falls to the reserved tier.
    expect($this->unit->fresh()->status)->toBe('reserved')
        ->and($draft->fresh()->status)->toBe('draft');
});

/*
|--------------------------------------------------------------------------
| STATE-TRANSITION GUARDS — across the full real status enum.
|--------------------------------------------------------------------------
*/

it('termination accepts a pending_approval lease but rejects every other non-active status', function () {
    // pending_approval is explicitly allowed by the service.
    $pending = makeLease($this->unit, attrs: ['status' => 'pending_approval']);
    $result = app(LeaseTerminationService::class)->terminate($pending, []);
    expect($result->status)->toBe('terminated');

    foreach (['draft', 'expired', 'renewed', 'terminated', 'cancelled'] as $status) {
        $unit = makeUnit($this->asset, ['code' => 'G-' . $status]);
        $lease = makeLease($unit, attrs: ['status' => $status]);

        expect(fn () => app(LeaseTerminationService::class)->terminate($lease, []))
            ->toThrow(InvalidArgumentException::class);
    }
});

it('renewal rejects every non-active status, including pending_approval', function () {
    // Unlike termination, renewal is strictly active-only.
    foreach (['draft', 'pending_approval', 'expired', 'renewed', 'terminated', 'cancelled'] as $status) {
        $unit = makeUnit($this->asset, ['code' => 'R-' . $status]);
        $lease = makeLease($unit, attrs: ['status' => $status]);

        expect(fn () => app(LeaseRenewalService::class)->renew($lease, [
            'new_term_months' => 12,
            'new_rent' => 11000,
        ]))->toThrow(InvalidArgumentException::class, 'active');
    }
});

it('rent change accepts active and pending_approval but rejects the rest', function () {
    foreach (['active', 'pending_approval'] as $ok) {
        $unit = makeUnit($this->asset, ['code' => 'OK-' . $ok]);
        $lease = makeLease($unit, attrs: ['status' => $ok, 'base_rent_monthly' => 10000]);
        Charge::create([
            'lease_id' => $lease->id, 'name' => 'Base Rent', 'type' => 'base_rent',
            'amount' => 10000, 'currency' => 'EGP', 'frequency' => 'monthly',
            'vat_applicable' => false, 'vat_rate' => 0,
            'start_date' => '2026-01-01', 'is_active' => true,
        ]);

        $changed = app(LeaseRentChangeService::class)->apply($lease, ['base_rent_monthly' => 12000]);
        expect((float) $changed->base_rent_monthly)->toBe(12000.0);
    }

    foreach (['draft', 'expired', 'renewed', 'terminated', 'cancelled'] as $bad) {
        $unit = makeUnit($this->asset, ['code' => 'BAD-' . $bad]);
        $lease = makeLease($unit, attrs: ['status' => $bad]);

        expect(fn () => app(LeaseRentChangeService::class)->apply($lease, ['base_rent_monthly' => 12000]))
            ->toThrow(InvalidArgumentException::class);
    }
});

/*
|--------------------------------------------------------------------------
| EXPIRY — derived expiry maths + the "expiring soon" boundary the dashboard
| widget reads.
|--------------------------------------------------------------------------
*/

it('creation computes expiry as commencement + term_months - 1 day for a 24-month term', function () {
    $lease = createLeaseVia($this->unit->id, [
        'commencement_date' => '2026-02-01',
        'term_months' => 24,
    ]);

    // 2026-02-01 + 24 months = 2028-02-01, minus a day = 2028-01-31.
    expect($lease->expiry_date->toDateString())->toBe('2028-01-31')
        ->and((int) $lease->term_months)->toBe(24);
});

it('isExpiringSoon is inclusive of the window boundary and excludes leases past their term', function () {
    $today = CarbonImmutable::now()->startOfDay();

    $unitA = makeUnit($this->asset, ['code' => 'EXP-A']);
    $expiringInWindow = makeLease($unitA, attrs: [
        'status' => 'active',
        'expiry_date' => $today->addDays(30)->toDateString(),
    ]);

    $unitB = makeUnit($this->asset, ['code' => 'EXP-B']);
    $expiringFarOut = makeLease($unitB, attrs: [
        'status' => 'active',
        'expiry_date' => $today->addDays(200)->toDateString(),
    ]);

    // 30 days out is inside the default 90-day window; 200 days out is not.
    expect($expiringInWindow->isExpiringSoon())->toBeTrue()
        ->and($expiringFarOut->isExpiringSoon())->toBeFalse();

    // daysUntilExpiry is a forward (signed) count from "now" (which carries a
    // time component) to the start-of-day expiry, floored — so a date exactly
    // 30 calendar days out reads 29 from mid-day now(). Assert it is positive
    // and within one day of the calendar gap rather than a brittle exact value.
    expect($expiringInWindow->daysUntilExpiry())->toBeGreaterThan(0)
        ->and($expiringInWindow->daysUntilExpiry())->toBeLessThanOrEqual(30)
        ->and($expiringInWindow->daysUntilExpiry())->toBeGreaterThanOrEqual(29);
});

/*
|--------------------------------------------------------------------------
| BUG PIN — renewal propagates a null in-memory has_percentage_rent.
|--------------------------------------------------------------------------
*/

it('renews a service-created lease without the has_percentage_rent NOT NULL crash', function () {
    // LeaseCreationService omits has_percentage_rent on create; the Lease model
    // defaults the NOT NULL boolean in memory (false, not null), so renewing the
    // just-returned instance (without a DB re-read) no longer propagates null
    // into the non-nullable column.
    $unit = makeUnit($this->asset, ['code' => 'BUG-HPR', 'status' => 'vacant']);

    $original = app(LeaseCreationService::class)->create([
        'tenant_mode' => 'existing',
        'tenant_id' => makeTenant()->id,
        'lease' => [
            'unit_id' => $unit->id,
            'commencement_date' => '2026-01-01',
            'term_months' => 12,
            'base_rent_monthly' => 10000,
            'service_charge_monthly' => 2000,
        ],
    ]); // NOT refreshed.

    expect($original->has_percentage_rent)->toBeFalse();   // defaulted in memory, not null

    $renewal = app(LeaseRenewalService::class)->renew($original, [
        'new_term_months' => 12,
        'new_rent' => 11000,
    ]);

    expect($renewal->has_percentage_rent)->toBeFalse()
        ->and((int) $renewal->previous_lease_id)->toBe($original->id);
});
