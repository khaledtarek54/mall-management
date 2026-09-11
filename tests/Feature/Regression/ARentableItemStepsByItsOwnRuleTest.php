<?php

use App\Filament\Admin\RelationManagers\ChargeScheduleRelationManager;
use App\Filament\Admin\RelationManagers\LeaseRentableItemsRelationManager;
use App\Filament\Admin\Resources\Leases\Pages\CreateLease;
use App\Filament\Admin\Resources\Leases\Pages\EditLease;
use App\Filament\Admin\Resources\Leases\Pages\ListLeases;
use App\Models\Asset;
use App\Models\Charge;
use App\Models\Lease;
use App\Models\RentableItem;
use App\Models\UnitOwnership;
use App\Services\AssignRentableItemService;
use App\Services\ChargeScheduleService;
use App\Services\LeaseRenewalService;
use App\Services\RentEscalationService;
use App\Support\ChargeEscalation;
use App\Support\Filament\RecordChanged;
use App\Support\LeaseEventNarrative;
use App\Support\RentableItemPricing;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesPermissionsSeeder;
use Filament\Actions\Testing\TestAction;
use Filament\Forms\Components\Select;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;

/**
 * A parking bay, a storage cage, a signage face steps on the lease anniversary by ITS OWN rule —
 * the per-item half of Yardi's grain that point 24 deliberately left out (2026-09-12).
 *
 * Point 24 gave every charge row its annual increase and made the `parking` row DERIVED: it is
 * re-summed from the bays a lease holds on every assignment, so a rule on the row was undone by
 * the next bay. The rule belongs where the rate lives — on the HOLDING — and Voyager's shape says
 * so: a rentable item is a recurring charge on its own code, and the escalation sits on that
 * charge. Now the holding carries the same three terms a charge row does, `RentableItemPricing`
 * is the one reading of what an item bills on a date, and the projection, the sweep and the
 * assignment-day re-sum all read it — so a projected parking ladder and a swept one converge.
 *
 * Two more things the same change closes: a lease can be created WITH its bays (the standard form
 * and the quick wizard, through the one assignment door), and the rule is set from the lease's
 * TABS — the schedule tab for a charge, the items tab for a bay — not only from the form's table,
 * with the form's table refilled from the schedule and the register so the two cannot disagree.
 */
afterEach(fn () => CarbonImmutable::setTestNow());

/**
 * An active lease at 100,000 rent / 20,000 service, 7 % a year from 1 January 2026, with its
 * two seeded rows — the shape `ruledLease()` builds for the charges, minus the extra charges.
 */
function stepLease(array $attrs = [], ?Asset $asset = null): Lease
{
    $asset ??= makeAsset();
    $lease = makeLease(makeUnit($asset), null, array_merge([
        'status' => 'active',
        'commencement_date' => '2025-01-01',
        'expiry_date' => '2027-12-31',
        'base_rent_monthly' => 100000,
        'service_charge_monthly' => 20000,
        'escalation_type' => 'fixed_percent',
        'escalation_rate' => 7,
        'next_escalation_date' => '2026-01-01',
    ], $attrs));

    foreach (['base_rent' => ['Base Rent', 100000], 'service_charge' => ['Service Charge', 20000]] as $type => [$name, $amount]) {
        Charge::create([
            'lease_id' => $lease->id, 'name' => $name, 'type' => $type, 'origin' => Charge::ORIGIN_SEED,
            'amount' => $amount, 'currency' => 'EGP', 'frequency' => 'monthly',
            'start_date' => $lease->commencement_date, 'is_active' => true,
        ]);
    }

    return $lease->fresh();
}

function stepBay(Lease $lease, string $code, float $rate = 500): RentableItem
{
    return RentableItem::create([
        'asset_id' => $lease->unit->asset_id, 'code' => $code, 'type' => 'parking',
        'status' => RentableItem::STATUS_AVAILABLE, 'monthly_rate' => $rate,
    ]);
}

/** Let a bay under a rule — the tab's own door. */
function letBay(Lease $lease, RentableItem $item, ?string $mode, ?float $rate = null, ?float $amount = null, ?string $from = null, ?float $monthly = null): void
{
    app(AssignRentableItemService::class)->assign($lease->fresh(), $item, array_filter([
        'effective_from' => $from,
        'monthly_rate' => $monthly,
        'escalation_mode' => $mode,
        'escalation_rate' => $rate,
        'escalation_amount' => $amount,
    ], fn ($v) => $v !== null));
}

