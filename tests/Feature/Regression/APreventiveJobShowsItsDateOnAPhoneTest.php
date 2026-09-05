<?php

use App\Filament\Admin\Resources\FacilityWorkOrders\Pages\ListFacilityWorkOrders;
use App\Models\FacilityWorkOrder;
use Database\Seeders\RolesPermissionsSeeder;
use Filament\Facades\Filament;
use Livewire\Livewire;

/**
 * A preventive job shows its date and its equipment on a phone (UX5-05).
 *
 * The phone list is deliberately six columns and one of them is "by when" — but that column was
 * `target_resolution_at`, the SLA clock, which only a CORRECTIVE order carries. A PREVENTIVE one
 * answers to its plan, whose due date is `scheduled_for`, and that column sits behind
 * `visibleFrom('md')`. So on the bulk of a technician's round the phone rendered a dash where the
 * date belongs, and the equipment code — which of eleven lifts — was desk-only too.
 *
 * The admin panel IS the technician's tool: a technician app was declined (O3), so this list is
 * what somebody standing at the equipment actually reads.
 */
beforeEach(function () {
    $this->seed(RolesPermissionsSeeder::class);
    $this->asset = makeAsset();
    $this->actingAs(makeUser('super_admin', [$this->asset->id]));
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    Filament::setTenant($this->asset, isQuiet: true);
});

afterEach(fn () => Filament::setTenant(null, isQuiet: true));

/** The rendered state of one column for one record, through the real table. */
function columnState(FacilityWorkOrder $record, string $column)
{
    $page = Livewire::test(ListFacilityWorkOrders::class)->instance();
    $col = $page->getTable()->getColumn($column)->record($record);

    return $col->getState();
}

it('puts the plan due date in the by-when column for a preventive job', function () {
    $pm = correctiveOrder([
        'work_order_type' => FacilityWorkOrder::TYPE_PPM,
        'target_resolution_at' => null,          // a PM carries no SLA clock
        'scheduled_for' => now()->addDays(3)->startOfDay(),
    ]);

    // The column a phone keeps must answer for this job, not render a dash.
    expect(columnState($pm, 'target_resolution_at'))->not->toBeNull()
        ->and(columnState($pm, 'target_resolution_at')->toDateString())
        ->toBe(now()->addDays(3)->toDateString());
});

it('still shows the SLA clock on a corrective job — the control', function () {
    // Without this, a fallback that simply returned `scheduled_for` for everything would satisfy
    // the case above while silently replacing the SLA deadline on every corrective job.
    $target = now()->addHours(6);

    $cm = correctiveOrder([
        'target_resolution_at' => $target,
        'scheduled_for' => now()->addDays(30),
    ]);

    expect(columnState($cm, 'target_resolution_at')->format('Y-m-d H'))->toBe($target->format('Y-m-d H'));
});

it('names the equipment under the title, where a phone can read it', function () {
    $equipment = \App\Models\Equipment::create([
        'asset_id' => $this->asset->id,
        'code' => 'LIFT-07',
        'name_en' => 'Passenger lift 7',
        'name_ar' => 'مصعد ركاب ٧',
        'trade_id' => tradeId('hvac'),
        'status' => 'active',
    ]);

    $pm = correctiveOrder([
        'work_order_type' => FacilityWorkOrder::TYPE_PPM,
        'equipment_id' => $equipment->id,
        'title' => 'Quarterly service',
    ]);

    $page = Livewire::test(ListFacilityWorkOrders::class)->instance();
    $description = $page->getTable()->getColumn('title')->record($pm->fresh())->getDescriptionBelow();

    expect((string) $description)->toContain('LIFT-07');
});

it('still describes a job with no equipment by its trade — the control', function () {
    // The description carried the trade before this change, and a job with no equipment has no
    // other classification. Replacing it outright would have traded one blank for another.
    $cm = correctiveOrder(['equipment_id' => null]);

    $page = Livewire::test(ListFacilityWorkOrders::class)->instance();
    $description = (string) $page->getTable()->getColumn('title')->record($cm->fresh())->getDescriptionBelow();

    expect($description)->not->toBe('')
        ->and($description)->not->toContain('LIFT-');
});
