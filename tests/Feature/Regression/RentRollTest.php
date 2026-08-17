<?php

use App\Filament\Admin\Pages\RentRoll;
use App\Models\Charge;
use App\Models\Lease;
use App\Models\LeaseOption;
use App\Services\LeaseCreationService;
use App\Services\MonthlyBillingService;
use App\Services\Reports\ReportService;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesPermissionsSeeder;
use Filament\Facades\Filament;
use Livewire\Livewire;

/**
 * The rent roll (RR-01) — what the mall is contracted to earn, **as at a date**.
 *
 * The as-of date is the whole feature, and it is why this report could not exist before phase 1:
 * the rent was one mutable number, so a roll for last March would have reported today's rent and a
 * roll for next year could not exist at all.
 *
 * The property that matters most is agreement with billing. A rent roll that decides "current
 * rent" by its own rule will eventually disagree with what actually bills, and an owner who finds
 * that out stops trusting both numbers — so these tests assert the roll against the invoice the
 * engine produces for the same month, not against a hand-computed figure.
 */
afterEach(fn () => CarbonImmutable::setTestNow());

beforeEach(function () {
    $this->seed(RolesPermissionsSeeder::class);
    $this->asset = makeAsset();
    Filament::setCurrentPanel(Filament::getPanel('admin'));
});

afterEach(fn () => Filament::setTenant(null, isQuiet: true));

/**
 * NOTE: `LeaseCreationService` is the QUICK-lease wizard and accepts a deliberately narrow payload
 * — unit, tenant, dates, rent, service charge, deposit, payment terms. Everything else takes the
 * model default, which is why these leases carry the 5% marketing levy (`has_marketing_levy`
 * defaults true) and a `fixed_percent` escalation. Fit-out, billing frequency and percentage rent
 * are set on the full lease form afterwards. That is the wizard's design, not a dropped field.
 */
function rollLease(array $attrs = [], float $rent = 100000): Lease
{
    return app(LeaseCreationService::class)->create([
        'tenant_mode' => 'existing',
        'tenant_id' => makeTenant()->id,
        'lease' => array_merge([
            'unit_id' => makeUnit(test()->asset, ['area_sqm' => 200])->id,
            'commencement_date' => '2026-01-01',
            'term_months' => 60,
            'base_rent_monthly' => $rent,
            'service_charge_monthly' => 20000,
            'escalation_rate' => 7,
        ], $attrs),
    ])->fresh();
}

function roll(?string $asOf = null, ?int $assetId = null)
{
    return app(ReportService::class)->rentRoll(
        CarbonImmutable::parse($asOf ?? '2026-06-15'),
        $assetId ?? test()->asset->id,
    );
}

/* ---- the as-of date, which is the point ------------------------------------ */

it('reports the rent in force on the chosen date, not today\'s', function () {
    CarbonImmutable::setTestNow('2026-06-15');
    rollLease();

    // Same lease, three dates, three contracted rents — impossible before the schedule existed.
    expect((float) roll('2026-06-15')->first()['base_rent'])->toBe(100000.0)
        ->and((float) roll('2027-06-15')->first()['base_rent'])->toBe(107000.0)
        ->and((float) roll('2030-06-15')->first()['base_rent'])->toBe(131079.60);
});

it('agrees with the invoice the billing engine produces for the same month', function () {
    // The assertion that matters. A roll computed by its own rule would drift from billing, and an
    // owner who catches that stops trusting both.
    CarbonImmutable::setTestNow('2027-06-15');
    $lease = rollLease();

    $invoice = app(MonthlyBillingService::class)
        ->generateForLease($lease, CarbonImmutable::parse('2027-06-01'))['invoice'];

    expect((float) roll('2027-06-15')->first()['base_rent'])
        ->toBe((float) $invoice->items()->where('type', 'base_rent')->sole()->amount);
});

it('leaves out a lease that had not started, or had already ended, on that date', function () {
    CarbonImmutable::setTestNow('2026-06-15');
    rollLease();                                        // 2026-01-01 → 2030-12-31
    rollLease(['commencement_date' => '2027-01-01']);    // not started yet

    expect(roll('2026-06-15'))->toHaveCount(1)
        ->and(roll('2025-06-15'))->toHaveCount(0)       // before either began
        ->and(roll('2027-06-15'))->toHaveCount(2);      // both live
});

/* ---- the numbers an owner reads -------------------------------------------- */

