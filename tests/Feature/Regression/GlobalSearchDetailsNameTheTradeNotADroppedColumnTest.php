<?php

/*
|--------------------------------------------------------------------------
| A search result names the TRADE, not a column that no longer exists (SW-076)
|--------------------------------------------------------------------------
| `facility_work_orders.category` and `equipment.category` were both dropped when the Trade
| catalogue replaced them — the trade is the routing spine, and two columns answering "what kind of
| work is this" would be two truths about one question. Both resources' global-search details were
| left reading `$record->category`.
|
| **A missing attribute resolves NULL rather than throwing**, which is the whole reason this
| survived: the top-bar result rendered a permanently blank `Category` row where the routing spine
| should be, on both resources, and a blank row reads as "this record has no trade" rather than as a
| bug — so it was never reported. The tables beside them have read `$record->trade?->label()` all
| along; the two doors onto one fact had been allowed to disagree.
|
| The work order's priority was the second half: printed as the raw stored code, so an Arabic
| operator read `high` in a list of otherwise translated details. `FacilityVocabulary` is the one
| vocabulary its table, its filter and now its search result all resolve through.
|
| Verified at HEAD 2026-09-05 in a booted app: `Schema::getColumns()` reports `category` absent from
| BOTH tables and `trade_id` present on both.
*/

use App\Filament\Admin\Resources\Equipment\EquipmentResource;
use App\Filament\Admin\Resources\FacilityWorkOrders\FacilityWorkOrderResource;
use App\Models\Equipment;
use App\Models\FacilityWorkOrder;
use App\Models\Trade;
use Database\Seeders\RolesPermissionsSeeder;
use Illuminate\Support\Facades\Schema;

beforeEach(function () {
    $this->seed(RolesPermissionsSeeder::class);
    ensureAllPropertiesAsset();

    $this->asset = makeAsset(['code' => 'GSD']);
    $this->trade = Trade::firstOrCreate(
        ['code' => 'hvac'],
        ['name_en' => 'HVAC', 'name_ar' => 'تكييف', 'is_active' => true],
    );

    $this->actingAs(makeUser('super_admin', [$this->asset->id]));
});

afterEach(fn () => app()->setLocale('en'));

it('proves its own premise — the column really is gone from both tables', function () {
    // If `category` ever comes back this test is measuring nothing, and the fix it guards would be
    // the wrong fix. Same reasoning as every other premise assertion in this suite.
    foreach (['facility_work_orders', 'equipment'] as $table) {
        $columns = array_column(Schema::getColumns($table), 'name');

        expect($columns)->not->toContain('category')
            ->and($columns)->toContain('trade_id');
    }
});

it('names the work order’s trade where it used to render a blank row', function () {
    $order = FacilityWorkOrder::create([
        'asset_id' => $this->asset->id,
        'work_order_type' => 'cm',
        'execution_type' => 'internal',
        'title' => 'Chiller down',
        'description' => 'No cooling on level 2',
        'trade_id' => $this->trade->id,
        'priority' => 'high',
        'scheduled_for' => '2026-09-01',
        'status' => 'open',
    ]);

    $details = FacilityWorkOrderResource::getGlobalSearchResultDetails($order->fresh());

    // The VALUE under the trade's own key — the old code produced a present key with a NULL value,
    // so asserting on keys alone passes on the defect, and asserting "no nulls anywhere" would fail
    // for a legitimate reason (this order has no unit, so that row is honestly empty).
    expect($details[__('admin.facility.fields.trade')] ?? null)->toBe($this->trade->label())
        ->and($details)->not->toHaveKey(__('admin.fields.category'));
});

it('translates the work order’s priority instead of printing the stored code', function () {
    $order = FacilityWorkOrder::create([
        'asset_id' => $this->asset->id,
        'work_order_type' => 'cm',
        'execution_type' => 'internal',
        'title' => 'Lift stuck',
        'description' => 'Between 2 and 3',
        'trade_id' => $this->trade->id,
        'priority' => 'high',
        'scheduled_for' => '2026-09-01',
        'status' => 'open',
    ]);

    app()->setLocale('ar');
    $arabic = FacilityWorkOrderResource::getGlobalSearchResultDetails($order->fresh());

    // The raw code must not survive into the Arabic panel, and the label must actually be Arabic —
    // `Lang::has()` falling back to English is how a parity check passes for the wrong reason.
    expect(array_values($arabic))->not->toContain('high')
        ->and(implode(' ', array_map('strval', array_values($arabic))))
        ->toMatch('/\p{Arabic}/u');
});

it('names the equipment’s trade too — the same defect through the other door', function () {
    $equipment = Equipment::create([
        'asset_id' => $this->asset->id,
        'code' => 'EQ-1',
        'name_en' => 'Chiller 1',
        'name_ar' => 'مبرد 1',
        'trade_id' => $this->trade->id,
        'location' => 'Roof',
    ]);

    $details = EquipmentResource::getGlobalSearchResultDetails($equipment->fresh());

    expect($details[__('admin.facility.fields.trade')] ?? null)->toBe($this->trade->label())
        ->and($details)->not->toHaveKey(__('admin.fields.category'));
});

it('eager-loads the trade, so the details are not a query per keystroke', function () {
    // The details fire per ROW of a live-search dropdown. Both resources state this reason in a
    // docblock; without the eager load the fix trades a blank row for an N+1 on every keystroke.
    foreach ([FacilityWorkOrderResource::class, EquipmentResource::class] as $resource) {
        expect(array_keys($resource::getGlobalSearchEloquentQuery()->getEagerLoads()))
            ->toContain('trade');
    }
});
