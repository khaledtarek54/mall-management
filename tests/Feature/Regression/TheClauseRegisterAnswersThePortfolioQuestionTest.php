<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| The query existed; nothing called it (2026-08-31)
|--------------------------------------------------------------------------
| `LeaseClause` was built to make one question answerable, and its own docblock states it:
|
| > "nothing can even answer 'how many of our leases have a co-tenancy trigger tied to the anchor we
| > are about to lose?'"
|
| The abstract shipped, `contingentMoney()` / `inForceOn()` / `liveExposure()` were written to
| answer exactly that, and `liveExposure()` had NO CALLER anywhere in `app/` — only in
| `LeaseClausesAreAbstractedTest`. Fully built, fully tested and unreachable: the shape this repo
| names for the four orphaned services found in August, where the green test file is precisely what
| made it look maintained. Ninety-nine clauses sat on the demo books, readable one lease at a time.
|
| This file is about the SCREEN that gives it a caller, so every assertion drives the real page.
*/

use App\Filament\Admin\Pages\ClauseRegister;
use App\Models\Lease;
use App\Models\LeaseClause;
use Database\Seeders\RolesPermissionsSeeder;
use Filament\Facades\Filament;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->seed(RolesPermissionsSeeder::class);
    ensureAllPropertiesAsset();
    $this->asset = makeAsset();
    $this->actingAs(makeUser('leasing', [$this->asset->id]));
    Filament::setTenant($this->asset);
});

function clauseOn(Lease $lease, string $type, array $attrs = []): LeaseClause
{
    return $lease->clauses()->create([...['type' => $type, 'summary' => "a {$type} clause"], ...$attrs]);
}

it('lists every abstracted clause, whichever lease it sits on', function (): void {
    $a = makeLease(makeUnit($this->asset));
    $b = makeLease(makeUnit($this->asset));

    clauseOn($a, LeaseClause::TYPE_EXCLUSIVITY);
    clauseOn($b, LeaseClause::TYPE_RADIUS, ['radius_km' => 5]);

    Livewire::test(ClauseRegister::class)
        ->assertOk()
        ->assertCanSeeTableRecords(LeaseClause::all());
});

/**
 * THE QUESTION, ASKED THROUGH THE SCREEN.
 *
 * The subheading is the deliverable — an operator opens this page to learn the number, and it must
 * be the model's own `liveExposure()` rather than a count reassembled from the table's filters.
 */
it('answers how many leases carry a live contingent-money trigger', function (): void {
    $exposed = makeLease(makeUnit($this->asset), null, ['status' => 'active']);
    clauseOn($exposed, LeaseClause::TYPE_CO_TENANCY, ['threshold_pct' => 70]);

    // Same clause, but the tenancy it protected has ended — the case `liveExposure()`'s docblock
    // records getting wrong: an open-ended clause reads as in force for ever.
    $ended = makeLease(makeUnit($this->asset), null, ['status' => 'terminated']);
    clauseOn($ended, LeaseClause::TYPE_KICK_OUT, ['threshold_amount' => 250000]);

    // And a clause that is not contingent money at all.
    clauseOn(makeLease(makeUnit($this->asset)), LeaseClause::TYPE_SIGNAGE);

    $subheading = Livewire::test(ClauseRegister::class)->instance()->getSubheading();

    expect($subheading)
        ->toContain('3 abstracted clauses')
        ->and($subheading)->toContain('One lease is exposed');
});

it('narrows to contingent money on live leases when that filter is on', function (): void {
    $exposed = makeLease(makeUnit($this->asset), null, ['status' => 'active']);
    $trigger = clauseOn($exposed, LeaseClause::TYPE_CO_TENANCY, ['threshold_pct' => 70]);

    $ended = makeLease(makeUnit($this->asset), null, ['status' => 'terminated']);
    $onDeadLease = clauseOn($ended, LeaseClause::TYPE_KICK_OUT, ['threshold_amount' => 250000]);

    $signage = clauseOn(makeLease(makeUnit($this->asset)), LeaseClause::TYPE_SIGNAGE);

    Livewire::test(ClauseRegister::class)
        ->filterTable('live_exposure')
        ->assertCanSeeTableRecords([$trigger])
        // Paired with what must DISAPPEAR: a filter that returned everything would satisfy the
        // first assertion on its own.
        ->assertCanNotSeeTableRecords([$onDeadLease, $signage]);
});

