<?php

require __DIR__.'/boot.php';
use App\Models\Asset;
use App\Models\Lease;
use App\Models\LeaseEvent;
use App\Models\LeaseOption;
use App\Models\Tenant;
use App\Models\Unit;
use App\Services\ExerciseLeaseOptionService;
use App\Services\LeaseCreationService;
use App\Services\LeaseRenewalService;
use App\Services\Reconciliation\BooksReconciliationService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Artisan;

$asset = Asset::where('code', 'AW')->firstOrFail();
$tenant = Tenant::whereDoesntHave('unitOwnerships')->firstOrFail();
$n = 0;
// Units minted on demand — the seeded vacant pool runs out long before this suite does.
$newUnit = function () use (&$n, $asset): Unit {
    return Unit::create(['asset_id' => $asset->id, 'code' => 'OPT-'.str_pad((string) ++$n, 3, '0', STR_PAD_LEFT),
        'category' => 'retail', 'area_sqm' => 100, 'status' => 'vacant']);
};
$svc = app(ExerciseLeaseOptionService::class);
$mk = function (array $a = []) use ($newUnit, $tenant): Lease {
    $l = Lease::create(array_merge(['tenant_id' => $tenant->id, 'unit_id' => $newUnit()->id,
        'status' => 'active', 'currency' => 'EGP', 'commencement_date' => '2025-01-01',
        'expiry_date' => '2026-12-31', 'term_months' => 24, 'base_rent_monthly' => 100000,
        'service_charge_monthly' => 0, 'has_marketing_levy' => false, 'billing_frequency' => 'monthly',
        'payment_terms_days' => 7, 'escalation_type' => 'none'], $a));
    LeaseCreationService::seedStandardCharges($l, (float) $l->base_rent_monthly, 0, $l->commencement_date);

    return $l->fresh();
};
$opt = fn (Lease $l, array $a) => LeaseOption::create(array_merge([
    'lease_id' => $l->id, 'status' => 'open',
    'earliest_notice_date' => '2026-06-01', 'latest_notice_date' => '2026-09-30',
], $a));

qa_section('OPTIONS 1 — every type can be recorded, and only the right ones encumber');
$leases = [];
$options = [];
foreach (LeaseOption::TYPES as $t) {
    $l = $mk();
    $leases[$t] = $l;
    // An option encumbers only when it NAMES a unit — a right over "some unit" ties nothing up.
    $namesAUnit = in_array($t, LeaseOption::ENCUMBERING_TYPES, true) ? ['unit_id' => $newUnit()->id] : [];
    $extra = match ($t) {
        'renewal' => ['term_months' => 36, 'rent_basis' => 'uplift_percent', 'uplift_percent' => 10],
        'expansion' => ['term_months' => 24, 'rent_basis' => 'fixed', 'fixed_rent' => 150000],
        'termination' => ['penalty_amount' => 50000],
        default => [],
    } + $namesAUnit;
    $options[$t] = $opt($l, ['type' => $t] + $extra);
    qa_ok("a $t option is recorded", $options[$t]->exists, 'status='.$options[$t]->status);
}
foreach (LeaseOption::TYPES as $t) {
    $encumbers = in_array($t, LeaseOption::ENCUMBERING_TYPES, true);
    qa_eq("$t ".($encumbers ? 'DOES' : 'does NOT').' encumber its unit', $encumbers, $options[$t]->encumbersUnit());
}

$noUnit = $opt($mk(), ['type' => 'rofr']);
qa_ok('…and an encumbering type with NO unit named encumbers nothing', ! $noUnit->encumbersUnit());

qa_section('OPTIONS 2 — an encumbered unit is flagged to the next deal, but not blocked');
$expansionUnit = Unit::find($options['expansion']->unit_id);
qa_ok('the expansion target reads as encumbered', $expansionUnit->fresh()->isEncumbered());
qa_ok('…and NOT as actively leased (it is spoken for, not let)', ! $expansionUnit->fresh()->isActivelyLeased());
qa_allows('…so a lease on it is still allowed — a warning, never a block',
    fn () => app(LeaseCreationService::class)->create([
        'tenant_mode' => 'existing', 'tenant_id' => $tenant->id,
        'lease' => ['unit_id' => $expansionUnit->id, 'commencement_date' => '2026-09-01', 'term_months' => 12,
            'base_rent_monthly' => 60000, 'service_charge_monthly' => 0]]));

