<?php

use App\Filament\Admin\RelationManagers\ChargeScheduleRelationManager;
use App\Filament\Admin\Resources\Leases\Pages\EditLease;
use App\Models\Charge;
use App\Models\Lease;
use App\Services\LeaseCreationService;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesPermissionsSeeder;
use Filament\Facades\Filament;
use Livewire\Livewire;

/**
 * The charge schedule has to be VISIBLE (UX-02).
 *
 * Phase 1 turned the rent into a date-ranged ladder and left it readable only in the database —
 * the lease form still showed a single `base_rent_monthly`, so an operator had no way to see next
 * year's step or last year's rent. A model nobody can look at is a model nobody trusts.
 *
 * These tests assert the ladder actually renders, not merely that the page returns 200: an empty
 * table passes assertOk() and tells the operator the schedule does not exist.
 */
afterEach(fn () => CarbonImmutable::setTestNow());

beforeEach(function () {
    $this->seed(RolesPermissionsSeeder::class);
    $this->asset = makeAsset();
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $this->actingAs(makeUser('super_admin', [$this->asset->id]));
    Filament::setTenant($this->asset);
});

afterEach(fn () => Filament::setTenant(null, isQuiet: true));

function ladderLease(): Lease
{
    return app(LeaseCreationService::class)->create([
        'tenant_mode' => 'existing',
        'tenant_id' => makeTenant()->id,
        'lease' => [
            'unit_id' => makeUnit(test()->asset)->id,
            'commencement_date' => '2026-01-01',
            'term_months' => 60,
            'base_rent_monthly' => 100000,
            'service_charge_monthly' => 20000,
            'escalation_rate' => 7,
            'has_marketing_levy' => false,
        ],
    ])->fresh();
}

it('renders every step of the ladder, not just the rent in force', function () {
    CarbonImmutable::setTestNow('2026-06-10');
    $lease = ladderLease();

    Livewire::test(ChargeScheduleRelationManager::class, [
        'ownerRecord' => $lease,
        'pageClass' => EditLease::class,
    ])
        ->assertOk()
        // Five years of contracted rent, all on the page — the whole point of the schedule.
        ->assertSee('100,000')
        ->assertSee('107,000')
        ->assertSee('114,490')
        ->assertSee('122,504')
        ->assertSee('131,079');
});

it('labels which row is billing now and which is still scheduled', function () {
    CarbonImmutable::setTestNow('2026-06-10');
    $lease = ladderLease();

    Livewire::test(ChargeScheduleRelationManager::class, [
        'ownerRecord' => $lease,
        'pageClass' => EditLease::class,
    ])
        ->assertSee(__('admin.charge_schedule.states.current'))
        ->assertSee(__('admin.charge_schedule.states.future'));
});

it('says why each row exists', function () {
    CarbonImmutable::setTestNow('2026-06-10');
    $lease = ladderLease();

    Livewire::test(ChargeScheduleRelationManager::class, [
        'ownerRecord' => $lease,
        'pageClass' => EditLease::class,
    ])
        ->assertSee(__('admin.charge_schedule.origins.seed'))
        ->assertSee(__('admin.charge_schedule.origins.escalation'));
});

it('headlines what is billing now and when it next changes', function () {
    CarbonImmutable::setTestNow('2026-06-10');
    $lease = ladderLease();

    $description = Livewire::test(ChargeScheduleRelationManager::class, [
        'ownerRecord' => $lease,
        'pageClass' => EditLease::class,
    ])->instance()->getTableDescription();

    expect($description)->toContain('100,000.00')   // billing now
        ->and($description)->toContain('107,000.00') // the next step…
        ->and($description)->toContain('01/01/2027'); // …and when
});

it('says there are no further steps when the ladder has run out', function () {
    // Also pins the narrower rule: this lease's next_escalation_date is in the PAST here (the
    // sweep never ran), and an overdue escalation must NOT be described as "not yet scheduled" —
    // that is a different problem and would be a second wrong answer.
    CarbonImmutable::setTestNow('2031-06-10');
    $lease = ladderLease();

    $description = Livewire::test(ChargeScheduleRelationManager::class, [
        'ownerRecord' => $lease,
        'pageClass' => EditLease::class,
    ])->instance()->getTableDescription();

    expect($description)->toContain(__('admin.charge_schedule.no_further_steps'));
});

