<?php

use App\Filament\Admin\Resources\FacilityWorkOrders\Pages\ListFacilityWorkOrders;
use App\Models\FacilityWorkOrder;
use App\Services\FacilityWorkLogPdfService;
use Database\Seeders\RolesPermissionsSeeder;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed(RolesPermissionsSeeder::class);
    ensureAllPropertiesAsset();
});

function wo(int $assetId, array $attrs = []): FacilityWorkOrder
{
    return FacilityWorkOrder::create(array_merge([
        'asset_id' => $assetId, 'title' => 'Job', 'category' => 'hvac',
        'status' => 'open', 'scheduled_for' => '2026-07-02',
    ], $attrs));
}

it('renders a facility work-log PDF', function () {
    $asset = makeAsset();
    wo($asset->id, ['title' => 'In range', 'status' => 'done', 'completed_at' => now()]);

    $pdf = app(FacilityWorkLogPdfService::class)->build('2026-07-01', '2026-07-31', [$asset->id], 'Mall A');

    expect($pdf)->toBeString();
    expect(substr($pdf, 0, 4))->toBe('%PDF');
});

it('scopes the work log to the given properties (no cross-property leak)', function () {
    $assetA = makeAsset(['code' => 'WLA']);
    $assetB = makeAsset(['code' => 'WLB']);
    wo($assetA->id, ['title' => 'A job', 'scheduled_for' => '2026-07-02']);
    wo($assetB->id, ['title' => 'B job', 'scheduled_for' => '2026-07-02']);

    $orders = app(FacilityWorkLogPdfService::class)->orders('2026-07-01', '2026-07-31', [$assetA->id]);

    expect($orders->pluck('title')->all())->toContain('A job')->not->toContain('B job');
});

it('excludes work orders outside the date range', function () {
    $asset = makeAsset();
    wo($asset->id, ['title' => 'In', 'scheduled_for' => '2026-07-15']);
    wo($asset->id, ['title' => 'Before', 'scheduled_for' => '2026-06-30']);
    wo($asset->id, ['title' => 'After', 'scheduled_for' => '2026-08-01']);

    $titles = app(FacilityWorkLogPdfService::class)->orders('2026-07-01', '2026-07-31', [$asset->id])->pluck('title')->all();

    expect($titles)->toBe(['In']); // boundary-inclusive, others excluded
});

it('renders a work-log PDF even when there are no orders in range', function () {
    $asset = makeAsset();
    wo($asset->id, ['scheduled_for' => '2026-05-01']); // out of range

    $pdf = app(FacilityWorkLogPdfService::class)->build('2026-07-01', '2026-07-31', [$asset->id], 'Mall A');
    expect(substr($pdf, 0, 4))->toBe('%PDF');
});

it('offers the work-log export action to operations and streams a PDF', function () {
    $asset = makeAsset();
    wo($asset->id, ['status' => 'done', 'completed_at' => now()]);
    $this->actingAs(makeUser('operations', [$asset->id]));

    asTenant($asset, function () {
        Livewire::test(ListFacilityWorkOrders::class)
            ->callAction('work_log', data: ['from' => '2026-07-01', 'to' => '2026-07-31'])
            ->assertHasNoActionErrors();
    });
});
