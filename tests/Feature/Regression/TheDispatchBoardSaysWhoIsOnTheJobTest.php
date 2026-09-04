<?php

use App\Filament\Admin\Resources\FacilityWorkOrders\Pages\ListFacilityWorkOrders;
use App\Models\FacilityWorkOrder;
use App\Models\Vendor;
use Database\Seeders\RolesPermissionsSeeder;
use Livewire\Livewire;

/**
 * The work-orders list is the dispatch board, and it could not say who was on the job.
 *
 * Measured at HEAD on 2026-09-03 (SW-078). `FacilityWorkOrdersTable::configure()` rendered nineteen
 * columns — reference, type, title, property, area, equipment, scheduled date, progress, priority,
 * both SLA clocks, penalty, cost bearer, status, cost, variance, repeat visit, over-NTE, PM
 * compliance — and neither `vendor_id` nor `assigned_to_user_id`, both of which have been on the row
 * since the module shipped and both of which already drive a notification. So the one question a
 * dispatcher opens this screen to answer needed a record page opened per job.
 *
 * ONE column, not two. `FacilityWorkOrder::booted()` enforces an XOR on a CORRECTIVE order — an
 * internal job may not name a vendor and an external one may not name a technician — so a pair of
 * columns would render one blank cell per row for ever. A preventive order is outside that guard and
 * may carry both, which is why the vendor is the headline and the technician sits beneath it.
 */
beforeEach(function () {
    $this->seed(RolesPermissionsSeeder::class);
    ensureAllPropertiesAsset();
    $this->asset = makeAsset();
    $this->actingAs(makeUser('super_admin', [$this->asset->id]));
});

it('names the contractor on an external job and the technician on an internal one', function () {
    $vendor = Vendor::create(['name' => 'Cool Air Co', 'status' => Vendor::STATUS_ACTIVE]);
    $technician = makeUser('operations', [$this->asset->id]);

    $external = FacilityWorkOrder::create([
        'asset_id' => $this->asset->id,
        'work_order_type' => FacilityWorkOrder::TYPE_CM,
        'execution_type' => FacilityWorkOrder::EXECUTION_EXTERNAL,
        'title' => 'Chiller down',
        'description' => 'No cooling on level 2.',
        'trade_id' => tradeId('hvac'),
        'status' => 'open',
        'priority' => 'high',
        'scheduled_for' => now()->toDateString(),
        'vendor_id' => $vendor->id,
    ]);

    $internal = FacilityWorkOrder::create([
        'asset_id' => $this->asset->id,
        'work_order_type' => FacilityWorkOrder::TYPE_CM,
        'execution_type' => FacilityWorkOrder::EXECUTION_INTERNAL,
        'title' => 'Lamp out, food court',
        'description' => 'Two downlights out over the seating.',
        'trade_id' => tradeId('hvac'),
        'status' => 'open',
        'priority' => 'low',
        'scheduled_for' => now()->toDateString(),
        'assigned_to_user_id' => $technician->id,
    ]);

    $nobody = FacilityWorkOrder::create([
        'asset_id' => $this->asset->id,
        'work_order_type' => FacilityWorkOrder::TYPE_PPM,
        'title' => 'Monthly AHU round',
        'trade_id' => tradeId('hvac'),
        'status' => 'open',
        'priority' => 'medium',
        'scheduled_for' => now()->toDateString(),
    ]);

    asTenant($this->asset, function () use ($external, $internal, $nobody, $vendor, $technician) {
        $list = Livewire::test(ListFacilityWorkOrders::class);

        $list->assertTableColumnStateSet('handled_by', $vendor->name, $external)
            ->assertTableColumnStateSet('handled_by', $technician->name, $internal);

        // `assertTableColumnStateSet` compares with assertEquals, under which '' == null — so the
        // "nobody has it" case is asserted strictly here instead, or the assertion could not fail.
        $column = $list->instance()->getTable()->getColumn('handled_by');
        $column->record($nobody);
        $column->clearCachedState();

        expect($column->getState())->toBeNull();
    });
});

it('loads the contractor and the technician with the page, not once per row', function () {
    $vendor = Vendor::create(['name' => 'Lift Services Ltd', 'status' => Vendor::STATUS_ACTIVE]);

    foreach (range(1, 3) as $i) {
        FacilityWorkOrder::create([
            'asset_id' => $this->asset->id,
            'work_order_type' => FacilityWorkOrder::TYPE_CM,
            'execution_type' => FacilityWorkOrder::EXECUTION_EXTERNAL,
            'title' => "Lift {$i} stuck",
            'description' => 'Car stopped between floors.',
            'trade_id' => tradeId('hvac'),
            'status' => 'open',
            'priority' => 'urgent',
            'scheduled_for' => now()->toDateString(),
            'vendor_id' => $vendor->id,
        ]);
    }

    asTenant($this->asset, function () {
        $rows = tableRows(Livewire::test(ListFacilityWorkOrders::class));

        // The premise: a sweep over an empty page would pass while proving nothing.
        expect($rows)->toHaveCount(3);

        foreach ($rows as $row) {
            expect($row->relationLoaded('vendor'))
                ->toBeTrue('the who-is-on-it column reads `vendor` on every row; without the eager load that is one query per row')
                ->and($row->relationLoaded('assignee'))
                ->toBeTrue('the who-is-on-it column reads `assignee` on every row; without the eager load that is one query per row');
        }
    });
});
