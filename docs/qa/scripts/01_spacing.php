<?php

require __DIR__.'/boot.php';
use App\Models\Area;
use App\Models\Asset;
use App\Models\Floor;
use App\Models\Lease;
use App\Models\Tenant;
use App\Models\Unit;
use App\Services\RemeasureUnitService;
use Carbon\CarbonImmutable;

/* ── fixtures: a private QA property so demo numbers stay untouched ───────── */
$qaAsset = Asset::firstOrCreate(['code' => 'QAL'], [
    'name' => 'QA Lab Mall', 'type' => 'mall', 'city' => 'Cairo', 'country' => 'Egypt',
    'currency' => 'EGP', 'is_active' => true, 'total_area_sqm' => 10000, 'leasable_area_sqm' => 8000,
]);
$other = Asset::where('code', 'AW')->firstOrFail();
$fl = Floor::firstOrCreate(['asset_id' => $qaAsset->id, 'code' => 'G'], ['name' => 'Ground', 'level' => 0]);
$flOther = Floor::firstOrCreate(['asset_id' => $other->id, 'code' => 'QAX'], ['name' => 'Foreign floor', 'level' => 9]);
$zone = Area::firstOrCreate(['asset_id' => $qaAsset->id, 'code' => 'Z1'], ['name' => 'Zone 1']);
$zoneOther = Area::where('asset_id', $other->id)->first();

qa_section('SPACING 1 — Unit creation & cross-property integrity');

$u1 = Unit::firstOrCreate(['asset_id' => $qaAsset->id, 'code' => 'QA-01'],
    ['floor_id' => $fl->id, 'area_id' => $zone->id, 'category' => 'retail', 'area_sqm' => 100, 'status' => 'vacant']);
qa_ok('unit created in QA property', $u1->exists);
qa_eq('opening dated area row written on create', 1, $u1->areas()->count());
qa_eq('areaOn(today) = column', 100.0, $u1->areaOn());

qa_refuses('raw write refuses a FLOOR from another property',
    fn () => Unit::create(['asset_id' => $qaAsset->id, 'code' => 'QA-X1', 'floor_id' => $flOther->id, 'category' => 'retail', 'area_sqm' => 50, 'status' => 'vacant']));

if ($zoneOther) {
    qa_refuses('raw write refuses a ZONE from another property',
        fn () => Unit::create(['asset_id' => $qaAsset->id, 'code' => 'QA-X2', 'area_id' => $zoneOther->id, 'category' => 'retail', 'area_sqm' => 50, 'status' => 'vacant']));
} else {
    echo "  (skipped zone test — no zone on AW)\n";
}

qa_refuses('negative area refused at the model',
    fn () => Unit::create(['asset_id' => $qaAsset->id, 'code' => 'QA-NEG', 'category' => 'retail', 'area_sqm' => -5, 'status' => 'vacant']));

// duplicate code per asset
try {
    Unit::create(['asset_id' => $qaAsset->id, 'code' => 'QA-01', 'category' => 'retail', 'area_sqm' => 10, 'status' => 'vacant']);
    qa_ok('duplicate unit code within a property is refused', false, 'it was accepted');
} catch (Throwable $e) {
    qa_ok('duplicate unit code within a property is refused', str_contains($e->getMessage(), 'Duplicate') || str_contains($e->getMessage(), 'Integrity'), get_class($e));
}

// out-of-set status
qa_refuses('out-of-set unit status refused by ValueSets listener',
    fn () => Unit::create(['asset_id' => $qaAsset->id, 'code' => 'QA-BAD', 'category' => 'retail', 'area_sqm' => 10, 'status' => 'demolished']),
    null, Throwable::class);

qa_section('SPACING 2 — Remeasurement (dated area register)');
$svc = app(RemeasureUnitService::class);

qa_refuses('remeasure to zero refused', fn () => $svc->record($u1, 0));
qa_refuses('remeasure to negative refused', fn () => $svc->record($u1, -10));

$r = $svc->record($u1->fresh(), 120, ['effective_from' => '2026-06-01', 'reason' => 'Re-survey']);
$u1->refresh();
qa_eq('after remeasure column = 120', 120.0, (float) $u1->area_sqm);
qa_eq('areaOn(2026-05-31) still the OLD area', 100.0, $u1->areaOn(CarbonImmutable::parse('2026-05-31')));
qa_eq('areaOn(2026-06-01) = the NEW area', 120.0, $u1->areaOn(CarbonImmutable::parse('2026-06-01')));
qa_eq('two dated rows now', 2, $u1->areas()->count());
$closed = $u1->areas()->whereNotNull('effective_to')->first();
qa_eq('the closed row ends the day before', '2026-05-31', optional($closed->effective_to)->format('Y-m-d'));

// idempotence
$again = $svc->record($u1->fresh(), 120, ['effective_from' => '2026-07-01']);
qa_eq('re-recording the SAME area opens no new row', 2, $u1->fresh()->areas()->count());

// backdating before the row in force
qa_refuses('remeasure dated at/before the row it would close is refused',
    fn () => $svc->record($u1->fresh(), 130, ['effective_from' => '2026-06-01']));

