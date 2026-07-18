<?php

use App\Filament\Admin\Widgets\ArAging;
use App\Filament\Admin\Widgets\LeasingPipeline;
use App\Filament\Admin\Widgets\MallStats;
use App\Filament\Admin\Widgets\MonthlyRevenueTrend;
use App\Filament\Admin\Widgets\RecentPayments;
use App\Filament\Admin\Widgets\TenantMix;
use Database\Seeders\RolesPermissionsSeeder;

/**
 * FR-DASH-02 — "the dashboard shall be role-aware, showing each user only the data relevant to
 * their scope of work."
 *
 * THE BUG. Every dashboard widget's allowedRoles() listed manager/viewer/leasing/operations —
 * and the `accounting` role, which owns invoices, payments, AR, credit notes and the GL, was in
 * NONE of them. So the role whose entire job is the money landed on an EMPTY dashboard: the
 * opposite of role-aware. Flagged in the FRD gap analysis and confirmed live before this fix
 * (accounting saw 0 of 13 widgets).
 */
beforeEach(function () {
    $this->seed(RolesPermissionsSeeder::class);
});

it('shows an accounting user its financial widgets, not an empty dashboard', function () {
    $this->actingAs(makeUser('accounting', [makeAsset()->id]));

    // The widgets whose domain IS accounting's scope of work.
    expect(ArAging::canView())->toBeTrue();            // accounts receivable aging
    expect(MonthlyRevenueTrend::canView())->toBeTrue(); // revenue
    expect(RecentPayments::canView())->toBeTrue();      // payments
    expect(MallStats::canView())->toBeTrue();           // invoiced / collected headline
});

it('does not hand accounting the leasing-only widgets', function () {
    // Role-aware cuts both ways: accounting sees the money, not the leasing pipeline.
    $this->actingAs(makeUser('accounting', [makeAsset()->id]));

    expect(LeasingPipeline::canView())->toBeFalse();
    expect(TenantMix::canView())->toBeFalse();
});

it('still shows a manager its dashboard', function () {
    // The fix must not narrow anyone: manager keeps the widgets it had.
    $this->actingAs(makeUser('manager', [makeAsset()->id]));

    expect(ArAging::canView())->toBeTrue();
    expect(MallStats::canView())->toBeTrue();
});
