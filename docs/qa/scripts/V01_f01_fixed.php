<?php

require __DIR__.'/boot.php';
use App\Filament\Admin\RelationManagers\UnitOwnershipChargesRelationManager;
use App\Filament\Admin\Resources\UnitOwnerships\Pages\EditUnitOwnership;
use App\Filament\Admin\Resources\UnitOwnerships\UnitOwnershipResource;
use App\Models\Asset;
use App\Models\Charge;
use App\Models\Tenant;
use App\Models\Unit;
use App\Models\UnitOwnership;
use App\Models\User;
use App\Services\BillUnitOwnershipsService;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Auth;
use Livewire\Livewire;

$asset = Asset::where('code', 'AW')->firstOrFail();
Filament::setCurrentPanel(Filament::getPanel('admin'));
Filament::setTenant($asset, true);
Auth::login(User::where('email', 'admin@mall.test')->firstOrFail());

qa_section('F-01 FIXED — the resource now has a schedule screen');
$rels = UnitOwnershipResource::getRelations();
// F-01 was "an ownership can never be given an assessment schedule", so what matters is that the
// CHARGES screen is mounted — not how many relation managers there are. A rentable-items manager
// was added later and turned this green check red for a reason nobody changed.
$relNames = array_map(fn ($r) => is_string($r) ? $r : $r->getRelationshipName(), $rels);
qa_ok('the charges (assessment schedule) relation manager is mounted',
    (bool) preg_grep('/Charges/i', $relNames), implode(', ', array_map('class_basename', $relNames)));
qa_eq('…and it is the assessment schedule',
    UnitOwnershipChargesRelationManager::class, $rels[0]);

// A unit with NO existing ownership this period — the baseline seeds handed-over owners, and
// SW-220's overlap guard (2026-09-02) now correctly refuses a second tenure that would push the
// day's total past 100%. The old fixture leaned on "vacant" alone, which does not exclude an
// existing ownership on the same unit.
$owned = UnitOwnership::pluck('unit_id')->all();
$unit = Unit::where('asset_id', $asset->id)->where('status', 'vacant')
    ->whereNotIn('id', $owned)->firstOrFail();
$owner = Tenant::whereIn('id', UnitOwnership::pluck('tenant_id'))->firstOrFail();
$o = UnitOwnership::create(['asset_id' => $asset->id, 'unit_id' => $unit->id, 'tenant_id' => $owner->id,
    'tenure_type' => 'freehold', 'status' => 'handed_over', 'assessment_basis' => 'area', 'ownership_share_pct' => 100,
    'started_at' => '2026-01-01', 'handover_date' => '2026-01-01', 'payment_terms_days' => 15, 'currency' => 'EGP']);

qa_section('the run now REPORTS an un-billable ownership instead of quietly skipping it');
$bill = app(BillUnitOwnershipsService::class);
$p = CarbonImmutable::parse('2026-09-01');
$stats = $bill->runForPeriod($p, $asset->id);
printf("  %s\n", json_encode($stats));
qa_ok('the stats carry an "unconfigured" counter', array_key_exists('unconfigured', $stats));
qa_ok('…and this ownership is counted in it', $stats['unconfigured'] >= 1);

qa_section('the schedule screen is REACHABLE and gated');
$rm = Livewire::test(UnitOwnershipChargesRelationManager::class, [
    'ownerRecord' => $o->fresh(), 'pageClass' => EditUnitOwnership::class,
]);
qa_ok('the relation manager mounts', true);
$table = $rm->instance()->getTable();
$named = collect($table->getHeaderActions())->keyBy(fn ($a) => $a->getName());
qa_ok('the schedule carries an "add assessment" header action', $named->has('addAssessment'), $named->keys()->join(','));
$add = $named->get('addAssessment');
qa_ok('…visible to a user who may edit ownerships', $add->isVisible());
qa_ok('…and authorized', $add->isAuthorized());
$recNamed = collect($table->getRecordActions())->keyBy(fn ($a) => $a->getName());
qa_ok('…and an "end assessment" row action', $recNamed->has('endAssessment'), $recNamed->keys()->join(','));

// the gate, measured by removing the permission rather than by reading the source
$restricted = User::factory()->create();
$restricted->syncRoles(['viewer']);
Auth::login($restricted);
$rm2 = Livewire::test(UnitOwnershipChargesRelationManager::class, [
    'ownerRecord' => $o->fresh(), 'pageClass' => EditUnitOwnership::class,
]);
$add2 = collect($rm2->instance()->getTable()->getHeaderActions())->keyBy(fn ($a) => $a->getName())->get('addAssessment');
qa_ok('a viewer cannot add an assessment', ! $add2->isVisible() && ! $add2->isAuthorized());
Auth::login(User::where('email', 'admin@mall.test')->firstOrFail());

qa_section('a schedule row makes the owner billable');
$c = Charge::create(['unit_ownership_id' => $o->id, 'name' => 'صيانة', 'type' => 'service_charge',
    'amount' => 2500, 'currency' => 'EGP', 'frequency' => 'monthly', 'vat_applicable' => true,
    'vat_rate' => null, 'start_date' => '2026-01-01', 'is_active' => true]);
qa_eq('the charge hangs off the ownership, not a lease', $o->id, (int) $c->unit_ownership_id);
qa_ok('…with no VAT override (the catalogue answers at billing time)', $c->vat_rate === null);

qa_section('…and the owner is now actually billed');
$oct = CarbonImmutable::parse('2026-10-01');
$inv = $bill->billOne($o->fresh(), $oct, $oct->endOfMonth());
qa_ok('an assessment invoice is raised', $inv !== null, $inv?->number);
qa_eq('…for the assessment plus VAT', round(2500 * 1.14, 2), (float) $inv->total);
qa_eq('…billed to the owner', $o->tenant_id, (int) $inv->tenant_id);
$stats2 = $bill->runForPeriod($oct, $asset->id);
printf("  October run: %s\n", json_encode($stats2));
qa_eq('it is no longer counted as unconfigured', 0, $stats2['unconfigured']);

qa_section('overlap is now refused on an OWNERSHIP schedule too (was lease-only)');
qa_refuses('a second open monthly row of the same type is refused',
    fn () => Charge::create(['unit_ownership_id' => $o->id, 'name' => 'صيانة dup', 'type' => 'service_charge',
        'amount' => 3000, 'currency' => 'EGP', 'frequency' => 'monthly', 'vat_applicable' => true,
        'vat_rate' => null, 'start_date' => '2026-06-01', 'is_active' => true]));
qa_allows('…but a one-off in the same month is fine',
    fn () => Charge::create(['unit_ownership_id' => $o->id, 'name' => 'One-off levy', 'type' => 'other',
        'amount' => 500, 'currency' => 'EGP', 'frequency' => 'one_time', 'vat_applicable' => false,
        'vat_rate' => null, 'start_date' => '2026-06-01', 'is_active' => true]));
qa_allows('…and a row starting after the first one is closed is fine', function () use ($o) {
    $o->charges()->where('type', 'service_charge')->where('frequency', 'monthly')
        ->update(['end_date' => '2026-12-31', 'is_active' => false]);
    Charge::create(['unit_ownership_id' => $o->id, 'name' => 'صيانة 2027', 'type' => 'service_charge',
        'amount' => 2800, 'currency' => 'EGP', 'frequency' => 'monthly', 'vat_applicable' => true,
        'vat_rate' => null, 'start_date' => '2027-01-01', 'is_active' => true]);
});

qa_summary();