// future-dated remeasure must NOT move the headline column
$future = CarbonImmutable::now()->addYear()->toDateString();
$svc->record($u1->fresh(), 150, ['effective_from' => $future]);
$u1->refresh();
qa_eq('future-dated remeasure leaves the CURRENT column alone', 120.0, (float) $u1->area_sqm);
qa_eq('areaOn(today) still 120', 120.0, $u1->areaOn());
qa_eq('areaOn(future) = 150', 150.0, $u1->areaOn(CarbonImmutable::parse($future)));

qa_refuses('a plain edit to area_sqm is refused once dated rows exist',
    fn () => tap($u1->fresh())->update(['area_sqm' => 999]));

qa_section('SPACING 3 — Occupancy projection');
$t = Tenant::first();
$u2 = Unit::firstOrCreate(['asset_id' => $qaAsset->id, 'code' => 'QA-02'], ['floor_id' => $fl->id, 'category' => 'retail', 'area_sqm' => 200, 'status' => 'vacant']);
$u3 = Unit::firstOrCreate(['asset_id' => $qaAsset->id, 'code' => 'QA-03'], ['floor_id' => $fl->id, 'category' => 'kiosk', 'area_sqm' => 20, 'status' => 'vacant']);

$lease = Lease::create([
    'asset_id' => $qaAsset->id, 'tenant_id' => $t->id, 'unit_id' => $u2->id,
    'reference' => 'QA-LSE-'.uniqid(), 'status' => 'draft',
    'commencement_date' => '2026-01-01', 'expiry_date' => '2027-12-31', 'term_months' => 24,
    'base_rent_monthly' => 10000, 'billing_frequency' => 'monthly']);
qa_eq('draft lease projects unit to reserved', 'reserved', $u2->fresh()->status);
$lease->update(['status' => 'active']);
qa_eq('active lease projects unit to occupied', 'occupied', $u2->fresh()->status);
// terminal statuses are immutable — prove that, then use a throwaway lease for the vacancy path
$tmp = Lease::create([
    'asset_id' => $qaAsset->id, 'tenant_id' => $t->id, 'unit_id' => $u3->id,
    'reference' => 'QA-TMP-'.uniqid(), 'status' => 'active',
    'commencement_date' => '2026-01-01', 'expiry_date' => '2026-03-31', 'term_months' => 3,
    'base_rent_monthly' => 1000, 'billing_frequency' => 'monthly']);
qa_eq('active lease occupies the second unit', 'occupied', $u3->fresh()->status);
$tmp->update(['status' => 'expired']);
qa_eq('expired lease projects unit back to vacant', 'vacant', $u3->fresh()->status);
qa_refuses('a terminal (expired) lease is immutable', fn () => $tmp->fresh()->update(['status' => 'active']));

// maintenance override
$u3->update(['status' => 'maintenance']);
$lease->syncUnits([$u2->id, $u3->id], $u2->id);
qa_eq('maintenance override survives an active lease', 'maintenance', $u3->fresh()->status);
qa_eq('multi-unit: master stays occupied', 'occupied', $u2->fresh()->status);
qa_eq('exactly one is_master row', 1, DB::table('lease_unit')->where('lease_id', $lease->id)->where('is_master', 1)->count());

// self-heal an operator-authored status on a lease-less unit
$u4 = Unit::firstOrCreate(['asset_id' => $qaAsset->id, 'code' => 'QA-04'], ['floor_id' => $fl->id, 'category' => 'retail', 'area_sqm' => 50, 'status' => 'vacant']);
$u4->update(['status' => 'occupied']);
$u4->recomputeStatus();
qa_eq('a lease-less unit self-heals to vacant', 'vacant', $u4->fresh()->status);

// soft-delete a lease → units re-project
$u3->update(['status' => 'vacant']);
$lease->refresh();
$lease->delete();
qa_eq('soft-deleting a lease frees the master unit', 'vacant', $u2->fresh()->status);
qa_eq('soft-deleting a lease frees the additional unit', 'vacant', $u3->fresh()->status);
$lease->restore();
qa_eq('restoring the lease re-occupies the master', 'occupied', $u2->fresh()->status);

qa_section('SPACING 4 — Occupancy metrics');
$a = $qaAsset->fresh();
$total = $a->units()->count();
$occ = $a->units()->where('status', 'occupied')->count();
qa_eq('occupancyRate = occupied/total', round($occ / max($total, 1) * 100, 1), $a->occupancyRate());
$occArea = (float) $a->units()->where('status', 'occupied')->sum('area_sqm');
$totArea = (float) $a->units()->sum('area_sqm');
qa_eq('areaOccupancyRate is GLA-weighted', round($occArea / max($totArea, 0.0001) * 100, 1), $a->areaOccupancyRate(), 0.05);

$empty = Asset::firstOrCreate(['code' => 'QAE'], ['name' => 'QA Empty', 'type' => 'mall', 'city' => 'Cairo', 'country' => 'Egypt', 'currency' => 'EGP', 'is_active' => true]);
qa_eq('no units → occupancyRate 0.0 (no divide-by-zero)', 0.0, $empty->occupancyRate());
qa_eq('no units → areaOccupancyRate 0.0', 0.0, $empty->areaOccupancyRate());

qa_section('SPACING 5 — Deletion policy');
qa_refuses('a unit that has been leased cannot be deleted', fn () => $u2->fresh()->delete(), null, Throwable::class);
qa_allows('a never-leased unit can be deleted', fn () => $u4->fresh()->delete());

qa_summary();
