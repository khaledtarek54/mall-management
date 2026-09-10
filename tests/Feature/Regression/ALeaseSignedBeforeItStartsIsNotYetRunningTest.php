<?php

use App\Models\Charge;
use App\Models\Lease;
use App\Models\Unit;
use App\Services\LeaseRenewalService;
use App\Services\MonthlyBillingService;
use App\Support\Occupancy;
use App\Support\ProjectedState;
use App\Support\ValueSets;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesPermissionsSeeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Lang;
use Spatie\Permission\PermissionRegistrar;

/**
 * A lease that has been signed but has not started is `future`, not `active` — Yardi's own status.
 *
 * Voyager's lease status runs Prospect → Applicant → **Future** → Current → **Notice** → Past. Atriom
 * had five of the six: `draft`/`pending_approval` cover the first two and `expired`/`renewed`/
 * `terminated`/`cancelled` split Past more finely than Voyager does, which is the better model and
 * stays. **Future was missing**, and its absence cost money.
 *
 * **The money defect.** A renewal is normally negotiated months before the term ends.
 * `LeaseRenewalService` stamped the original `renewed` — a TERMINAL status outside
 * `BILLABLE_STATUSES` — the instant the renewal was signed, while the successor sat at `active`
 * with a commencement date still in the future. Neither billed the gap. Measured on the demo books
 * before the fix: renewing in September a lease expiring 31 December left October, November and
 * December uninvoiced on a shop still trading, and the weekly `billing:scan-unbilled-periods` could
 * not report it either — that scan only reports months a BILLABLE lease missed, so the one safety
 * net built for exactly this was structurally blind to it.
 *
 * **The occupancy defect**, same cause: a lease keyed today to commence in 60 days read `active`
 * from the day it was typed, so its unit read `occupied` and the mall's occupancy went 0% → 10.4%
 * for a shop nobody was trading from and nobody was paying for.
 *
 * `Lease::executedStatusFor()` is the ONE definition — an operator declares a deal EXECUTED and the
 * calendar answers whether that means active or future — derived on the write so every door
 * inherits it, and swept by `leases:expire` for the day the term actually starts, which is a day on
 * which nothing is written. That pairing is what {@see ProjectedState} exists to state.
 */
/**
 * A rent row on the schedule. Without one the billing arms below would bill nothing whatever the
 * status said, and would pass for the wrong reason — `makeLease()` writes the lease, not its money.
 */
function futureLeaseRent(Lease $lease): void
{
    Charge::create([
        'lease_id' => $lease->id, 'name' => 'Base Rent', 'type' => 'base_rent',
        'amount' => 10000, 'currency' => 'EGP', 'frequency' => 'monthly',
        'vat_applicable' => false, 'vat_rate' => 0,
        'start_date' => $lease->commencement_date, 'is_active' => true,
    ]);
}

beforeEach(function () {
    $this->seed(RolesPermissionsSeeder::class);
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->actingAs(makeUser('super_admin'));
    $this->asset = makeAsset(['code' => 'FUT']);
});

it('stores a lease signed before it starts as future, and does not let it occupy the shop', function () {
    $unit = makeUnit($this->asset, ['code' => 'F-01', 'status' => 'vacant']);
    $before = Occupancy::forUnits(Unit::where('asset_id', $this->asset->id));

    // The operator declares the deal EXECUTED — `active` is what every door passes — and the
    // calendar decides the rest.
    $lease = makeLease($unit, null, [
        'status' => 'active',
        'commencement_date' => now()->addDays(60)->toDateString(),
        'expiry_date' => now()->addDays(60)->addYear()->toDateString(),
    ]);

    $after = Occupancy::forUnits(Unit::where('asset_id', $this->asset->id));

    expect($lease->fresh()->status)->toBe('future')
        // RESERVED, not occupied and not vacant: the shop is spoken for, which is what a leasing
        // manager needs to see before marketing it, and is the answer the unit column already had.
        //
        // `recomputeStatus()` reads `future` in BOTH of its lists and removing it from EITHER ONE
        // leaves this green — measured, not assumed. That is not a redundant clause: they answer
        // *currently held* and *not yet released*, and both are true of a lease whose `lease_unit`
        // rows carry no dates, which is every lease `syncUnits()` writes. They separate only on a
        // future-DATED pivot, which `LeaseSpaceChangeService` alone produces and LE-02 already
        // covers. Removing it from both goes red.
        ->and($unit->fresh()->status)->toBe('reserved')
        ->and($after['occupied_sqm'])->toBe($before['occupied_sqm']);
});