function parkingLadder(Lease $lease): string
{
    return $lease->charges()->where('type', 'parking')->where('is_active', true)->orderBy('start_date')->get()
        ->map(fn (Charge $c) => number_format((float) $c->amount, 2, '.', '').'@'.$c->start_date->format('Y-m'))
        ->implode(' ');
}

function holdingOf(Lease $lease, RentableItem $item): object
{
    return DB::table('rentable_item_holdings')
        ->where('holder_type', 'lease')->where('holder_id', $lease->id)->where('rentable_item_id', $item->id)
        ->whereNull('effective_to')->sole();
}

it('projects the parking ladder from each held item\'s own rule the day a bay is let', function () {
    CarbonImmutable::setTestNow('2025-06-15');
    $lease = stepLease();
    letBay($lease, stepBay($lease, 'P-A'), ChargeEscalation::FIXED_AMOUNT, amount: 100);
    letBay($lease, stepBay($lease, 'P-B', 1000), ChargeEscalation::PERCENT, rate: 10);

    // 500 +100 a year beside 1,000 +10 % a year: 1,500 → 1,700 → 1,910, one rung per anniversary,
    // the register's own arithmetic on the one parking row.
    expect(parkingLadder($lease))->toBe('1500.00@2025-06 1700.00@2026-01 1910.00@2027-01')
        ->and($lease->charges()->where('type', 'parking')->where('is_active', true)->orderByDesc('start_date')->first()->origin)
        ->toBe(Charge::ORIGIN_ESCALATION);
});

it('steps each item on the anniversary, stores the new rate on its holding and records its own sentence', function () {
    CarbonImmutable::setTestNow('2025-06-15');
    $lease = stepLease();
    $a = stepBay($lease, 'P-A');
    $b = stepBay($lease, 'P-B', 1000);
    letBay($lease, $a, ChargeEscalation::FIXED_AMOUNT, amount: 100);
    letBay($lease, $b, ChargeEscalation::PERCENT, rate: 10);

    CarbonImmutable::setTestNow('2026-01-02');
    $stats = app(RentEscalationService::class)->runForToday();

    expect($stats['applied'])->toBe(1)
        // The rent by the clause; each bay by its own rule, the stepped rate now ON the holding —
        // the rent's own discipline, so a re-sum next month reads 600 and 1,100, not the signing figures.
        ->and((float) $lease->fresh()->base_rent_monthly)->toBe(107000.0)
        ->and((float) holdingOf($lease, $a)->monthly_rate)->toBe(600.0)
        ->and((float) holdingOf($lease, $b)->monthly_rate)->toBe(1100.0)
        // The sweep's rung is the projection's rung: same money, no second row.
        ->and(parkingLadder($lease))->toBe('1500.00@2025-06 1700.00@2026-01 1910.00@2027-01')
        ->and($lease->fresh()->next_escalation_date->toDateString())->toBe('2027-01-01');

    $event = $lease->events()->latest('id')->first();
    expect($event->payload[LeaseEventNarrative::KEY])->toBe('charge_escalated_items')
        ->and(LeaseEventNarrative::resolve($event, 'en'))
        ->toBe('Contractual increase on Parking — 1,500.00 to 1,700.00 (P-A, P-B, each by its own rule).')
        ->and(LeaseEventNarrative::resolve($event, 'ar'))->toMatch('/\p{Arabic}/u')->toContain('P-A, P-B');
});

it('sweeps a lease whose rent never steps for the bay that does, arming the anniversary itself', function () {
    CarbonImmutable::setTestNow('2025-06-15');
    $lease = stepLease(['escalation_type' => 'none', 'escalation_rate' => 0, 'next_escalation_date' => null]);
    $bay = stepBay($lease, 'P-A');

    expect($lease->escalates())->toBeFalse();

    letBay($lease, $bay, ChargeEscalation::FIXED_AMOUNT, amount: 100);

    // Armed by the projection at the first anniversary on or after today, never in the past.
    expect($lease->fresh()->escalates())->toBeTrue()
        ->and($lease->fresh()->next_escalation_date->toDateString())->toBe('2026-01-01')
        ->and(parkingLadder($lease))->toBe('500.00@2025-06 600.00@2026-01 700.00@2027-01');

    CarbonImmutable::setTestNow('2026-01-02');
    expect(app(RentEscalationService::class)->runForToday()['applied'])->toBe(1)
        ->and((float) holdingOf($lease, $bay)->monthly_rate)->toBe(600.0)
        ->and((float) $lease->fresh()->base_rent_monthly)->toBe(100000.0)
        ->and($lease->fresh()->next_escalation_date->toDateString())->toBe('2027-01-01');

    // The pointer is kept on a `none` clause while the bay's rule stands — saving the lease
    // must not clear it (the `saving` branch reads the register too).
    $lease->fresh()->forceFill(['notes' => 'edited'])->save();
    expect($lease->fresh()->next_escalation_date->toDateString())->toBe('2027-01-01');
});

