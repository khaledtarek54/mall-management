<?php

use App\Filament\Admin\Resources\TenantSalesDeclarations\Pages\ListTenantSalesDeclarations;
use App\Filament\Admin\Resources\TenantSalesDeclarations\Tables\TenantSalesDeclarationsTable;
use App\Models\TenantSalesDeclaration;
use App\Notifications\SalesDeclarationLockedNotification;
use App\Services\PercentageRentCalculationService;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesPermissionsSeeder;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Livewire\Livewire;

/**
 * UX layer for annual (cumulative) percentage rent — the operator/tenant must be able to SEE and verify
 * how an annual figure is derived (it is a share of a running yearly total, meaningless as a bare
 * number). Covers the service read models (explain / yearAttribution), the native "View working" modal
 * actually rendering the cumulative breakdown, and the tenant notification carrying the annual context.
 */
beforeEach(function () {
    $this->seed(RolesPermissionsSeeder::class);
    $this->asset = makeAsset();
    $this->operator = makeUser('manager', [$this->asset->id]);
    $this->svc = app(PercentageRentCalculationService::class);
    Filament::setCurrentPanel(Filament::getPanel('admin'));
});

afterEach(fn () => Filament::setTenant(null, isQuiet: true));

function uxAnnualLease($ctx)
{
    return makeLease(makeUnit($ctx->asset), makeTenant(), [
        'status' => 'active',
        'has_percentage_rent' => true,
        'percentage_rent_calculation_type' => 'artificial',
        'percentage_rent_frequency' => 'annual',
        'percentage_rent_threshold' => 150000,
        'percentage_rent_rate' => 10,
    ]);
}

function uxDecl($lease, string $month, float $sales, string $status = 'submitted'): TenantSalesDeclaration
{
    $start = $month.'-01';

    return TenantSalesDeclaration::create([
        'lease_id' => $lease->id,
        'period_start' => $start,
        'period_end' => CarbonImmutable::parse($start)->endOfMonth()->toDateString(),
        'declared_sales' => $sales,
        'calculated_percentage_rent' => 0,
        'status' => $status,
        'declared_at' => now(),
        'declared_by_type' => $lease->tenant::class,
        'declared_by_id' => $lease->tenant_id,
    ]);
}

it('explain() exposes the cumulative annual working, not just a bare figure', function () {
    $lease = uxAnnualLease($this);
    $this->svc->lock(uxDecl($lease, '2026-01', 100000), $this->operator); // 100k < 150k → 0
    $mar = uxDecl($lease, '2026-03', 100000);
    $this->svc->lock($mar, $this->operator);                              // cumulative 200k → carries 5,000

    $w = $this->svc->explain($mar->fresh());

    expect($w['applicable'])->toBeTrue()
        ->and($w['frequency'])->toBe('annual')
        ->and($w['prior_ytd_sales'])->toBe(100000.0)
        ->and($w['cumulative_ytd_sales'])->toBe(200000.0)
        ->and($w['breakpoint'])->toBe(150000.0)
        ->and($w['ytd_overage'])->toBe(5000.0)
        ->and($w['this_period_share'])->toBe(5000.0)
        ->and($w['is_estimate'])->toBeFalse();
});

it('explain() flags a not-yet-locked declaration as an estimate', function () {
    $lease = uxAnnualLease($this);
    $submitted = uxDecl($lease, '2026-01', 200000); // still 'submitted'

    expect($this->svc->explain($submitted)['is_estimate'])->toBeTrue();
});

it('yearAttribution() reports each locked month\'s share and the year total', function () {
    $lease = uxAnnualLease($this);
    $this->svc->lock(uxDecl($lease, '2026-01', 100000), $this->operator);
    $this->svc->lock(uxDecl($lease, '2026-02', 100000), $this->operator); // cumulative 200k → Feb 5,000

    $attr = $this->svc->yearAttribution($lease->id, 2026);

    expect($attr['total'])->toBe(5000.0)
        ->and($attr['cumulative_sales'])->toBe(200000.0)
        ->and(collect($attr['months'])->firstWhere('period', 'Feb 2026')['share'])->toBe(5000.0);
});

