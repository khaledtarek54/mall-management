<?php

use App\Filament\Admin\RelationManagers\ChargeScheduleRelationManager;
use App\Filament\Admin\Resources\Leases\Pages\EditLease;
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

function ladderLease(): \App\Models\Lease
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
    CarbonImmutable::setTestNow('2031-06-10');
    $lease = ladderLease();

    $description = Livewire::test(ChargeScheduleRelationManager::class, [
        'ownerRecord' => $lease,
        'pageClass' => EditLease::class,
    ])->instance()->getTableDescription();

    expect($description)->toContain(__('admin.charge_schedule.no_further_steps'));
});

it('is read-only — rent changes go through the service, never a cell edit', function () {
    // An editable amount here would reintroduce exactly the drift ChargeScheduleService exists to
    // prevent: overwriting a row instead of closing it and opening the next.
    CarbonImmutable::setTestNow('2026-06-10');
    $lease = ladderLease();

    $table = Livewire::test(ChargeScheduleRelationManager::class, [
        'ownerRecord' => $lease,
        'pageClass' => EditLease::class,
    ])->instance()->getTable();

    expect($table->getActions())->toBe([])
        ->and($table->getHeaderActions())->toBe([]);
});