it('a follows-lease bay inherits the collared clause, and stands still under an amount clause', function () {
    CarbonImmutable::setTestNow('2025-06-15');

    $collared = stepLease(['escalation_ceiling_rate' => 5]);
    letBay($collared, stepBay($collared, 'P-A'), ChargeEscalation::FOLLOWS_LEASE);
    expect(parkingLadder($collared))->toBe('500.00@2025-06 525.00@2026-01 551.25@2027-01');

    $amount = stepLease(['escalation_type' => 'fixed_amount', 'escalation_rate' => 0, 'escalation_amount' => 5000]);
    $bay = stepBay($amount, 'P-B');
    letBay($amount, $bay, ChargeEscalation::FOLLOWS_LEASE);
    expect(parkingLadder($amount))->toBe('500.00@2025-06');

    CarbonImmutable::setTestNow('2026-01-02');
    app(RentEscalationService::class)->runForToday();
    expect((float) holdingOf($amount, $bay)->monthly_rate)->toBe(500.0)
        ->and((float) $amount->fresh()->base_rent_monthly)->toBe(105000.0);
});

it('re-sums a bay let mid-term at the rates already stepped, and one dated past the next anniversary at the rate it will bill', function () {
    CarbonImmutable::setTestNow('2025-06-15');
    $lease = stepLease();
    $a = stepBay($lease, 'P-A');
    letBay($lease, $a, ChargeEscalation::FIXED_AMOUNT, amount: 100);

    CarbonImmutable::setTestNow('2026-01-02');
    app(RentEscalationService::class)->runForToday();

    // A second bay in June, ruled to stand still: the in-force row is 600 + 300, and the next
    // rung 700 + 300 — the register's stepped rate, never the signing figure.
    CarbonImmutable::setTestNow('2026-06-10');
    letBay($lease, stepBay($lease, 'P-C', 300), ChargeEscalation::NONE);
    expect(parkingLadder($lease))->toBe('500.00@2025-06 600.00@2026-01 900.00@2026-06 1000.00@2027-01');

    // And a third dated from next March — PAST the January anniversary — is summed with A at the
    // 700 it will bill by then, not the 600 in force today.
    CarbonImmutable::setTestNow('2026-11-01');
    letBay($lease, stepBay($lease, 'P-D', 200), ChargeEscalation::NONE, from: '2027-03-01');
    expect(parkingLadder($lease))->toBe('500.00@2025-06 600.00@2026-01 900.00@2026-06 1000.00@2027-01 1200.00@2027-03');
});

it('takes a released bay\'s steps out of the ladder', function () {
    CarbonImmutable::setTestNow('2025-06-15');
    $lease = stepLease();
    $a = stepBay($lease, 'P-A');
    letBay($lease, $a, ChargeEscalation::FIXED_AMOUNT, amount: 100);
    letBay($lease, stepBay($lease, 'P-B', 1000), ChargeEscalation::PERCENT, rate: 10);

    app(AssignRentableItemService::class)->release($lease->fresh(), $a, '2025-12-31');

    expect(parkingLadder($lease))->toBe('1500.00@2025-06 1100.00@2026-01 1210.00@2027-01');
});

it('rules on a held item through the one writer, touching only the live holding', function () {
    CarbonImmutable::setTestNow('2025-02-01');
    $lease = stepLease();
    $a = stepBay($lease, 'P-A');
    letBay($lease, $a, ChargeEscalation::NONE, from: '2025-02-01');
    app(AssignRentableItemService::class)->release($lease->fresh(), $a, '2025-03-31');

    // The first holding's row was BOUNDED on release (a stop still ahead keeps its row active for
    // the months it covers — `close()`'s rule); the re-let opens a fresh one after the gap.
    CarbonImmutable::setTestNow('2025-07-01');
    letBay($lease, $a, ChargeEscalation::NONE, from: '2025-07-01');
    expect(parkingLadder($lease))->toBe('500.00@2025-02 500.00@2025-07');

    app(AssignRentableItemService::class)->setEscalation($lease->fresh(), $a, ChargeEscalation::PERCENT, 10);

    $rows = DB::table('rentable_item_holdings')->where('holder_id', $lease->id)->orderBy('id')->get();
    expect($rows)->toHaveCount(2)
        ->and($rows[0]->escalation_mode)->toBe(ChargeEscalation::NONE)
        ->and($rows[1]->escalation_mode)->toBe(ChargeEscalation::PERCENT)
        ->and((float) $rows[1]->escalation_rate)->toBe(10.0)
        ->and(parkingLadder($lease))->toBe('500.00@2025-02 500.00@2025-07 550.00@2026-01 605.00@2027-01');

    // A holding released at a date still AHEAD is live and may be ruled on (it steps on any
    // anniversary before it ends); once that date has passed it is history and the writer
    // refuses — the same set the tab's button shows.
    app(AssignRentableItemService::class)->release($lease->fresh(), $a, '2025-08-31');
    app(AssignRentableItemService::class)->setEscalation($lease->fresh(), $a, ChargeEscalation::NONE);

    CarbonImmutable::setTestNow('2025-09-15');
    expect(fn () => app(AssignRentableItemService::class)->setEscalation($lease->fresh(), $a, ChargeEscalation::NONE))
        ->toThrow(DomainException::class);
});