qa_section('OPTIONS 3 — projected rent by basis (and what must never be invented)');
$r = $options['renewal'];
qa_eq('uplift_percent projects +10% of the passing rent', 110000.00, $r->projectedRent(100000));
$fixed = $opt($mk(), ['type' => 'renewal', 'term_months' => 12, 'rent_basis' => 'fixed', 'fixed_rent' => 123456]);
qa_eq('fixed projects the stated figure', 123456.00, $fixed->projectedRent(100000));
foreach (['market', 'cpi'] as $basis) {
    $o = $opt($mk(), ['type' => 'renewal', 'term_months' => 12, 'rent_basis' => $basis]);
    qa_ok("$basis projects NOTHING — a valuation/index is not ours to invent", $o->projectedRent(100000) === null);
}

qa_section('OPTIONS 4 — exercising writes the DEAL, typed by what the option does');
$svc->exercise($options['renewal']->fresh(), ['notice_given_at' => '2026-08-01', 'reason' => 'QA notice served']);
qa_eq('the renewal option is exercised', 'exercised', $options['renewal']->fresh()->status);
qa_eq('…and records the notice date served, not the day it was keyed', '2026-08-01',
    $options['renewal']->fresh()->notice_given_at?->format('Y-m-d'));
qa_eq('a renewal writes an EXTENSION event (the deal, not the option)', 1,
    LeaseEvent::where('lease_id', $leases['renewal']->id)->where('type', LeaseEvent::TYPE_EXTENSION)->count());
$svc->exercise($options['expansion']->fresh(), ['notice_given_at' => '2026-08-02']);
qa_eq('an expansion writes an EXPANSION event', 1,
    LeaseEvent::where('lease_id', $leases['expansion']->id)->where('type', LeaseEvent::TYPE_EXPANSION)->count());
$svc->exercise($options['termination']->fresh(), ['notice_given_at' => '2026-08-03']);
qa_eq('a termination option writes a TERMINATION event', 1,
    LeaseEvent::where('lease_id', $leases['termination']->id)->where('type', LeaseEvent::TYPE_TERMINATION)->count());
$svc->exercise($options['rofr']->fresh(), ['notice_given_at' => '2026-08-04']);
qa_eq('a ROFR writes NO lease event (nothing about the lease changed yet)', 0,
    LeaseEvent::where('lease_id', $leases['rofr']->id)->count());

qa_section('OPTIONS 5 — exercising an encumbering option releases the unit');
qa_ok('the expansion target is no longer encumbered', ! Unit::find($options['expansion']->unit_id)->fresh()->isEncumbered());

qa_section('OPTIONS 6 — waive and lapse record the outcome and write NO event');
$w = $opt($mk(), ['type' => 'renewal', 'term_months' => 12, 'rent_basis' => 'fixed', 'fixed_rent' => 90000]);
$svc->resolveWithout($w->fresh(), 'waived');
qa_eq('waived', 'waived', $w->fresh()->status);
qa_ok('…with a resolution date', $w->fresh()->resolved_at !== null);
qa_eq('…and no lease event (a timeline of non-events is one nobody reads)', 0,
    LeaseEvent::where('lease_id', $w->lease_id)->count());
$lp = $opt($mk(), ['type' => 'rofo']);
$svc->resolveWithout($lp->fresh(), 'lapsed');
qa_eq('lapsed', 'lapsed', $lp->fresh()->status);
// A DomainException since the refusals-translation pass: exercising a resolved option is an
// OPERATOR act refused with a toast, not a developer error 500.
qa_refuses('an already-resolved option cannot be exercised',
    fn () => $svc->exercise($lp->fresh()), null, DomainException::class);
qa_refuses('…nor resolved again', fn () => $svc->resolveWithout($lp->fresh(), 'waived'), null, InvalidArgumentException::class);
qa_refuses('an invalid resolution is refused',
    fn () => $svc->resolveWithout($opt($mk(), ['type' => 'purchase'])->fresh(), 'cancelled'), null, InvalidArgumentException::class);

qa_section('OPTIONS 7 — the exercised renewal hands its terms to the renewal form');
$terms = $svc->pendingRenewalTerms($leases['renewal']->fresh());
printf("  pending terms: %s\n", json_encode(array_map(
    fn ($v) => $v instanceof CarbonImmutable ? $v->toDateString() : (is_object($v) ? class_basename($v).'#'.$v->id : $v), $terms ?? [])));
