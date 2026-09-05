<?php

use App\Filament\Admin\Widgets\EnergyConsumptionTrend;
use App\Filament\Admin\Widgets\MonthlyRevenueTrend;
use Database\Seeders\RolesPermissionsSeeder;
use Filament\Facades\Filament;

/**
 * The two trend charts drill through, like every other card on the dashboard (UX5-06's remainder).
 *
 * `MallStats` got its drill-downs on 2026-09-05; the charts were left because a `ChartWidget` has
 * no header-action slot — its chrome is heading, description and filters, and nothing else. The
 * link goes in the DESCRIPTION, which works because Blade's `{{ }}` calls `e()`, and `e()` returns
 * an `Htmlable` unescaped. That is the supported slot rather than an override of Filament's view.
 *
 * WHICH destination is the substance: the revenue chart plots invoices billed against collected,
 * so it goes to the invoice register; the energy chart plots meter readings, so it goes to the
 * meters. A link to a report would answer a question the bars are not asking.
 */
beforeEach(function () {
    $this->seed(RolesPermissionsSeeder::class);
    ensureAllPropertiesAsset();
    $this->asset = makeAsset();
    Filament::setCurrentPanel(Filament::getPanel('admin'));
});

afterEach(fn () => Filament::setTenant(null, isQuiet: true));

/** A widget's rendered description for the given role. */
function trendDescription(string $widget, string $role, $asset): string
{
    test()->actingAs(makeUser($role, [$asset->id]));
    Filament::setTenant($asset);

    return (string) (new $widget)->getDescription();
}

it('sends the revenue chart to the invoices and the energy chart to the meters', function () {
    expect(trendDescription(MonthlyRevenueTrend::class, 'super_admin', $this->asset))
        ->toContain('/invoices')
        ->and(trendDescription(EnergyConsumptionTrend::class, 'super_admin', $this->asset))
        ->toContain('/utility-meters');
});

it('lands on a clean list rather than wherever the operator left it', function () {
    // A link with an EMPTY query string is indistinguishable from a bare page load, so
    // `TableDefaults` restores the last filters and a saved default view redirects away — the
    // same trap the MallStats cards carry `tableView=none` for.
    expect(trendDescription(MonthlyRevenueTrend::class, 'super_admin', $this->asset))
        ->toContain('tableView=none');
});

it('offers no link to a role that cannot open the register', function () {
    // `marketing` holds neither invoices.view nor utility_meters.view. A caption that links into
    // a 403 reads as the system being broken rather than as not-for-you.
    $revenue = trendDescription(MonthlyRevenueTrend::class, 'marketing', $this->asset);
    $energy = trendDescription(EnergyConsumptionTrend::class, 'marketing', $this->asset);

    expect($revenue)->not->toContain('<a ')
        ->and($energy)->not->toContain('<a ')
        // …and the caption itself survives: suppressing the link must not blank the description.
        ->and($revenue)->not->toBe('')
        ->and($energy)->not->toBe('');
});