it('leaves a lease that starts today active', function () {
    // The boundary, and the control: `future` must mean NOT YET, never "starts today".
    $unit = makeUnit($this->asset, ['code' => 'F-02', 'status' => 'vacant']);

    $lease = makeLease($unit, null, [
        'status' => 'active',
        'commencement_date' => today()->toDateString(),
        'expiry_date' => today()->addYear()->toDateString(),
    ]);

    expect($lease->fresh()->status)->toBe('active')
        ->and($unit->fresh()->status)->toBe('occupied');
});

it('commences a future lease on the day its term arrives', function () {
    // The sweep's half. The commencement date passing is not a write, so nothing else can notice it.
    $unit = makeUnit($this->asset, ['code' => 'F-03', 'status' => 'vacant']);
    $lease = makeLease($unit, null, [
        'status' => 'active',
        'commencement_date' => now()->addDays(3)->toDateString(),
        'expiry_date' => now()->addYear()->toDateString(),
    ]);
    expect($lease->fresh()->status)->toBe('future');

    // Nothing to do yet — a sweep that commenced it early would be worse than one that never ran.
    Artisan::call('leases:expire');
    expect($lease->fresh()->status)->toBe('future');

    $this->travelTo(now()->addDays(3));
    Artisan::call('leases:expire');

    expect($lease->fresh()->status)->toBe('active')
        ->and($unit->fresh()->status)->toBe('occupied');
});

it('still bills the first month of a lease whose sweep has not run yet', function () {
    // ORDERING, and it is why `future` is in BILLABLE_STATUSES. The billing job runs at 02:00 and
    // `leases:expire` at 05:15, so on the morning a tenancy opens the lease is still `future` when
    // billing asks. Excluding it would lose the FIRST MONTH of every lease keyed in advance.
    $unit = makeUnit($this->asset, ['code' => 'F-04', 'status' => 'vacant']);
    $lease = makeLease($unit, null, [
        'status' => 'active',
        'commencement_date' => now()->addMonth()->startOfMonth()->toDateString(),
        'expiry_date' => now()->addMonth()->startOfMonth()->addYear()->toDateString(),
    ]);
    expect($lease->fresh()->status)->toBe('future');
    futureLeaseRent($lease);

    $result = app(MonthlyBillingService::class)->generateForLease(
        $lease->fresh(),
        CarbonImmutable::now()->addMonth()->startOfMonth(),
    );

    expect($result['status'])->toBe('created');
});

it('bills nothing for a month before the term starts', function () {
    // The control on the clause above: admitting `future` must widen WHICH LEASES are asked, never
    // WHICH MONTHS bill. The date clauses still refuse a month the term does not cover.
    $unit = makeUnit($this->asset, ['code' => 'F-05', 'status' => 'vacant']);
    $lease = makeLease($unit, null, [
        'status' => 'active',
        'commencement_date' => now()->addMonths(6)->startOfMonth()->toDateString(),
        'expiry_date' => now()->addMonths(6)->startOfMonth()->addYear()->toDateString(),
    ]);
    futureLeaseRent($lease);

    $result = app(MonthlyBillingService::class)->generateForLease(
        $lease->fresh(),
        CarbonImmutable::now()->startOfMonth(),
    );

    expect($result['status'])->toBe('skipped');
});