it('computes rent per square metre per year, and refuses to divide by no area', function () {
    CarbonImmutable::setTestNow('2026-06-15');
    rollLease();                                                   // 200 m², 100,000/month
    rollLease(['unit_id' => makeUnit($this->asset, ['area_sqm' => 0])->id]);

    $rows = roll()->keyBy('rent_per_sqm_year');

    // 100,000 × 12 ÷ 200 = 6,000
    expect(roll()->firstWhere('area_sqm', 200.0)['rent_per_sqm_year'])->toBe(6000.0)
        // No area is not a rate of zero — it is unknown, and a zero would quietly drag a
        // portfolio average down.
        ->and(roll()->firstWhere('area_sqm', 0.0)['rent_per_sqm_year'])->toBeNull();
});

it('carries the next contracted step and the next option deadline', function () {
    CarbonImmutable::setTestNow('2026-06-15');
    $lease = rollLease();

    LeaseOption::create([
        'lease_id' => $lease->id, 'type' => 'renewal', 'status' => 'open',
        'earliest_notice_date' => '2030-01-01', 'latest_notice_date' => '2030-04-01',
    ]);

    $row = roll()->first();

    expect($row['next_step_date']->toDateString())->toBe('2027-01-01')
        ->and((float) $row['next_step_amount'])->toBe(107000.0)
        ->and($row['next_option_date']->toDateString())->toBe('2030-04-01')
        ->and($row['next_option_type'])->toBe('renewal');
});

it('ignores an option that is already resolved when picking the next deadline', function () {
    CarbonImmutable::setTestNow('2026-06-15');
    $lease = rollLease();

    LeaseOption::create([
        'lease_id' => $lease->id, 'type' => 'termination', 'status' => 'exercised',
        'latest_notice_date' => '2027-01-01',
    ]);
    LeaseOption::create([
        'lease_id' => $lease->id, 'type' => 'renewal', 'status' => 'open',
        'latest_notice_date' => '2030-04-01',
    ]);

    // A resolved option needs no decision, so it must not be the date the roll flags.
    expect(roll()->first()['next_option_date']->toDateString())->toBe('2030-04-01');
});

it('totals the monthly charge including service and marketing', function () {
    CarbonImmutable::setTestNow('2026-06-15');
    rollLease();

    $row = roll()->first();

    // 100,000 rent + 20,000 service + 5,000 levy (5% of rent, on by default). The levy belongs in
    // the total precisely because the owner's question is "what does this tenancy bill a month",
    // not "what is the rent line".
    expect((float) $row['total_monthly'])->toBe(125000.0)
        ->and((float) $row['service_charge'])->toBe(20000.0)
        ->and((float) $row['marketing'])->toBe(5000.0);
});

it('is scoped to the selected property', function () {
    CarbonImmutable::setTestNow('2026-06-15');
    $other = makeAsset();
    rollLease();
    app(LeaseCreationService::class)->create([
        'tenant_mode' => 'existing',
        'tenant_id' => makeTenant()->id,
        'lease' => [
            'unit_id' => makeUnit($other, ['area_sqm' => 100])->id,
            'commencement_date' => '2026-01-01', 'term_months' => 24,
            'base_rent_monthly' => 50000, 'service_charge_monthly' => 0,
        ],
    ]);

    expect(roll('2026-06-15', $this->asset->id))->toHaveCount(1)
        ->and(roll('2026-06-15', $other->id))->toHaveCount(1);
});

/* ---- the page -------------------------------------------------------------- */

it('renders the roll with its headline totals', function () {
    CarbonImmutable::setTestNow('2026-06-15');
    $lease = rollLease();

    $this->actingAs(makeUser('manager', [$this->asset->id]));
    Filament::setTenant($this->asset);

    expect(RentRoll::canAccess())->toBeTrue();

    $page = Livewire::test(RentRoll::class)->set('asOf', '2026-06-15');

    $page->assertOk()->assertSee($lease->tenant->name);

    // A roll that renders empty would pass assertOk() and tell the owner the mall earns nothing.
    expect($page->instance()->getSubheading())
        ->toContain('125,000.00')   // total monthly (rent + service + levy)
        ->toContain('6,000.00');    // EGP/m²/yr, weighted by area
});

it('hides the roll from a user without report access', function () {
    $this->actingAs(makeUser('hr', [$this->asset->id]));
    Filament::setTenant($this->asset);

    expect(RentRoll::canAccess())->toBeFalse();
});
