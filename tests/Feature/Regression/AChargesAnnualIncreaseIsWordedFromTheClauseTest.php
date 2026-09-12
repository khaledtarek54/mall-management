<?php

use App\Filament\Admin\Resources\Leases\Pages\EditLease;
use App\Models\Charge;
use App\Models\Lease;
use App\Support\ChargeEscalation;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesPermissionsSeeder;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\LeaseLadder;

/**
 * The "Which charges step" table words each charge's rule FROM THE CLAUSE — how much, how often,
 * and what a follows-lease row inherits — and its two columns say the same thing (Trello
 * O26YHJWG · TvHYVvEc · cdng18sM, 2026-09-12).
 *
 * Three sentences were wrong on the tester's screen. "+100% a year" over a clause whose *Steps
 * every* read 3 — the cadence was a literal; "Follows the rent — which steps by an AMOUNT, so this
 * charge stands still" under a clause of NONE — the two un-followable clauses shared one sentence;
 * and the *Annual increase* option reading "(no percentage to follow)" beside a *By* cell already
 * reading "the index, collared" — the option's label carries a fact from outside the select, and
 * Filament re-reads a non-native select's option LIST on open but never the label it displays, so
 * a clause typed live left the label a round trip behind. `ChargeEscalation::cadence()`,
 * `inheritance()` and `clauseFingerprint()` are the three fixes, and every sentence names the
 * COLLARED figure the ladder will actually carry.
 */
beforeEach(function () {
    $this->seed(RolesPermissionsSeeder::class);
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->actingAs(makeUser('super_admin'));
    $this->asset = makeAsset(['code' => 'WRD']);
    $this->travelTo(CarbonImmutable::parse('2026-09-10'));
});

/** A follows-lease service-charge row, as a `Charge` the describer reads. */
function followingRow(): Charge
{
    return (new Charge)->forceFill(['escalation_mode' => ChargeEscalation::FOLLOWS_LEASE]);
}

function clause(array $terms): Lease
{
    return (new Lease)->forceFill($terms + ['escalation_type' => 'fixed_percent', 'escalation_rate' => 10]);
}

/**
 * The follows-lease option's label as the page's own mode picker resolves it — read off the BUILT
 * component, because a non-native select renders its options as a JSON blob inside `x-data` that
 * `assertSee()` cannot match.
 */
function followsOptionOnPage(\Livewire\Features\SupportTesting\Testable $page): string
{
    $select = collect($page->instance()->form->getFlatComponents(withHidden: true))
        ->first(fn ($c): bool => $c instanceof \Filament\Forms\Components\Select && $c->getName() === 'escalation_mode');

    return $select->getOptions()[ChargeEscalation::FOLLOWS_LEASE];
}

it('says how OFTEN a charge steps, from the clause interval, in both languages', function () {
    $row = followingRow();

    expect(ChargeEscalation::describe($row, clause(['escalation_interval_months' => null])))->toBe('Follows the rent — +10% every year')
        ->and(ChargeEscalation::describe($row, clause(['escalation_interval_months' => 3])))->toBe('Follows the rent — +10% every 3 months')
        ->and(ChargeEscalation::describe($row, clause(['escalation_interval_months' => 24])))->toBe('Follows the rent — +10% every 2 years')
        ->and(ChargeEscalation::describe($row, clause(['escalation_interval_months' => 1])))->toBe('Follows the rent — +10% every month');

    // A charge on its OWN rule steps on the same anniversary, so it reads the same cadence.
    $own = (new Charge)->forceFill(['escalation_mode' => ChargeEscalation::PERCENT, 'escalation_rate' => 4]);
    expect(ChargeEscalation::describe($own, clause(['escalation_interval_months' => 6])))->toBe('+4% every 6 months');

    app()->setLocale('ar');
    expect(ChargeEscalation::describe($row, clause(['escalation_interval_months' => 3])))->toContain('كل 3 أشهر')
        ->and(ChargeEscalation::describe($row, clause(['escalation_interval_months' => 18])))->toContain('كل 18 شهرًا')
        ->and(ChargeEscalation::describe($row, clause(['escalation_interval_months' => null])))->toContain('سنويًا')
        ->and(ChargeEscalation::describe($row, clause(['escalation_interval_months' => 24])))->toContain('كل سنتين');
});

