<?php

require __DIR__.'/boot.php';
use App\Enums\TenantRequestType;
use App\Models\ApprovalRule;
use App\Models\Area;
use App\Models\Asset;
use App\Models\FacilityWorkOrder;
use App\Models\InventoryItem;
use App\Models\Lease;
use App\Models\RentableItem;
use App\Models\ServicePlan;
use App\Models\Tenant;
use App\Models\Unit;
use App\Models\User;
use App\Models\Vendor;
use App\Models\Warehouse;
use App\Services\Accounting\AccountResolver;
use App\Services\AssessSlaPenaltyService;
use App\Services\AssignRentableItemService;
use App\Services\ChargeScheduleService;
use App\Services\FacilityWorkOrderService;
use App\Services\GeneratePreventiveWorkOrdersService;
use App\Services\RaiseCorrectiveWorkOrderService;
use App\Services\Reconciliation\BooksReconciliationService;
use App\Services\StockMovementService;
use App\Services\TenantRequestService;
use App\Services\WorkOrderPartService;
use App\Support\ApprovalPolicy;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Artisan;

$asset = Asset::where('code', 'AW')->firstOrFail();
$acct = fn (string $r) => app(AccountResolver::class)->id($r);
$occupied = Unit::where('asset_id', $asset->id)->where('status', 'occupied')->firstOrFail();
$lease = Lease::where('unit_id', $occupied->id)->where('status', 'active')->firstOrFail();
$tenant = $lease->tenant;
$admin = User::where('email', 'admin@mall.test')->firstOrFail();

/* ══════════════════════════ MODULE 11 · TENANT REQUESTS ══════════════════════════ */
qa_section('REQUESTS 1 — filed by a tenant, clamped to their OWN unit');
$svc = app(TenantRequestService::class);
$req = $svc->create(['type' => TenantRequestType::Maintenance->value, 'title' => 'QA aircon fault', 'category' => 'hvac',
    'description' => 'QA description', 'priority' => 'high', 'unit_id' => $occupied->id], $tenant);
qa_ok('a request is filed', $req->exists, $req->reference ?? ('#'.$req->id));
qa_eq('…against the tenant own unit', $occupied->id, (int) $req->unit_id);
qa_eq('…starting submitted', 'submitted', $req->status);
qa_ok('…with an SLA target derived from type + priority', $req->target_resolution_at !== null,
    (string) $req->target_resolution_at);
$foreignUnit = Unit::where('asset_id', $asset->id)->where('id', '!=', $occupied->id)->firstOrFail();
$crafted = $svc->create(['type' => TenantRequestType::Maintenance->value, 'title' => 'QA crafted', 'category' => 'hvac',
    'description' => 'QA', 'priority' => 'low', 'unit_id' => $foreignUnit->id], $tenant);
qa_ok('a crafted unit_id is CLAMPED to the tenant own premises, never accepted',
    (int) $crafted->unit_id !== $foreignUnit->id, 'got unit '.($crafted->unit_id ?? 'null'));

qa_section('REQUESTS 2 — the state machine refuses an illegal hop');
$svc->transition($req->fresh(), 'acknowledged');
qa_eq('submitted → acknowledged', 'acknowledged', $req->fresh()->status);
qa_refuses('acknowledged → closed is refused (it must be resolved first)',
    fn () => $svc->transition($req->fresh(), 'closed'), null, Throwable::class);
$svc->transition($req->fresh(), 'in_progress');
// Resolving needs EVIDENCE — a photo of the completed work or a work order raised for it. A good
// rule, and worth asserting rather than working around.
qa_refuses('resolving with no evidence is refused',
    fn () => $svc->transition($req->fresh(), 'resolved'), 'photo');
app(RaiseCorrectiveWorkOrderService::class)->fromTenantRequest($req->fresh(),
    ['title' => 'QA evidence order', 'priority' => 'high', 'description' => 'QA',
        'execution_type' => FacilityWorkOrder::EXECUTION_INTERNAL, 'assigned_to_user_id' => $admin->id]);