it('KEEPS BILLING a lease renewed months before its term ends', function () {
    // THE MONEY TOOTH. Renewing early is ordinary practice; it must not stop the rent.
    $unit = makeUnit($this->asset, ['code' => 'F-06', 'status' => 'vacant']);
    $lease = makeLease($unit, null, [
        'status' => 'active',
        'commencement_date' => now()->subMonths(9)->startOfMonth()->toDateString(),
        'expiry_date' => now()->addMonths(3)->endOfMonth()->toDateString(),
    ]);
    futureLeaseRent($lease);

    app(LeaseRenewalService::class)->renew($lease->fresh(), [
        'new_term_months' => 12,
        'new_rent' => 12000,
    ]);
    $lease = $lease->fresh();

    // The original is still running — it has three months left on a shop that is still trading.
    expect($lease->status)->toBe('active');

    $result = app(MonthlyBillingService::class)->generateForLease(
        $lease,
        CarbonImmutable::now()->addMonth()->startOfMonth(),
    );

    expect($result['status'])->toBe('created');

    // …and the successor is FUTURE, so it neither double-bills nor claims the shop.
    $successor = Lease::where('previous_lease_id', $lease->id)->sole();
    expect($successor->status)->toBe('future')
        ->and($unit->fresh()->status)->toBe('occupied');
});

it('marks the original renewed on the day its term actually ends', function () {
    // Where `renewed` is reached now: derived from the successor, in the same sweep that already
    // chooses between `expired` and `terminated` from the lease's own history.
    $unit = makeUnit($this->asset, ['code' => 'F-07', 'status' => 'vacant']);
    $lease = makeLease($unit, null, [
        'status' => 'active',
        'commencement_date' => now()->subMonths(9)->startOfMonth()->toDateString(),
        'expiry_date' => now()->addDays(2)->toDateString(),
    ]);
    app(LeaseRenewalService::class)->renew($lease->fresh(), ['new_term_months' => 12, 'new_rent' => 12000]);

    $this->travelTo(now()->addDays(3));
    Artisan::call('leases:expire');

    expect($lease->fresh()->status)->toBe('renewed');
});

it('marks a lease with no successor expired, not renewed', function () {
    // The control. A sweep that wrote `renewed` for everything would satisfy the case above.
    $unit = makeUnit($this->asset, ['code' => 'F-08', 'status' => 'vacant']);
    $lease = makeLease($unit, null, [
        'status' => 'active',
        'commencement_date' => now()->subMonths(9)->startOfMonth()->toDateString(),
        'expiry_date' => now()->addDays(2)->toDateString(),
    ]);

    $this->travelTo(now()->addDays(3));
    Artisan::call('leases:expire');

    expect($lease->fresh()->status)->toBe('expired');
});

it('still stamps renewed at once when the term had ALREADY run out', function () {
    // LE-04: a renewal signed after the old lease expired. Waiting for a sweep would leave the
    // record reading `expired` beside its own successor, so that branch is kept.
    $unit = makeUnit($this->asset, ['code' => 'F-09', 'status' => 'vacant']);
    $lease = makeLease($unit, null, [
        'status' => 'active',
        'commencement_date' => now()->subYear()->toDateString(),
        'expiry_date' => now()->subDays(10)->toDateString(),
    ]);

    app(LeaseRenewalService::class)->renew($lease->fresh(), ['new_term_months' => 12, 'new_rent' => 12000]);

    expect($lease->fresh()->status)->toBe('renewed');
});

it('never rewrites a draft or a decision', function () {
    // The over-lock control. The derivation touches `active` and nothing else — a draft dated ahead
    // is still a draft, and a terminal status is a decision the calendar has no opinion about.
    $unit = makeUnit($this->asset, ['code' => 'F-10', 'status' => 'vacant']);

    $draft = makeLease($unit, null, [
        'status' => 'draft',
        'commencement_date' => now()->addDays(30)->toDateString(),
        'expiry_date' => now()->addYear()->toDateString(),
    ]);

    expect($draft->fresh()->status)->toBe('draft');
});

it('REFUSES a second lease on a shop a future lease has already taken', function () {
    // THE INVARIANT THE STATUS NEARLY BROKE. `future` was added to the vocabulary and every
    // `where('status','active')` guard silently stopped seeing it — including this one, which
    // `ConcurrencyPolicy::AUTHORITATIVE_GUARDS` registers as one of the three whose refusal is
    // authoritative. Measured with the literal still in place: two leases, one shop, thirteen
    // months of overlap, both billing, the unit reading `reserved` throughout.
    $unit = makeUnit($this->asset, ['code' => 'F-11', 'status' => 'vacant']);
    makeLease($unit, null, [
        'status' => 'active',
        'commencement_date' => now()->addDays(60)->toDateString(),
        'expiry_date' => now()->addDays(60)->addYear()->toDateString(),
    ]);

    expect($unit->fresh()->isActivelyLeased())->toBeTrue()
        ->and($unit->fresh()->isActivelyLeasedForUpdate())->toBeTrue();
});