it('tells a clause of NONE from a clause in pounds, in both columns', function () {
    $row = followingRow();

    // NONE: the rent does not step. Neither column may say "by an amount".
    $none = clause(['escalation_type' => 'none', 'escalation_rate' => null]);
    expect(ChargeEscalation::describe($row, $none))->toBe('Follows the rent — which does not step, so this charge stands still')
        ->and(ChargeEscalation::options($none)[ChargeEscalation::FOLLOWS_LEASE])->toBe('Follows the rent\'s clause (the rent does not step)');

    // A stated rate of ZERO is a clause that does not step either — and so is one a ceiling of
    // zero clamps to nothing (the collared figure is what the ladder writes).
    expect(ChargeEscalation::describe($row, clause(['escalation_rate' => 0])))->toContain('does not step')
        ->and(ChargeEscalation::describe($row, clause(['escalation_rate' => 10, 'escalation_ceiling_rate' => 0])))->toContain('does not step');

    // AMOUNT: the rent steps, in pounds, which a percentage-following row cannot follow.
    $amount = clause(['escalation_type' => 'fixed_amount', 'escalation_amount' => 5000]);
    expect(ChargeEscalation::describe($row, $amount))->toBe('Follows the rent — which steps by an amount, so this charge stands still')
        ->and(ChargeEscalation::options($amount)[ChargeEscalation::FOLLOWS_LEASE])->toBe('Follows the rent\'s clause (the rent steps by an amount — nothing to follow)');

    // INDEX: both columns name the index.
    $cpi = clause(['escalation_type' => 'cpi', 'escalation_rate' => null]);
    expect(ChargeEscalation::describe($row, $cpi))->toBe('Follows the rent — the index, collared, every year')
        ->and(ChargeEscalation::options($cpi)[ChargeEscalation::FOLLOWS_LEASE])->toBe('Follows the rent\'s clause (the index)');
});

it('names the COLLARED figure a following charge will carry, the one the ladder writes', function () {
    $collared = clause(['escalation_rate' => 10, 'escalation_ceiling_rate' => 5]);

    expect(ChargeEscalation::describe(followingRow(), $collared))->toBe('Follows the rent — +5% every year')
        ->and(ChargeEscalation::options($collared)[ChargeEscalation::FOLLOWS_LEASE])->toBe('Follows the rent\'s clause (+5%)');
});

it('re-mounts the mode picker when the clause it names changes on the real edit page', function () {
    asTenant($this->asset, function () {
        $lease = LeaseLadder::testersLease($this->asset);
        app(\App\Services\ChargeScheduleService::class)->setEscalation($lease, 'service_charge', ChargeEscalation::FOLLOWS_LEASE);

        $keyFor = fn (array $clause): string => 'escalation_mode:'.md5(ChargeEscalation::clauseFingerprint((new Lease)->forceFill($clause)));
        $asSaved = ['escalation_type' => 'fixed_percent', 'escalation_rate' => '10.00', 'escalation_floor_rate' => null, 'escalation_ceiling_rate' => null, 'escalation_interval_months' => null];

        $page = Livewire::test(EditLease::class, ['record' => $lease->getKey()]);
        // The option and the sentence agree as saved…
        $page->assertSeeHtml($keyFor($asSaved))
            ->assertSee('Follows the rent — +10% every year');
        expect(followsOptionOnPage($page))->toBe('Follows the rent\'s clause (+10%)');

        // …and after the clause is retyped to an index, the picker's Livewire key has moved — so
        // the browser re-mounts it and re-reads its label — and both columns say "the index".
        $page->fillForm(['escalation_type' => 'cpi', 'escalation_interval_months' => 3])
            ->assertDontSeeHtml($keyFor($asSaved))
            ->assertSeeHtml($keyFor(['escalation_type' => 'cpi', 'escalation_rate' => '10.00', 'escalation_floor_rate' => null, 'escalation_ceiling_rate' => null, 'escalation_interval_months' => 3]))
            ->assertSee('Follows the rent — the index, collared, every 3 months');
        expect(followsOptionOnPage($page))->toBe('Follows the rent\'s clause (the index)');

        // A clause of NONE reads as one — never as "steps by an amount".
        $page->fillForm(['escalation_type' => 'none'])
            ->assertSee('Follows the rent — which does not step, so this charge stands still')
            ->assertDontSee('steps by an amount');
        expect(followsOptionOnPage($page))->toBe('Follows the rent\'s clause (the rent does not step)');
    });
});

it('re-renders the table when the interval or the collar is typed — the fields the sentences read are live', function () {
    asTenant($this->asset, function () {
        $lease = LeaseLadder::testersLease($this->asset);
        $components = collect(Livewire::test(EditLease::class, ['record' => $lease->getKey()])->instance()->form->getFlatFields(withHidden: true));

        // `fillForm()` re-renders whatever the field says, so a missing `live()` is invisible to
        // every driven case above; the liveness is pinned directly.
        foreach (['escalation_interval_months', 'escalation_floor_rate', 'escalation_ceiling_rate'] as $field) {
            expect($components[$field]->isLive())->toBeTrue($field.' must be live — the "Which charges step" table reads it');
        }
    });
});

it('prices the rate box per STEP, in the clause cadence', function () {
    asTenant($this->asset, function () {
        $lease = LeaseLadder::testersLease($this->asset);
        $lease->forceFill(['escalation_interval_months' => 3])->save();
        app(\App\Services\ChargeScheduleService::class)->setEscalation($lease, 'service_charge', ChargeEscalation::PERCENT, 4);

        Livewire::test(EditLease::class, ['record' => $lease->getKey()])
            ->assertSee('% every 3 months')
            ->assertDontSee('% / yr');
    });
});