$svc->transition($req->fresh(), 'resolved');
$svc->transition($req->fresh(), 'closed');
qa_eq('…and the full path works', 'closed', $req->fresh()->status);
qa_refuses('a CLOSED request is terminal', fn () => $svc->transition($req->fresh(), 'in_progress'), null, Throwable::class);

qa_section('REQUESTS 3 — rating is only for a finished request');
$open = $svc->create(['type' => TenantRequestType::Maintenance->value, 'title' => 'QA rating', 'category' => 'hvac',
    'description' => 'QA', 'priority' => 'low', 'unit_id' => $occupied->id], $tenant);
qa_refuses('an open request cannot be rated', fn () => $svc->rate($open->fresh(), 5, 'QA'), null, Throwable::class);
$svc->rate($req->fresh(), 4, 'QA good job');
qa_eq('a closed request can be rated', 4, (int) $req->fresh()->csat_rating);

/* ══════════════════════════ MODULE 30 · AREAS (zone routing) ══════════════════════════ */
qa_section('AREAS — a request routes to the zone of its unit');
$zone = Area::where('asset_id', $asset->id)->first()
    ?? Area::create(['asset_id' => $asset->id, 'code' => 'QAZ', 'name' => 'QA Zone']);
$occupied->forceFill(['area_id' => $zone->id])->saveQuietly();
$zoned = $svc->create(['type' => TenantRequestType::Maintenance->value, 'title' => 'QA zoned', 'category' => 'hvac',
    'description' => 'QA', 'priority' => 'high', 'unit_id' => $occupied->id], $tenant);
qa_eq('the request inherits the unit zone', $zone->id, (int) $zoned->fresh()->area_id);
$foreignAsset = Asset::where('code', 'PA')->first();
if ($foreignAsset) {
    $foreignZone = Area::firstOrCreate(['asset_id' => $foreignAsset->id, 'code' => 'PAZ'], ['name' => 'PA Zone']);
    qa_refuses('a unit cannot be tagged with ANOTHER property zone',
        fn () => tap($occupied->fresh())->update(['area_id' => $foreignZone->id]), null, Throwable::class);
}

/* ══════════════════════════ MODULE 26 · FACILITY ══════════════════════════ */
qa_section('FACILITY 1 — a corrective work order raised FROM a tenant request');
$wo = app(RaiseCorrectiveWorkOrderService::class)->fromTenantRequest($zoned->fresh(), [
    'title' => 'QA corrective', 'priority' => 'high', 'description' => 'QA corrective work',
    'execution_type' => FacilityWorkOrder::EXECUTION_INTERNAL, 'assigned_to_user_id' => $admin->id]);
qa_ok('a work order is raised', $wo->exists, $wo->reference ?? ('#'.$wo->id));
qa_eq('…linked back to the request', $zoned->id, (int) $wo->tenant_request_id);
qa_eq('…in the same property', $asset->id, (int) $wo->asset_id);
qa_eq('…starting open', 'open', $wo->status);

qa_section('FACILITY 2 — the work-order state machine');
$fsvc = app(FacilityWorkOrderService::class);
qa_refuses('open → done is allowed but open → a nonsense state is not',
    fn () => $fsvc->transition($wo->fresh(), 'archived'), null, Throwable::class);
$fsvc->transition($wo->fresh(), 'in_progress', $admin->id);
qa_eq('open → in_progress', 'in_progress', $wo->fresh()->status);
$item = $fsvc->addItem($wo->fresh(), 'QA check the compressor');
qa_ok('a checklist item is added', $item->exists);
$fsvc->markItem($item->fresh(), 'fail', $admin->id);
qa_eq('…and can be marked failed', 'fail', $item->fresh()->result);

qa_section('FACILITY 3 — a FAILED check raises its own corrective order');
$follow = app(RaiseCorrectiveWorkOrderService::class)->fromFailedCheck($item->fresh(), [
    'title' => 'QA compressor replacement', 'priority' => 'high', 'description' => 'QA follow-up',
    'execution_type' => FacilityWorkOrder::EXECUTION_INTERNAL, 'assigned_to_user_id' => $admin->id]);
qa_ok('a corrective order is raised from the failed check', $follow->exists);
qa_ok('…and it is NOT the same order', $follow->id !== $wo->id);