it('re-trues the parking ladder when the clause it follows changes', function () {
    CarbonImmutable::setTestNow('2025-06-15');
    $lease = stepLease();
    letBay($lease, stepBay($lease, 'P-A'), ChargeEscalation::FOLLOWS_LEASE);
    expect(parkingLadder($lease))->toBe('500.00@2025-06 535.00@2026-01 572.45@2027-01');

    $lease->fresh()->update(['escalation_rate' => 10]);

    expect(parkingLadder($lease))->toBe('500.00@2025-06 550.00@2026-01 605.00@2027-01');

    // And a CLEARED clause takes the bay's projected steps with it: nothing steps, so the rungs
    // must go — the prune, not the walk, is what removes them (the walk writes only where the
    // sum moves), so this is the case that proves parking's rungs are pruned with the rest.
    $lease->fresh()->update(['escalation_type' => 'none']);

    expect(parkingLadder($lease))->toBe('500.00@2025-06');
});

it('carries a bay\'s rule onto the renewal with its rate', function () {
    CarbonImmutable::setTestNow('2027-11-01');
    $lease = stepLease(['next_escalation_date' => '2028-01-01']);
    $bay = stepBay($lease, 'P-A');
    letBay($lease, $bay, ChargeEscalation::FIXED_AMOUNT, amount: 100, from: '2027-11-01');

    $renewal = app(LeaseRenewalService::class)->renew($lease->fresh(), ['new_term_months' => 12, 'new_rent' => 110000]);

    expect(holdingOf($renewal, $bay)->escalation_mode)->toBe(ChargeEscalation::FIXED_AMOUNT)
        ->and((float) holdingOf($renewal, $bay)->escalation_amount)->toBe(100.0);
});

it('stores no rule on an ownership\'s bay — an assessment has no anniversary', function () {
    $asset = makeAsset();
    $unit = makeUnit($asset);
    $ownership = UnitOwnership::create([
        'asset_id' => $asset->id, 'unit_id' => $unit->id, 'tenant_id' => makeTenant()->id,
        'reference' => 'OWN-'.uniqid(), 'status' => 'handed_over', 'tenure_type' => 'freehold',
        'management_mode' => 'self_occupied', 'started_at' => '2026-01-01',
    ]);
    $item = RentableItem::create([
        'asset_id' => $asset->id, 'code' => 'P-O', 'type' => 'parking',
        'status' => RentableItem::STATUS_AVAILABLE, 'monthly_rate' => 500,
    ]);

    app(AssignRentableItemService::class)->assign($ownership, $item, [
        'effective_from' => '2026-03-01', 'escalation_mode' => ChargeEscalation::PERCENT, 'escalation_rate' => 10,
    ]);

    expect(DB::table('rentable_item_holdings')->where('holder_id', $ownership->id)->sole()->escalation_mode)->toBeNull()
        ->and((float) RentableItemPricing::sumOn($ownership, CarbonImmutable::parse('2027-06-01')))->toBe(500.0);
});

