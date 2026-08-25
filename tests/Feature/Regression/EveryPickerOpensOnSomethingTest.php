<?php

/*
|--------------------------------------------------------------------------
| A picker opens on what it CAN show (2026-08-25)
|--------------------------------------------------------------------------
| Asked for after the credit-note apply modal opened empty over a tenant with one invoice: make the
| pickers show what can be shown rather than nothing at all.
|
| ~85 of the panel's 111 record pickers handed Filament a static empty array and waited to be typed
| into. **An empty dropdown reads as "no such record", not as "type to search"** — which is why this
| was reported as missing data rather than as a bug.
|
| `OptionDisplay::PRELOAD` answered "is this MODEL small", which is the wrong question: `Invoice`
| holds thousands portfolio-wide and exactly one on that modal. Per call site was no better — four
| invoice pickers narrowed to one tenant and only one had remembered `->preload()`.
|
| Every picker now opens on the first `AUTO_BROWSE` rows of its own scoped, narrowed query. The two
| properties that matter are opposites and both are tested here: it must SHOW something, and it must
| not REACH further than it did before. A test that only asserted the first would pass just as
| happily on a picker that offered the whole portfolio.
*/

use App\Models\Tenant;
use App\Support\Filament\EntitySelect;
use App\Support\Search\OptionDisplay;
use Filament\Facades\Filament;

beforeEach(function () {
    ensureAllPropertiesAsset();
    $this->asset = makeAsset();
    $this->other = makeAsset(['code' => 'OTH', 'name' => 'Other Mall']);

    // A picker's scope comes from the SELECTED property, so a test that never selects one is
    // measuring the unscoped case and would pass whatever the scope did.
    $this->actingAs(makeUser('manager', [$this->asset->id]));
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    Filament::setTenant($this->asset);
});

afterEach(fn () => Filament::setTenant(null, isQuiet: true));

/** The options a bare tenant picker offers, as Filament would build them. */
function pickerOptions(EntitySelect $select): array
{
    return $select->getOptions() ?? [];
}

it('opens showing records, without anyone typing', function () {
    $tenant = makeTenant(['name' => 'Cilantro']);
    makeLease(makeUnit($this->asset), $tenant, ['status' => 'active']);

    $options = pickerOptions(EntitySelect::make('tenant_id')->entity(Tenant::class));

    // The whole ask in one assertion: something is there before a search term exists.
    expect($options)->not->toBeEmpty()
        ->and(array_keys($options))->toContain($tenant->id);
});

it('does NOT show a record from another property', function () {
    $mine = makeTenant(['name' => 'Cilantro']);
    makeLease(makeUnit($this->asset), $mine, ['status' => 'active']);

    $theirs = makeTenant(['name' => 'Zara']);
    makeLease(makeUnit($this->other), $theirs, ['status' => 'active']);

    // Showing more must never mean reaching more. Paired with the test above deliberately: an
    // assertion that the list is non-empty passes on a list that offers the whole portfolio.
    $options = pickerOptions(EntitySelect::make('tenant_id')->entity(Tenant::class));

    expect(array_keys($options))->toContain($mine->id)
        ->and(array_keys($options))->not->toContain($theirs->id);
});

it('honours a call site that narrows the query', function () {
    $wanted = makeTenant(['name' => 'Cilantro']);
    $unwanted = makeTenant(['name' => 'Zara']);

    $options = pickerOptions(
        EntitySelect::make('tenant_id')
            ->entity(Tenant::class)
            ->modifyOptionsQuery(fn ($query) => $query->whereKey($wanted->id))
    );

    // The narrowing is the whole reason a picker can browse at all — the set it shows is the set
    // the call site asked for, not the table.
    expect(array_keys($options))->toBe([$wanted->id]);
});

it('stays bounded on a large set', function () {
    // 3,000 tenants must cost a read of 50 rows, not 3,000. The bound is what makes showing
    // something affordable everywhere; without it this change would be a preload of every table.
    for ($i = 0; $i < EntitySelect::AUTO_BROWSE + 15; $i++) {
        makeTenant(['name' => "Tenant {$i}"]);
    }

    $options = pickerOptions(EntitySelect::make('tenant_id')->entity(Tenant::class));

    expect(count($options))->toBe(EntitySelect::AUTO_BROWSE);
});

it('still finds a record beyond the first page, by search', function () {
    for ($i = 0; $i < EntitySelect::AUTO_BROWSE + 15; $i++) {
        makeTenant(['name' => "Filler {$i}"]);
    }
    $late = makeTenant(['name' => 'Zzz Cilantro Egypt']);

    // What you SEE and what you can FIND are different questions — the rule `->suggest()` already
    // states. A bounded opening list would be a regression if it also bounded the search.
    $found = OptionDisplay::search(Tenant::class, 'cilantro');

    expect(array_keys($found))->toContain($late->id);
});