it('never edits a row in place — every write goes through the schedule service', function () {
    // An editable amount here would reintroduce exactly the drift ChargeScheduleService exists to
    // prevent: overwriting a row instead of closing it and opening the next.
    //
    // The screen was action-less until 2026-08-11, when adding and ending a charge landed on it
    // (nothing else could put an accountant's own charge code on a lease), and `changeRent` and
    // `grantRelief` joined them on 2026-08-29 — they were reachable only from the page header, so
    // an operator looking at the schedule they wanted to change had to leave it to change it.
    //
    // So the assertion is not "no actions" and not a count. It is that every action present routes
    // through a SERVICE that closes a row and opens the next — `ChargeScheduleService::setAmount()`
    // for all four — and that nothing on a ROW edits or deletes it in place. An editable amount
    // here would reintroduce exactly the drift that service exists to prevent.
    CarbonImmutable::setTestNow('2026-06-10');
    $lease = ladderLease();

    $table = Livewire::test(ChargeScheduleRelationManager::class, [
        'ownerRecord' => $lease,
        'pageClass' => EditLease::class,
    ])->instance()->getTable();

    $names = fn (array $actions) => collect($actions)
        ->flatMap(fn ($a) => method_exists($a, 'getActions') ? $a->getActions() : [$a])
        ->map(fn ($a) => $a->getName())
        ->all();

    expect($names($table->getHeaderActions()))->toBe(['changeRent', 'grantRelief', 'addCharge'])
        // The half that carries the invariant: one row action, and it ENDS a row rather than
        // rewriting it. An `edit` or `delete` appearing here is the regression.
        ->and($names($table->getActions()))->toBe(['endCharge']);
});

it('warns when a contracted escalation is due but has never been scheduled', function () {
    // The state every lease signed before projection existed is in: one open-ended rent row and a
    // contracted increase the schedule knows nothing about. Saying "no further steps scheduled"
    // there tells the operator no increase is coming when the contract says one is.
    CarbonImmutable::setTestNow('2026-08-08');

    $lease = makeLease(makeUnit($this->asset), null, [
        'status' => 'active',
        'commencement_date' => '2026-03-01',
        'expiry_date' => '2029-03-01',
        'base_rent_monthly' => 66000,
        'escalation_type' => 'fixed_percent',
        'escalation_rate' => 7,
        'next_escalation_date' => '2027-03-01',
    ]);
    Charge::create([
        'lease_id' => $lease->id, 'name' => 'Base Rent', 'type' => 'base_rent',
        'origin' => Charge::ORIGIN_SEED, 'amount' => 66000, 'currency' => 'EGP',
        'frequency' => 'monthly', 'vat_applicable' => false, 'vat_rate' => 0, 'is_active' => true,
    ]);

    $description = Livewire::test(ChargeScheduleRelationManager::class, [
        'ownerRecord' => $lease->fresh(),
        'pageClass' => EditLease::class,
    ])->instance()->getTableDescription();

    expect($description)->toContain('01/03/2027')
        ->and($description)->not->toContain(__('admin.charge_schedule.no_further_steps'));
});

it('reads chronologically, and every column can be sorted', function () {
    // The schedule is a timeline first: "what changes next, across every charge type" must be
    // answerable by reading down the page. Grouping by type is available for the ladder view.
    //
    // The sortable assertion is not decoration — a hard orderBy in modifyQueryUsing used to run
    // BEFORE the table's own sort, so every column header appended a last-place key and clicking
    // one silently did nothing.
    CarbonImmutable::setTestNow('2026-06-10');
    $lease = ladderLease();

    $table = Livewire::test(ChargeScheduleRelationManager::class, [
        'ownerRecord' => $lease,
        'pageClass' => EditLease::class,
    ])->instance()->getTable();

    expect($table->getDefaultSortColumn())->toBe('start_date')
        ->and($table->getDefaultSortDirection())->toBe('asc')
        ->and($table->getColumn('start_date')->isSortable())->toBeTrue()
        ->and($table->getColumn('amount')->isSortable())->toBeTrue()
        ->and($table->getColumn('type')->isSortable())->toBeTrue()
        // …and the ladder view is still reachable.
        ->and(array_keys($table->getGroups()))->toContain('type');
});