it('re-derives the whole parking ladder from a back-dated assignment or release across a started rung', function () {
    CarbonImmutable::setTestNow('2025-06-15');
    $lease = stepLease();
    $a = stepBay($lease, 'P-A');
    letBay($lease, $a, ChargeEscalation::PERCENT, rate: 10);

    CarbonImmutable::setTestNow('2026-01-02');
    app(RentEscalationService::class)->runForToday();
    expect(parkingLadder($lease))->toBe('500.00@2025-06 550.00@2026-01 605.00@2027-01');

    // A second bay let from LAST December, keyed in March: the started January rung is part of
    // the derivation, not history to leave standing — moving one row and keeping the rung left
    // the bay unbilled for the whole of 2026 (found by review). The months before the applied
    // anniversary read A at its stepped 550: the stated limit of storing the rate in force.
    CarbonImmutable::setTestNow('2026-03-10');
    letBay($lease, stepBay($lease, 'P-C', 300), ChargeEscalation::NONE, from: '2025-12-01');
    expect(parkingLadder($lease))->toBe('500.00@2025-06 850.00@2025-12 905.00@2027-01');

    // And a back-dated release of the stepped bay takes it out of every later row — the rung
    // it had stepped into no longer bills it for the rest of the term.
    app(AssignRentableItemService::class)->release($lease->fresh(), $a, '2025-11-30');
    expect(parkingLadder($lease))->toBe('500.00@2025-06 300.00@2025-12');
});

it('keeps billing the months a future-dated release still covers, and re-lets without double-counting', function () {
    CarbonImmutable::setTestNow('2025-06-15');
    $lease = stepLease();
    $a = stepBay($lease, 'P-A');
    letBay($lease, $a, ChargeEscalation::NONE);

    // Released at the year end, recorded in June: July to December still bill — the row is
    // BOUNDED, never switched off (`is_active => false` on it dropped the bay from every
    // invoice until the release date, found by review).
    app(AssignRentableItemService::class)->release($lease->fresh(), $a, '2025-12-31');
    $row = $lease->charges()->where('type', 'parking')->sole();
    expect((bool) $row->is_active)->toBeTrue()
        ->and($row->end_date->toDateString())->toBe('2025-12-31')
        ->and(app(ChargeScheduleService::class)->rowCovering($lease, 'parking', CarbonImmutable::parse('2025-10-01'))?->id)->toBe($row->id)
        ->and(app(ChargeScheduleService::class)->rowCovering($lease, 'parking', CarbonImmutable::parse('2026-01-01')))->toBeNull();

    // Re-let from January: a fresh row from the re-let, the ended one left where it ended —
    // never extended over the gap.
    CarbonImmutable::setTestNow('2026-01-05');
    letBay($lease, $a, ChargeEscalation::NONE, from: '2026-01-01', monthly: 700);
    expect(parkingLadder($lease))->toBe('500.00@2025-06 700.00@2026-01')
        ->and($lease->charges()->where('type', 'parking')->where('is_active', true)->first()->end_date->toDateString())->toBe('2025-12-31');

    // A second release closes ONE holding — the live one — never every holding of the item
    // (`updateExistingPivot()` closed the spring's row on the summer's date and the bay was
    // held twice, found by review, pre-existing).
    app(AssignRentableItemService::class)->release($lease->fresh(), $a, '2026-06-30');
    $rows = DB::table('rentable_item_holdings')->where('holder_id', $lease->id)->orderBy('id')->get();
    expect($rows[0]->effective_to)->toBe('2025-12-31')
        ->and($rows[1]->effective_to)->toBe('2026-06-30')
        ->and(RentableItemPricing::heldOn($lease->fresh(), CarbonImmutable::parse('2026-03-01')))->toHaveCount(1);
});

it('rules the holding that goes on when a re-let overlaps a release still ahead', function () {
    CarbonImmutable::setTestNow('2025-06-15');
    $lease = stepLease();
    $a = stepBay($lease, 'P-A');
    letBay($lease, $a, ChargeEscalation::NONE);
    app(AssignRentableItemService::class)->release($lease->fresh(), $a, '2025-06-30');
    letBay($lease, $a, ChargeEscalation::NONE, from: '2025-07-01');

    app(AssignRentableItemService::class)->setEscalation($lease->fresh(), $a, ChargeEscalation::PERCENT, 10);

    $rows = DB::table('rentable_item_holdings')->where('holder_id', $lease->id)->orderBy('id')->get();
    expect($rows[0]->escalation_mode)->toBe(ChargeEscalation::NONE)
        ->and($rows[1]->escalation_mode)->toBe(ChargeEscalation::PERCENT);
});