it('builds + renders the "View working" modal with the cumulative breakdown for an annual lease', function () {
    $lease = uxAnnualLease($this);
    $this->svc->lock(uxDecl($lease, '2026-01', 100000), $this->operator);
    $mar = uxDecl($lease, '2026-03', 100000);
    $this->svc->lock($mar, $this->operator);

    // The native infolist schema for an annual lease includes the cumulative + this-month-share rows.
    $names = collect(TenantSalesDeclarationsTable::workingSchema($mar->fresh()))->map->getName();
    expect($names)->toContain('working_cumulative')->toContain('working_share');

    // And the modal actually mounts/renders without error (catches a broken schema/component — the
    // class of bug the earlier hand-rolled Blade + missing keys caused).
    $this->actingAs($this->operator);
    Filament::setTenant($this->asset);
    Livewire::test(ListTenantSalesDeclarations::class)
        ->mountAction(TestAction::make('working')->table($mar->fresh()))
        ->assertSuccessful();
});

it('the working modal shows the monthly (not cumulative) breakdown for a monthly lease', function () {
    $lease = makeLease(makeUnit($this->asset), makeTenant(), [
        'status' => 'active', 'has_percentage_rent' => true,
        'percentage_rent_calculation_type' => 'artificial',
        'percentage_rent_threshold' => 50000, 'percentage_rent_rate' => 5,
    ]);
    $decl = uxDecl($lease, '2026-01', 100000);

    $names = collect(TenantSalesDeclarationsTable::workingSchema($decl))->map->getName();
    expect($names)->toContain('working_breakpoint')->toContain('working_overage')
        ->not->toContain('working_cumulative');

    $this->actingAs($this->operator);
    Filament::setTenant($this->asset);
    Livewire::test(ListTenantSalesDeclarations::class)
        ->mountAction(TestAction::make('working')->table($decl))
        ->assertSuccessful();
});

it('annualYearSummary tells the operator how the year now sits (re-truing made visible)', function () {
    $lease = uxAnnualLease($this);
    $this->svc->lock(uxDecl($lease, '2026-01', 100000), $this->operator);
    $feb = uxDecl($lease, '2026-02', 100000);
    $this->svc->lock($feb, $this->operator);

    $summary = TenantSalesDeclarationsTable::annualYearSummary($feb->fresh());

    expect($summary)->toContain('Feb 2026')->toContain('5,000');
});

it('shows the SALES breakpoint (base rent ÷ rate) for a natural-breakpoint lease, not the raw base rent', function () {
    // Natural breakpoint: percentage rent begins when sales × rate = base rent → sales = base ÷ rate.
    // base 50,000 @ 5% → sales breakpoint 1,000,000. Sales of 800,000 owe 0 (below it). The displayed
    // "breakpoint" must be 1,000,000 (comparable to sales), NOT 50,000 (the raw base rent, which would
    // read as sales dwarfing the breakpoint yet owing nothing).
    $lease = makeLease(makeUnit($this->asset), makeTenant(), [
        'status' => 'active', 'has_percentage_rent' => true,
        'percentage_rent_calculation_type' => 'natural_breakpoint',
        'base_rent_monthly' => 50000, 'percentage_rent_rate' => 5,
        'percentage_rent_threshold' => null,
    ]);
    $decl = uxDecl($lease, '2026-01', 800000);

    $w = $this->svc->explain($decl);

    expect($w['method'])->toBe('natural_breakpoint')
        ->and($w['breakpoint'])->toBe(1000000.0)          // sales breakpoint, not 50,000
        ->and($w['this_period_share'])->toBe(0.0)          // 800k < 1M → nothing owed
        // Coherent: overage = (sales − breakpoint) × rate reproduces the charge (here 0).
        ->and(round((800000.0 - $w['breakpoint']) * ($w['rate'] / 100), 2))->toBeLessThanOrEqual(0.0);
});

it('the tenant lock notification carries the annual cumulative context', function () {
    $lease = uxAnnualLease($this);
    $this->svc->lock(uxDecl($lease, '2026-01', 100000), $this->operator);
    $mar = uxDecl($lease, '2026-03', 100000);
    $this->svc->lock($mar, $this->operator); // cumulative 200k, carries 5,000

    $mail = (new SalesDeclarationLockedNotification($mar->fresh()))->toMail($lease->tenant);
    $lines = collect($mail->introLines)->implode(' | ');

    expect($lines)->toContain('200,000')   // the cumulative YTD sales
        ->toContain('150,000');            // the annual breakpoint
});