it('narrows to one clause type', function (): void {
    $lease = makeLease(makeUnit($this->asset));
    $radius = clauseOn($lease, LeaseClause::TYPE_RADIUS, ['radius_km' => 5]);
    $signage = clauseOn($lease, LeaseClause::TYPE_SIGNAGE);

    Livewire::test(ClauseRegister::class)
        ->filterTable('type', [LeaseClause::TYPE_RADIUS])
        ->assertCanSeeTableRecords([$radius])
        ->assertCanNotSeeTableRecords([$signage]);
});

/**
 * PROPERTY ISOLATION — the one thing a portfolio-wide report must not get wrong.
 *
 * A clause is `#[PropertyOwned(via: 'lease.unit')]`, two hops from an `asset_id`, which is exactly
 * the shape a one-hop scope answers null for and lets through.
 */
it('never shows a clause from a property the operator cannot see', function (): void {
    $mine = clauseOn(makeLease(makeUnit($this->asset)), LeaseClause::TYPE_CO_TENANCY, ['threshold_pct' => 70]);

    $other = makeAsset();
    $theirs = clauseOn(makeLease(makeUnit($other)), LeaseClause::TYPE_CO_TENANCY, ['threshold_pct' => 80]);

    Livewire::test(ClauseRegister::class)
        ->assertCanSeeTableRecords([$mine])
        ->assertCanNotSeeTableRecords([$theirs]);

    // The COUNT is scoped too, not just the rows: a subheading that counted the portfolio while the
    // table showed one mall would be the "right figures, wrong caption" failure EG-27 records.
    expect(Livewire::test(ClauseRegister::class)->instance()->getSubheading())
        ->toContain('1 abstracted clauses')
        ->and(Livewire::test(ClauseRegister::class)->instance()->getSubheading())->toContain('One lease is exposed');
});

it('refuses an operator without the reports right, and admits one with it', function (): void {
    $this->actingAs(makeUser('technician', [$this->asset->id]));
    Filament::setTenant($this->asset);
    expect(ClauseRegister::canAccess())->toBeFalse();

    // The control: without it the refusal would pass just as happily if canAccess() always said no.
    $this->actingAs(makeUser('leasing', [$this->asset->id]));
    Filament::setTenant($this->asset);
    expect(ClauseRegister::canAccess())->toBeTrue();
});

/**
 * The trigger column reads whichever of the four columns the clause type actually uses — a register
 * showing only `threshold_pct` prints a blank cell for three types out of four.
 */
it('shows each clause type its own number', function (): void {
    $lease = makeLease(makeUnit($this->asset));

    $coTenancy = clauseOn($lease, LeaseClause::TYPE_CO_TENANCY, ['threshold_pct' => 70]);
    $assignment = clauseOn($lease, LeaseClause::TYPE_ASSIGNMENT, ['notice_days' => 30]);

    expect(ClauseRegister::trigger($coTenancy))->toBe('70%')
        ->and(ClauseRegister::trigger(clauseOn($lease, LeaseClause::TYPE_KICK_OUT, ['threshold_amount' => 250000])))->toBe('EGP 250,000.00')
        ->and(ClauseRegister::trigger(clauseOn($lease, LeaseClause::TYPE_RADIUS, ['radius_km' => 5])))->toBe('5 km')
        // The one the lease's own Clauses tab could not show: an assignment clause's only number
        // is its notice period, and that column read three of the four.
        ->and(ClauseRegister::trigger($assignment))->toContain('30')
        ->and(ClauseRegister::trigger(clauseOn($lease, LeaseClause::TYPE_SIGNAGE)))->toBe('—');

    // …and the register and the lease tab print the SAME thing, because they ask the same model.
    expect(ClauseRegister::trigger($assignment))->toBe($assignment->triggerLabel())
        ->and(ClauseRegister::trigger($coTenancy))->toBe($coTenancy->triggerLabel());
});

it('exports what the operator is looking at, not the whole register', function (): void {
    $lease = makeLease(makeUnit($this->asset), null, ['status' => 'active']);
    clauseOn($lease, LeaseClause::TYPE_CO_TENANCY, ['threshold_pct' => 70]);
    clauseOn($lease, LeaseClause::TYPE_SIGNAGE);

    $all = Livewire::test(ClauseRegister::class)->instance()->reportCsv();
    expect($all['rows'])->toHaveCount(2);

    $filtered = Livewire::test(ClauseRegister::class)
        ->filterTable('live_exposure')
        ->instance()
        ->reportCsv();

    expect($filtered['rows'])->toHaveCount(1)
        ->and($filtered['headers'])->toHaveCount(count($filtered['rows'][0]));
});