it('narrates the step from what stepped, not from the row a newly-let bay just joined', function () {
    CarbonImmutable::setTestNow('2025-06-15');
    $lease = stepLease();
    $a = stepBay($lease, 'P-A');
    letBay($lease, $a, ChargeEscalation::PERCENT, rate: 10);

    // A second bay let in the anniversary month, before the sweep: it sits in the new sum
    // without having moved, so the sentence is 800 → 850, not the eve row's 500 → 850.
    CarbonImmutable::setTestNow('2026-01-01');
    letBay($lease, stepBay($lease, 'P-B', 300), ChargeEscalation::NONE);
    CarbonImmutable::setTestNow('2026-01-02');
    app(RentEscalationService::class)->runForToday();

    $event = $lease->events()->latest('id')->first();
    expect(LeaseEventNarrative::resolve($event, 'en'))
        ->toBe('Contractual increase on Parking — 800.00 to 850.00 (P-A, each by its own rule).')
        ->and(parkingLadder($lease))->toBe('500.00@2025-06 850.00@2026-01 905.00@2027-01');
});

it('refuses a holding dated before the lease begins, in the operator\'s words', function () {
    CarbonImmutable::setTestNow('2025-06-15');
    $lease = stepLease();
    $a = stepBay($lease, 'P-A');

    expect(fn () => letBay($lease, $a, ChargeEscalation::NONE, from: '2024-11-01'))
        ->toThrow(DomainException::class, '01/01/2025');

    letBay($lease, $a, ChargeEscalation::NONE, from: '2025-01-01');
    expect(parkingLadder($lease))->toBe('500.00@2025-01');
});