qa_ok('terms are offered', $terms !== null);
qa_eq('…the contracted term', 36, $terms['term_months']);
qa_eq('…the contracted rent (+10%)', 110000.00, $terms['rent']);
qa_eq('…commencing the day after the current term ends', '2027-01-01', $terms['commencement']->toDateString());
$new = app(LeaseRenewalService::class)->renew($leases['renewal']->fresh(), [
    'new_term_months' => $terms['term_months'], 'new_rent' => $terms['rent'],
    'commencement_date' => $terms['commencement']->toDateString()]);
qa_eq('the renewal is priced at the CONTRACTED rent, not a typed one', 110000.00, (float) $new->base_rent_monthly);
qa_eq('…for the contracted term', '2029-12-31', $new->expiry_date?->format('Y-m-d'));

qa_section('OPTIONS 8 — a market-basis renewal offers no rent, deliberately');
$mkt = $mk();
$mo = $opt($mkt, ['type' => 'renewal', 'term_months' => 24, 'rent_basis' => 'market']);
$svc->exercise($mo->fresh(), ['notice_given_at' => '2026-08-05']);
$mTerms = $svc->pendingRenewalTerms($mkt->fresh());
qa_eq('the term is offered', 24, $mTerms['term_months']);
qa_ok('…and the rent is NULL — the operator types the valuation', $mTerms['rent'] === null);
$ev = LeaseEvent::where('lease_id', $mkt->id)->latest('id')->first();
qa_ok('…and the history says the rent is to be agreed',
    (bool) data_get($ev?->payload ?? [], 'rent_to_be_agreed'), json_encode($ev?->payload));

qa_section('OPTIONS 9 — the notice-window scan');
$soon = $opt($mk(), ['type' => 'renewal', 'term_months' => 12, 'rent_basis' => 'fixed', 'fixed_rent' => 1,
    'earliest_notice_date' => now()->addDays(3)->toDateString(), 'latest_notice_date' => now()->addDays(60)->toDateString()]);
$closing = $opt($mk(), ['type' => 'renewal', 'term_months' => 12, 'rent_basis' => 'fixed', 'fixed_rent' => 1,
    'earliest_notice_date' => now()->subDays(30)->toDateString(), 'latest_notice_date' => now()->addDays(5)->toDateString()]);
$lapsing = $opt($mk(), ['type' => 'renewal', 'term_months' => 12, 'rent_basis' => 'fixed', 'fixed_rent' => 1,
    'earliest_notice_date' => now()->subDays(60)->toDateString(), 'latest_notice_date' => now()->subDay()->toDateString()]);
$exit = Artisan::call('leases:scan-option-windows');
echo '  '.trim(str_replace("\n", "\n  ", Artisan::output()))."\n";
qa_eq('the scan runs clean', 0, $exit);
qa_ok('an option whose window has CLOSED is stamped', $closing->fresh()->closing_notified_at !== null
    || $lapsing->fresh()->lapsed_notified_at !== null, 'closing/lapsed stamps written');
$before = [$soon->fresh()->opening_notified_at, $closing->fresh()->closing_notified_at, $lapsing->fresh()->lapsed_notified_at];
Artisan::call('leases:scan-option-windows');
$after = [$soon->fresh()->opening_notified_at, $closing->fresh()->closing_notified_at, $lapsing->fresh()->lapsed_notified_at];
qa_eq('…and the scan is idempotent (no re-alerting)', json_encode($before), json_encode($after));

qa_section('OPTIONS 10 — an unabstracted lease is a portfolio question, not a silent one');
$bare = $mk();
qa_eq('a lease with no options recorded has none', 0, $bare->options()->count());
$withNone = Lease::where('status', 'active')->whereDoesntHave('options')->count();
printf("  leases on the books with NO option recorded: %d\n", $withNone);
qa_ok('the "no options recorded" population is countable (the list filter)', $withNone > 0);

qa_section('ACCOUNTING — options move rights, never money on their own');
Artisan::call('accounting:sync-ledger', ['--all' => true]);
qa_assert_tb('after every option type was exercised/waived/lapsed');
$rec = app(BooksReconciliationService::class);
$tie = $rec->glTieOut();
qa_eq('AR ties', 0.0, $tie['ar']['delta']);
qa_eq('AP ties', 0.0, $tie['ap']['delta']);
qa_eq('no GL drift', 0, count($rec->glDriftDiscrepancies()));

qa_summary();