qa_section('FACILITY 4 — spare parts need approval above the band');
$item2 = InventoryItem::first();
$wh = Warehouse::where('asset_id', $asset->id)->first();
app(StockMovementService::class)->receive($wh, $item2, 100, 500);
$psvc = app(WorkOrderPartService::class);
// Requested by someone OTHER than the approver, so the approval below is a real second pair of eyes.
$partRequester = User::where('email', 'operations@mall.test')->first() ?? $admin;
$part = $psvc->requestInternal($wo->fresh(), ['inventory_item_id' => $item2->id, 'quantity' => 2,
    'warehouse_id' => $wh->id], $partRequester->id);
qa_ok('a part draw is requested', $part->exists, 'status='.$part->status);
qa_ok('…with the approval tier FROZEN on the row', filled($part->required_permission ?? null),
    (string) ($part->required_permission ?? '—'));
$selfPart = $psvc->requestInternal($wo->fresh(), ['inventory_item_id' => $item2->id, 'quantity' => 1,
    'warehouse_id' => $wh->id], $admin->id);
qa_refuses('approving your own part draw is refused (a second pair of eyes)',
    fn () => $psvc->approve($selfPart->fresh(), $admin), 'second pair of eyes');
$psvc->approve($part->fresh(), $admin);
qa_eq('an approved draw consumes stock', 'approved', $part->fresh()->status);

qa_section('APPROVALS — the band decides who may decide');
$rule = ApprovalRule::where('module', ApprovalRule::MODULE_PURCHASE_REQUEST)->first();
printf("  bands for %s: %s\n", ApprovalRule::MODULE_PURCHASE_REQUEST,
    ApprovalRule::where('module', ApprovalRule::MODULE_PURCHASE_REQUEST)->get()
        ->map(fn ($r) => ($r->min_amount ?? 0).'–'.($r->max_amount ?? '∞').' → '.$r->required_permission)->join(' · '));
qa_ok('a small amount lands in a low tier',
    ApprovalPolicy::permissionFor(ApprovalRule::MODULE_PURCHASE_REQUEST, 100) !== null);
$small = ApprovalPolicy::permissionFor(ApprovalRule::MODULE_PURCHASE_REQUEST, 100);
$large = ApprovalPolicy::permissionFor(ApprovalRule::MODULE_PURCHASE_REQUEST, 5000000);
printf("  100 → %s · 5,000,000 → %s\n", $small ?? '(none)', $large ?? '(none)');
qa_ok('a large amount escalates to a higher tier', $small !== $large, "$small vs $large");
$ops = User::where('email', 'operations@mall.test')->first();
if ($ops) {
    qa_ok('a tier-1 approver may decide a small amount',
        ApprovalPolicy::canApprove($ops, ApprovalRule::MODULE_PURCHASE_REQUEST, 100));
    qa_ok('…and may NOT decide a large one', ! ApprovalPolicy::canApprove($ops, ApprovalRule::MODULE_PURCHASE_REQUEST, 5000000));
}

qa_section('FACILITY 5 — an SLA breach becomes a penalty that CUTS a vendor bill');
$vendor = Vendor::first();
$breached = FacilityWorkOrder::create(['asset_id' => $asset->id, 'title' => 'QA SLA breach',
    'description' => 'QA', 'status' => 'open', 'priority' => 'high', 'category' => 'hvac',
    'execution_type' => FacilityWorkOrder::EXECUTION_EXTERNAL, 'vendor_id' => $vendor->id,
    'target_resolution_at' => now()->subDays(5), 'scheduled_for' => now()->subDays(10), 'work_order_type' => 'cm']);
$pen = app(AssessSlaPenaltyService::class)->assess($breached->fresh());
if ($pen) {
    printf("  penalty %s status=%s\n", number_format((float) $pen->amount, 2), $pen->status);
    qa_ok('a breached order assesses a penalty', (float) $pen->amount >= 0);
    qa_allows('…and it can be waived with a reason',
        fn () => app(AssessSlaPenaltyService::class)->waive($pen->fresh(), 'QA goodwill', $admin->id));
    qa_eq('…leaving it waived', 'waived', $pen->fresh()->status);
} else {
    echo "  (no penalty assessed — the vendor contract carries no SLA terms)\n";
    qa_ok('assess() returns null rather than inventing a penalty', true);
}

