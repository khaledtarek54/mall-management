<?php

use App\Filament\Imports\LeaseImporter;
use App\Models\Lease;
use App\Services\ChargeScheduleService;
use App\Services\MonthlyBillingService;
use App\Settings\BillingSettings;
use App\Support\ProrationMethod;
use Carbon\CarbonImmutable;
use Illuminate\Validation\Rules\In;

/**
 * EG-29 / M-1 — how a PARTIAL month is priced is a lease term, not a hardcoded line.
 *
 * Proration was `days ÷ that month's own length`. That is one of the four methods Yardi Voyager
 * ships, and leases say different things: a clause reading "one thirtieth of the monthly rent per
 * day" is billed wrong in the seven months that do not have thirty days. On 30,000/month, sixteen
 * days of August billed 14,516.13 where the clause says 15,000 — under-billed on a move-in, and the
 * same line runs every move-out, rent commencement and final cycle.
 *
 * **A full month is exactly one month under every method.** Without that rule 30/360 bills 31/30 of
 * a month every August and the 365 method bills 31 × 12 ÷ 365 — both more than a month's rent for a
 * month the tenant occupied normally, which is not what any of these methods mean.
 */
function prorationFactor(string $method, string $from, string $to, ?string $monthStart = null): float
{
    $month = CarbonImmutable::parse($monthStart ?? $from)->startOfMonth();

    return MonthlyBillingService::monthsCovered(
        $month, 1, CarbonImmutable::parse($from), CarbonImmutable::parse($to), $method,
    );
}

it('prices the same sixteen days four different ways', function () {
    // 16–31 August inclusive: 16 days of a 31-day month.
    $factors = collect(ProrationMethod::METHODS)
        ->mapWithKeys(fn (string $m): array => [$m => round(prorationFactor($m, '2026-08-16', '2026-08-31') * 30000, 2)])
        ->all();

    expect($factors[ProrationMethod::ACTUAL])->toBe(15483.87)      // 16/31
        ->and($factors[ProrationMethod::THIRTY_DAY])->toBe(16000.0) // 16/30 — the clause
        ->and($factors[ProrationMethod::YEAR_365])->toBe(15780.82)  // 16 × 12 / 365
        ->and($factors[ProrationMethod::WHOLE_MONTH])->toBe(30000.0);
});

it('bills a FULL month as exactly one month under every method', function () {
    // The rule that keeps 30/360 from billing 31/30 of a month every August. Both a 31-day and a
    // 28-day month, because the divisor is wrong in opposite directions in each.
    foreach (ProrationMethod::METHODS as $method) {
        expect(prorationFactor($method, '2026-08-01', '2026-08-31'))->toBe(1.0, "31-day month, {$method}")
            ->and(prorationFactor($method, '2026-02-01', '2026-02-28'))->toBe(1.0, "28-day month, {$method}");
    }
});

it('defaults to what this system always did, so nothing moves on deploy', function () {
    expect(ProrationMethod::DEFAULT)->toBe(ProrationMethod::ACTUAL)
        ->and(app(BillingSettings::class)->proration_method)->toBe(ProrationMethod::ACTUAL)
        // …and the parameter default matches, so a caller that predates EG-29 bills identically.
        ->and(MonthlyBillingService::monthsCovered(
            CarbonImmutable::parse('2026-08-01'), 1,
            CarbonImmutable::parse('2026-08-16'), CarbonImmutable::parse('2026-08-31'),
        ))->toBe(16 / 31);
});

it('resolves the lease clause first, then the property, then the portfolio', function () {
    $asset = makeAsset();
    $lease = makeLease(makeUnit($asset), makeTenant(), ['asset_id' => $asset->id]);

    // Nothing stated anywhere — the portfolio default.
    expect($lease->prorationMethod())->toBe(ProrationMethod::ACTUAL);

    // The portfolio speaks.
    $settings = app(BillingSettings::class);
    $settings->proration_method = ProrationMethod::YEAR_365;
    $settings->save();

    expect($lease->fresh()->prorationMethod())->toBe(ProrationMethod::YEAR_365);

    // The lease's own clause wins over it.
    $lease->update(['proration_method' => ProrationMethod::THIRTY_DAY]);

    expect($lease->fresh()->prorationMethod())->toBe(ProrationMethod::THIRTY_DAY);
});

it('bills a real mid-month move-in on the lease’s own clause', function () {
    // Driven through the billing planner, not the arithmetic: a method the lease states and the
    // planner never reads is exactly the shape of bug this row describes.
    $asset = makeAsset();
    $lease = makeLease(makeUnit($asset), makeTenant(), [
        'asset_id' => $asset->id,
        'status' => 'active',
        'commencement_date' => '2026-08-16',
        'expiry_date' => '2029-08-15',
        'base_rent_monthly' => 30000,
        'proration_method' => ProrationMethod::THIRTY_DAY,
    ]);

    app(ChargeScheduleService::class)->setAmount(
        $lease, 'base_rent', 30000.0, CarbonImmutable::parse('2026-08-16'),
        ['name' => 'Base Rent', 'frequency' => 'monthly', 'vat_applicable' => false, 'vat_rate' => 0],
    );

    $plan = app(MonthlyBillingService::class)->planInvoiceForLease(
        $lease->fresh(), CarbonImmutable::parse('2026-08-01'), CarbonImmutable::parse('2026-08-31'), prorate: true,
    );

    $rent = collect($plan['items'])->firstWhere('type', 'base_rent');

    // 16 days at one thirtieth each. On `actual` this line would read 15,483.87.
    expect($rent)->not->toBeNull()
        ->and(round((float) $rent['amount'], 2))->toBe(16000.0);
});

it('falls back rather than throwing when a stored method is not one of the four', function () {
    // Proration runs inside the monthly billing run; a hand-edited row must not stop a night's
    // invoicing. Resolved, not validated-at-read — the column's `ValueSets` entry is what refuses a
    // bad value on the way IN.
    expect(ProrationMethod::resolve('fortnightly', null))->toBe(ProrationMethod::ACTUAL)
        ->and(ProrationMethod::isKnown('fortnightly'))->toBeFalse()
        ->and(ProrationMethod::isKnown(null))->toBeFalse();
});

it('refuses a method the catalogue does not offer, at the column', function () {
    $asset = makeAsset();
    $lease = makeLease(makeUnit($asset), makeTenant(), ['asset_id' => $asset->id]);

    expect(fn () => $lease->update(['proration_method' => 'fortnightly']))->toThrow(Exception::class);

    // The control: a real one saves.
    $lease->update(['proration_method' => ProrationMethod::WHOLE_MONTH]);

    expect($lease->fresh()->proration_method)->toBe(ProrationMethod::WHOLE_MONTH);
});

it('lets a migrating operator import the clause with the lease', function () {
    // The "reachable but not configurable" gap this codebase keeps finding: a term a form can set
    // and an import cannot is 200 leases keyed in by hand, and a lease imported on the wrong method
    // is under- or over-billed on its very first part month.
    $names = array_map(fn ($c) => $c->getName(), LeaseImporter::getColumns());

    expect($names)->toContain('proration_method');

    // Read from the registry rather than a second hardcoded list — all four, because a migrating
    // operator's leases genuinely state any of them.
    $column = collect(LeaseImporter::getColumns())
        ->first(fn ($c) => $c->getName() === 'proration_method');

    $rule = collect($column->getDataValidationRules())->first(fn ($r) => $r instanceof In);

    expect((string) $rule)->toContain(ProrationMethod::THIRTY_DAY)
        ->and((string) $rule)->toContain(ProrationMethod::WHOLE_MONTH);
});