it('still lets a vacant shop be let', function () {
    // The control. A guard that refused everything would satisfy the case above.
    $unit = makeUnit($this->asset, ['code' => 'F-12', 'status' => 'vacant']);

    expect($unit->isActivelyLeased())->toBeFalse();
});

it('refuses a SECOND renewal of the same tenancy', function () {
    // The implicit guard that went with the `renewed` stamp. Nothing else ever enforced this, so
    // a double-clicked Renew produced two successors on one tenancy, each commencing the day after
    // the same expiry, both billing the shop once the sweep commenced them.
    $unit = makeUnit($this->asset, ['code' => 'F-13', 'status' => 'vacant']);
    $lease = makeLease($unit, null, [
        'status' => 'active',
        'commencement_date' => now()->subMonths(6)->startOfMonth()->toDateString(),
        'expiry_date' => now()->addMonths(6)->endOfMonth()->toDateString(),
    ]);
    $terms = ['new_term_months' => 12, 'new_rent' => 12000];

    $first = app(LeaseRenewalService::class)->renew($lease->fresh(), $terms);

    expect(fn () => app(LeaseRenewalService::class)->renew($lease->fresh(), $terms))
        ->toThrow(InvalidArgumentException::class);

    // …and the refusal NAMES the successor, because "an 'Active' lease cannot be renewed" is both
    // wrong and a dead end.
    try {
        app(LeaseRenewalService::class)->renew($lease->fresh(), $terms);
        $message = null;
    } catch (InvalidArgumentException $e) {
        $message = $e->getMessage();
    }

    expect($message)->toContain($first->reference)
        ->and($message)->not->toContain('admin.refusals')
        ->and(Lease::where('previous_lease_id', $lease->id)->count())->toBe(1);
});

it('lets a tenancy be renewed again once the first renewal is cancelled', function () {
    // The escape hatch, and the reason the guard reads a LIVE successor rather than any successor:
    // a renewal that fell through must leave the tenancy renewable, or the operator is left with a
    // lease that can never be continued.
    $unit = makeUnit($this->asset, ['code' => 'F-14', 'status' => 'vacant']);
    $lease = makeLease($unit, null, [
        'status' => 'active',
        'commencement_date' => now()->subMonths(6)->startOfMonth()->toDateString(),
        'expiry_date' => now()->addMonths(6)->endOfMonth()->toDateString(),
    ]);
    $terms = ['new_term_months' => 12, 'new_rent' => 12000];

    $first = app(LeaseRenewalService::class)->renew($lease->fresh(), $terms);
    $first->update(['status' => 'cancelled']);

    $second = app(LeaseRenewalService::class)->renew($lease->fresh(), $terms);

    expect($second->exists)->toBeTrue();
});

it('leaves a lease still awaiting approval alone', function () {
    // The other half of the over-lock control, which the name claimed and the body did not check.
    // The derivation touches `active` and nothing else: a deal dated to open in three months that
    // has not been approved yet is `pending_approval`, not `future` — it is not executed at all.
    // This is the case a hook written as "anything non-terminal" would rewrite.
    $unit = makeUnit($this->asset, ['code' => 'F-15', 'status' => 'vacant']);

    $pending = makeLease($unit, null, [
        'status' => 'pending_approval',
        'commencement_date' => now()->addMonths(3)->toDateString(),
        'expiry_date' => now()->addMonths(3)->addYear()->toDateString(),
    ]);

    expect($pending->fresh()->status)->toBe('pending_approval');
});

it('words the new status in EN and AR, everywhere it is read', function () {
    foreach (['admin.statuses.lease.future', 'admin.tabs.future', 'admin.widgets.pipeline.future'] as $key) {
        expect(Lang::has($key, 'en', false))->toBeTrue($key)
            ->and(Lang::has($key, 'ar', false))->toBeTrue($key)
            ->and((bool) preg_match('/\p{Arabic}/u', __($key, [], 'ar')))->toBeTrue($key);
    }

    expect(ValueSets::allowed('leases', 'status'))->toContain('future');
});
