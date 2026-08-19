<?php

require __DIR__.'/boot.php';
use App\Filament\Admin\Resources\UnitOwnerships\UnitOwnershipResource;
use App\Models\Asset;
use App\Models\Charge;
use App\Models\Tenant;
use App\Models\Unit;
use App\Models\UnitOwnership;
use App\Services\BillUnitOwnershipsService;
use Carbon\CarbonImmutable;

qa_section('FINDING 1 — an ownership created the way the screen creates it is NEVER billed');
$asset = Asset::where('code', 'AW')->firstOrFail();
$unit = Unit::where('asset_id', $asset->id)->where('status', 'vacant')->firstOrFail();
$owner = Tenant::whereIn('id', UnitOwnership::pluck('tenant_id'))->first();

// Exactly the columns UnitOwnershipForm collects — no charge rows, because no screen offers any.
$o = UnitOwnership::create([
    'asset_id' => $asset->id, 'unit_id' => $unit->id, 'tenant_id' => $owner->id,
    'tenure_type' => 'freehold', 'status' => 'handed_over', 'assessment_basis' => 'area',
    'ownership_share_pct' => 100, 'started_at' => '2026-01-01', 'handover_date' => '2026-01-01',
    'purchase_date' => '2025-12-01', 'purchase_price' => 2500000, 'payment_terms_days' => 15, 'currency' => 'EGP',
]);
qa_ok('ownership created and handed over', $o->exists, $o->reference);
qa_eq('it has no assessment schedule', 0, $o->charges()->count());
qa_ok('…and it IS considered billable', $o->isBillableForPeriod(CarbonImmutable::parse('2026-09-01'), CarbonImmutable::parse('2026-09-30')));

$bill = app(BillUnitOwnershipsService::class);
foreach (['2026-09', '2026-10', '2026-11'] as $m) {
    $p = CarbonImmutable::parse($m.'-01');
    $inv = $bill->billOne($o->fresh(), $p, $p->endOfMonth());
    qa_ok("month $m produced no assessment", $inv === null);
}
$stats = $bill->runForPeriod(CarbonImmutable::parse('2026-09-01'), $asset->id);
printf("\n  scheduled run reports: %s\n", json_encode($stats));
qa_ok('the run reports it as SKIPPED, not failed — nothing surfaces the problem',
    $stats['failed'] === 0 && $stats['skipped'] > 0);

qa_section('…and there is no screen that could have given it one');
$rm = method_exists(UnitOwnershipResource::class, 'getRelations')
    ? UnitOwnershipResource::getRelations() : [];
qa_eq('UnitOwnershipResource has no relation managers', 0, count($rm));
$formFields = file_get_contents(base_path('app/Filament/Admin/Resources/UnitOwnerships/Schemas/UnitOwnershipForm.php'));
qa_ok('the ownership form contains no charge/repeater field', ! str_contains($formFields, 'Repeater') && ! str_contains($formFields, "'charges'"));
qa_ok('the charge importer is lease-only (no ownership column)',
    ! str_contains(file_get_contents(base_path('app/Filament/Imports/ChargeImporter.php')), 'unit_ownership'));

qa_summary();