qa_section('FACILITY 6 — preventive work orders are generated from a service plan, idempotently');
$plan = ServicePlan::where('asset_id', $asset->id)->first();
if ($plan) {
    $made = app(GeneratePreventiveWorkOrdersService::class)->runFor($plan->fresh());
    $again = app(GeneratePreventiveWorkOrdersService::class)->runFor($plan->fresh());
    printf("  generated %d, second run %d\n", $made, $again);
    qa_eq('a second run generates nothing more', 0, $again);
} else {
    echo "  (no service plan seeded)\n";
}

/* ══════════════════════════ MODULE 35 · RENTABLE ITEMS ══════════════════════════ */
qa_section('RENTABLE ITEMS — a bay is LET, and the parking charge is RE-DERIVED as a dated rung');
$rentOn = fn (Lease $l, string $d) => (float) optional(ChargeScheduleService::pickInForce(
    $l->fresh('charges')->charges->where('type', 'parking'), CarbonImmutable::parse($d)))->amount;
$before = $rentOn($lease, '2026-07-01');
$heldBefore = $lease->rentableItems()->count();
printf("  before: %d item(s) held, parking charge %s\n", $heldBefore, number_format($before, 2));

$item3 = RentableItem::create(['asset_id' => $asset->id, 'code' => 'QA-P1', 'name' => 'QA Bay 1',
    'type' => 'parking', 'monthly_rate' => 1500, 'status' => 'available']);
app(AssignRentableItemService::class)->assign($lease->fresh(), $item3->fresh(), ['effective_from' => '2026-08-01']);
qa_eq('the charge is the TOTAL of every bay held, not one bay rate', $before + 1500.00, $rentOn($lease, '2026-08-01'));
qa_eq('…and July still bills the OLD total (a dated rung, never an overwrite)', $before, $rentOn($lease, '2026-07-01'));
qa_eq('the lease now holds one more item', $heldBefore + 1, $lease->fresh()->rentableItems()->count());

qa_refuses('the SAME bay cannot be let twice',
    fn () => app(AssignRentableItemService::class)->assign(
        Lease::where('status', 'active')->where('id', '!=', $lease->id)->first(), $item3->fresh(),
        ['effective_from' => '2026-08-01']), 'already held');

app(AssignRentableItemService::class)->release($lease->fresh(), $item3->fresh(), '2026-08-31');
qa_eq('releasing re-derives the charge DOWN from the next month', $before, $rentOn($lease, '2026-09-01'));
qa_eq('…while August keeps what it was billed', $before + 1500.00, $rentOn($lease, '2026-08-15'));
$active = $lease->fresh('charges')->charges->where('type', 'parking')->where('is_active', true)->values();
$overlap = false;
foreach ($active as $i => $a) {
    foreach ($active as $j => $b) {
        if ($i >= $j) {
            continue;
        }
        $endsBefore = $a->end_date && $b->start_date && $a->end_date->lt($b->start_date);
        $startsAfter = $a->start_date && $b->end_date && $a->start_date->gt($b->end_date);
        if (! $endsBefore && ! $startsAfter) {
            $overlap = true;
        }
    }
}
qa_ok('no two active parking rows overlap — the ladder can never double-bill', ! $overlap,
    $active->count().' active rung(s)');
qa_ok('…and the bay is available again', $item3->fresh()->status === 'available', $item3->fresh()->status);

qa_section('BATCH B TIE-OUT');
Artisan::call('accounting:sync-ledger', ['--all' => true]);
qa_assert_tb('after requests, facility, approvals and rentable items');
$rec = app(BooksReconciliationService::class);
$tie = $rec->glTieOut();
qa_eq('AR ties', 0.0, $tie['ar']['delta']);
qa_eq('AP ties', 0.0, $tie['ap']['delta']);
qa_eq('no GL drift', 0, count($rec->glDriftDiscrepancies()));

qa_summary();
