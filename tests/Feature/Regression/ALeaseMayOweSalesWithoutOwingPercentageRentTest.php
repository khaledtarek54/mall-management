<?php

declare(strict_types=1);

use App\Filament\Admin\Actions\SalesDeclarationActions;
use App\Filament\Admin\RelationManagers\LeaseSalesDeclarationsRelationManager;
use App\Filament\Admin\RelationManagers\PercentageRentTiersRelationManager;
use App\Filament\Admin\Resources\Leases\Pages\EditLease;
use App\Models\Lease;
use App\Models\TenantSalesDeclaration;
use Carbon\CarbonImmutable;

/**
 * WHETHER A TENANT DECLARES IS A DIFFERENT CLAUSE FROM WHETHER THEY PAY ON IT.
 *
 * `has_percentage_rent` was doing both jobs: it decided the charge AND it decided who gets chased
 * for a declaration. A mall collects turnover from tenants who owe no percentage rent — for sales
 * per m², for the occupancy-cost ratio that says which tenant is in trouble, and to price a renewal
 * at all — and many leases oblige the disclosure without charging on it. Yardi keeps "Sales
 * Reporting Required" as its own field for exactly this.
 *
 * NULL IS THE NORMAL STATE and means "follow the percentage-rent clause", so nothing an install
 * does today changes. That matters more than it looks: a plain boolean backfilled from the current
 * flag would FREEZE the answer, and a lease that gains percentage rent later would never start
 * being chased — silently. It is the `charges.vat_applicable` bug, and the reason this column is
 * compared with `=== null` rather than cast.
 */
function reportingLease(?bool $requires, bool $percentage): Lease
{
    return Lease::factory()->create([
        'status' => 'active',
        'commencement_date' => CarbonImmutable::parse('2026-01-01'),
        'expiry_date' => CarbonImmutable::parse('2028-12-31'),
        'has_percentage_rent' => $percentage,
        'percentage_rent_rate' => $percentage ? 7 : null,
        'percentage_rent_calculation_type' => $percentage ? 'artificial' : null,
        'percentage_rent_threshold' => $percentage ? 800_000 : null,
        'percentage_rent_frequency' => 'monthly',
        'requires_sales_reporting' => $requires,
        'escalation_type' => 'none',
    ]);
}

it('answers the duty across all four combinations', function (?bool $requires, bool $percentage, bool $expected): void {
    expect(reportingLease($requires, $percentage)->requiresSalesReporting())->toBe($expected);
})->with([
    'unset on a fixed-rent lease — nothing to declare' => [null, false, false],
    'unset on a percentage lease — follows the clause' => [null, true, true],
    'required without percentage rent — the new case' => [true, false, true],
    'excused despite percentage rent' => [false, true, false],
]);

it('keeps the SQL and the predicate saying the same thing', function (?bool $requires, bool $percentage, bool $expected): void {
    $lease = reportingLease($requires, $percentage);

    // Two expressions of one rule — the scope chases, the method decides. They drifted apart once
    // in this codebase already, on the billing paths, which is why they are pinned together.
    expect(Lease::owingSalesDeclaration(CarbonImmutable::parse('2026-06-01'))->whereKey($lease->getKey())->exists())
        ->toBe($expected)
        ->and($lease->requiresSalesReporting())->toBe($expected);
})->with([
    [null, false, false],
    [null, true, true],
    [true, false, true],
    [false, true, false],
]);

it('shows the declarations tab wherever a declaration is owed', function (): void {
    $reporting = reportingLease(true, false);
    $neither = reportingLease(null, false);

    expect(LeaseSalesDeclarationsRelationManager::canViewForRecord($reporting, EditLease::class))->toBeTrue()
        // The control the tab was written for: a permanently empty table reads as "they have not
        // declared" rather than "there is nothing to declare".
        ->and(LeaseSalesDeclarationsRelationManager::canViewForRecord($neither, EditLease::class))->toBeFalse();
});

it('does NOT show the breakpoint ladder or the working without a charge', function (): void {
    $reporting = reportingLease(true, false);

    // Reporting is a disclosure duty; a ladder and a working are about a CHARGE. Following the duty
    // here would offer a tier table for a clause that does not exist.
    expect(PercentageRentTiersRelationManager::canViewForRecord($reporting, EditLease::class))->toBeFalse();

    // And the "view the working" action, asked through the surface an operator meets rather than
    // by opening a protected helper: it is offered on a declaration whose lease charges, and not
    // on one that only reports.
    $declaration = TenantSalesDeclaration::create([
        'lease_id' => $reporting->id,
        'period_start' => '2026-06-01',
        'period_end' => '2026-06-30',
        'gross_sales' => 900_000,
        'declared_sales' => 900_000,
        'declared_at' => '2026-07-03',
        'status' => 'submitted',
    ]);

    $working = collect(SalesDeclarationActions::all())
        ->first(fn ($action) => $action->getName() === 'working')
        ->record($declaration);

    expect($working->isVisible())->toBeFalse();
});