describe('through the panel', function () {
    beforeEach(function () {
        $this->seed(RolesPermissionsSeeder::class);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->actingAs(makeUser('super_admin'));
        $this->asset = makeAsset(['code' => 'RIS']);
        CarbonImmutable::setTestNow('2025-06-01');
    });

    it('creates a lease with its bays through the form, through the one assignment door', function () {
        asTenant($this->asset, function () {
            $unit = makeUnit($this->asset, ['status' => 'vacant']);
            $tenant = makeTenant();
            $a = RentableItem::create(['asset_id' => $this->asset->id, 'code' => 'P-A', 'type' => 'parking', 'status' => 'available', 'monthly_rate' => 500]);
            $b = RentableItem::create(['asset_id' => $this->asset->id, 'code' => 'P-B', 'type' => 'storage', 'status' => 'available', 'monthly_rate' => 800]);

            Livewire::test(CreateLease::class)->fillForm([
                'unit_id' => $unit->id, 'tenant_id' => $tenant->id, 'status' => 'active',
                'commencement_date' => '2025-06-01', 'term_months' => 36, 'expiry_date' => '2028-05-31',
                'base_rent_monthly' => 1000, 'service_charge_monthly' => 250,
                'escalation_type' => 'fixed_percent', 'escalation_rate' => 10, 'security_deposit_months' => 3,
                'rentable_items' => [
                    ['rentable_item_id' => $a->id, 'monthly_rate' => 500, 'effective_from' => null, 'escalation_mode' => ChargeEscalation::FIXED_AMOUNT, 'escalation_rate' => null, 'escalation_amount' => 50],
                    ['rentable_item_id' => $b->id, 'monthly_rate' => 700, 'effective_from' => '2025-09-01', 'escalation_mode' => ChargeEscalation::FOLLOWS_LEASE, 'escalation_rate' => null, 'escalation_amount' => null],
                ],
            ])->call('create')->assertHasNoFormErrors();

            $lease = Lease::where('tenant_id', $tenant->id)->sole();

            // Both held — A from the commencement, B from the date typed, at the negotiated 700 —
            // each under its rule, and the parking row projected from them: 500 → 1,200 in
            // September → 1,320 on the first anniversary (550 + 770).
            expect(holdingOf($lease, $a)->effective_from)->toBe('2025-06-01')
                ->and(holdingOf($lease, $a)->escalation_mode)->toBe(ChargeEscalation::FIXED_AMOUNT)
                ->and(holdingOf($lease, $b)->effective_from)->toBe('2025-09-01')
                ->and((float) holdingOf($lease, $b)->monthly_rate)->toBe(700.0)
                ->and(holdingOf($lease, $b)->escalation_mode)->toBe(ChargeEscalation::FOLLOWS_LEASE)
                ->and(parkingLadder($lease))->toBe('500.00@2025-06 1200.00@2025-09 1320.00@2026-06 1447.00@2027-06');
        });
    });

    it('lets no bay to a draft, and says which were not let', function () {
        asTenant($this->asset, function () {
            $unit = makeUnit($this->asset, ['status' => 'vacant']);
            $tenant = makeTenant();
            $a = RentableItem::create(['asset_id' => $this->asset->id, 'code' => 'P-A', 'type' => 'parking', 'status' => 'available', 'monthly_rate' => 500]);

            Livewire::test(CreateLease::class)->fillForm([
                'unit_id' => $unit->id, 'tenant_id' => $tenant->id, 'status' => 'draft',
                'commencement_date' => '2025-06-01', 'term_months' => 36, 'expiry_date' => '2028-05-31',
                'base_rent_monthly' => 1000, 'service_charge_monthly' => 250,
                'escalation_type' => 'fixed_percent', 'escalation_rate' => 10, 'security_deposit_months' => 3,
                'rentable_items' => [
                    ['rentable_item_id' => $a->id, 'monthly_rate' => 500, 'effective_from' => null, 'escalation_mode' => ChargeEscalation::NONE, 'escalation_rate' => null, 'escalation_amount' => null],
                ],
            ])->call('create')->assertHasNoFormErrors()
                ->assertNotified(__('admin.rentable_items.not_attached_title'));

            $lease = Lease::where('tenant_id', $tenant->id)->sole();
            expect($lease->status)->toBe('draft')
                ->and($lease->rentableItems()->count())->toBe(0)
                ->and($lease->charges()->where('type', 'parking')->count())->toBe(0);
        });
    });

    it('creates a lease with its bays through the quick wizard, driven through its own modal', function () {
        asTenant($this->asset, function () {
            $unit = makeUnit($this->asset, ['status' => 'vacant']);
            $tenant = makeTenant();
            $a = RentableItem::create(['asset_id' => $this->asset->id, 'code' => 'P-A', 'type' => 'parking', 'status' => 'available', 'monthly_rate' => 500]);

            // Through the ACTION, because an action's `$data` is the DEHYDRATED state: with the
            // create form's `dehydrated(false)` the wizard's third step accepted the rows, created
            // the lease and let nothing (found by review — a service-level test could not see it).
            Livewire::test(ListLeases::class)
                ->callAction(TestAction::make('quickLease')->table(), data: [
                    'tenant_mode' => 'existing', 'tenant_id' => $tenant->id,
                    'lease' => [
                        'unit_id' => $unit->id, 'commencement_date' => '2025-06-01', 'term_months' => 36,
                        'base_rent_monthly' => 1000, 'service_charge_monthly' => 0, 'escalation_rate' => 7, 'payment_terms_days' => 7,
                    ],
                    'rentable_items' => [
                        ['rentable_item_id' => $a->id, 'monthly_rate' => 500, 'effective_from' => null, 'escalation_mode' => ChargeEscalation::PERCENT, 'escalation_rate' => 10, 'escalation_amount' => null],
                    ],
                ])
                ->assertHasNoActionErrors();

            $lease = Lease::where('tenant_id', $tenant->id)->sole();

            expect(holdingOf($lease, $a)->effective_from)->toBe('2025-06-01')
                ->and((float) holdingOf($lease, $a)->monthly_rate)->toBe(500.0)
                ->and(parkingLadder($lease))->toBe('500.00@2025-06 550.00@2026-06 605.00@2027-06');
        });
    });

    it('rules on a charge from the schedule tab through the same writer, and the form\'s table refills from it', function () {
        asTenant($this->asset, function () {
            $lease = stepLease(['unit_id' => makeUnit($this->asset)->id], $this->asset);
            $service = $lease->charges()->where('type', 'service_charge')->sole();

            Livewire::test(ChargeScheduleRelationManager::class, ['ownerRecord' => $lease, 'pageClass' => EditLease::class])
                ->assertTableActionVisible('setEscalation', $service)
                ->assertTableActionHidden('setEscalation', $lease->charges()->where('type', 'base_rent')->sole())
                ->callTableAction('setEscalation', $service, data: ['escalation_mode' => ChargeEscalation::PERCENT, 'escalation_rate' => 5])
                ->assertHasNoTableActionErrors();

            expect(ChargeEscalation::modeOf($service->fresh()))->toBe(ChargeEscalation::PERCENT)
                ->and($lease->charges()->where('type', 'service_charge')->where('is_active', true)->count())->toBe(3);

            // The page's derived table re-reads the schedule when a tab announces a change —
            // the row the tab just ruled on reads as the tab wrote it, not as the form mounted.
            $page = Livewire::test(EditLease::class, ['record' => $lease->getKey()]);
            $mounted = collect($page->get('data.charge_escalations'))->firstWhere('type', 'service_charge');
            expect($mounted['escalation_mode'])->toBe(ChargeEscalation::PERCENT);

            // Through the EVENT a tab dispatches, not the listener method by name: an aliased trait
            // listener kept its `#[On]` and won the event, so the override was never reached and
            // calling the method proved nothing (found by review).
            app(ChargeScheduleService::class)->setEscalation($lease->fresh(), 'service_charge', ChargeEscalation::FIXED_AMOUNT, amount: 900);
            $page->dispatch(RecordChanged::EVENT);
            $refilled = collect($page->get('data.charge_escalations'))->firstWhere('type', 'service_charge');
            expect($refilled['escalation_mode'])->toBe(ChargeEscalation::FIXED_AMOUNT)
                ->and((float) $refilled['escalation_amount'])->toBe(900.0);
        });
    });

    it('rules on a bay from the items tab, and the form\'s table shows the register\'s rules in words', function () {
        asTenant($this->asset, function () {
            $lease = stepLease(['unit_id' => makeUnit($this->asset)->id], $this->asset);
            $a = stepBay($lease, 'P-A');
            $gone = stepBay($lease, 'P-Z');
            // A bay held and given back in the spring: history, ruled on by nobody from here on.
            letBay($lease, $gone, ChargeEscalation::NONE, from: '2025-03-01');
            app(AssignRentableItemService::class)->release($lease->fresh(), $gone, '2025-04-30');
            letBay($lease, $a, ChargeEscalation::NONE);

            $tab = Livewire::test(LeaseRentableItemsRelationManager::class, ['ownerRecord' => $lease->fresh(), 'pageClass' => EditLease::class]);
            $tab->assertTableActionVisible('setEscalation', $a)
                ->assertTableActionHidden('setEscalation', $gone)
                ->callTableAction('setEscalation', $a, data: ['escalation_mode' => ChargeEscalation::FIXED_AMOUNT, 'escalation_amount' => 75])
                ->assertHasNoTableActionErrors();

            // The spring's row — a stop already past when it was recorded — is switched off by
            // `close()`'s rule; the summer's holding has the active ladder to itself.
            expect(holdingOf($lease, $a)->escalation_mode)->toBe(ChargeEscalation::FIXED_AMOUNT)
                ->and(parkingLadder($lease))->toBe('500.00@2025-06 575.00@2026-01 650.00@2027-01');

            // One reading, two surfaces: the tab's column and the form's derived parking row say the same words.
            $rows = EditLease::chargeEscalationRows($lease->fresh());
            $parking = collect($rows)->firstWhere('type', 'parking');
            expect($parking['derived'])->toBeTrue()
                ->and($parking['summary'])->toBe('P-A — '.__('admin.charge_escalation.own_amount', ['amount' => '75.00']));

            $tab->assertSee(__('admin.charge_escalation.own_amount', ['amount' => '75.00']));

            $en = Livewire::test(EditLease::class, ['record' => $lease->getKey()]);
            $en->assertSee('P-A — +EGP 75.00 a year')->assertSee('Parking & rentable items')
                ->assertDontSee('admin.charge_escalation')->assertDontSee('admin.sections');

            app()->setLocale('ar');
            Livewire::test(EditLease::class, ['record' => $lease->getKey()])
                ->assertSee('P-A — ')->assertDontSee('admin.charge_escalation')->assertDontSee('admin.sections');
        });
    });

    it('shows the items section from the first render, with the table appearing the moment the status leaves draft', function () {
        asTenant($this->asset, function () {
            // The status select must be LIVE: the section's contents read it, and a browser only
            // re-renders on a change the field announces. Without this the table never appeared
            // for the whole of a create (reported 2026-09-12) while this very test stayed green,
            // because `fillForm()` re-renders whatever the field says — so the tooth is the flag.
            $page = Livewire::test(CreateLease::class);
            $page->assertFormFieldExists('status', checkFieldUsing: fn (Select $field): bool => $field->isLive());

            // Under the default Draft the section is on screen with its note and no table.
            // (A Placeholder is not a Field, so the note is asserted on the RENDER — a hidden
            // component emits nothing — while the repeater is asserted as a field.)
            $page->assertSee(__('admin.sections.rentable_items_at_creation'))
                ->assertSee(__('admin.rentable_items.draft_holds_none'))
                ->assertFormFieldHidden('rentable_items');

            $page->fillForm(['status' => 'active'])
                ->assertFormFieldVisible('rentable_items')
                ->assertDontSee(__('admin.rentable_items.draft_holds_none'))
                ->assertDontSee('admin.sections.rentable')->assertDontSee('admin.actions.add_rentable')
                ->assertDontSee('admin.rentable_items.draft');

            app()->setLocale('ar');
            Livewire::test(CreateLease::class)
                ->assertSee('المواقف والعناصر المؤجَّرة')->assertSee('المسودة لا تحوز')
                ->assertDontSee('admin.sections.rentable')->assertDontSee('admin.rentable_items.draft');
        });
    });
});
