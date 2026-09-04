<?php

/*
|--------------------------------------------------------------------------
| A revised index figure is an EDIT, and the form has to say so (SW-043)
|--------------------------------------------------------------------------
| `rent_indices` has carried `unique(code, period)` since it shipped and the form asked nothing
| about it, so entering a month that already had a reading came back as a raw 500. That is not an
| exotic mistake — a statistical agency revises a figure and the operator retypes the month, which
| the migration's own docblock says is precisely the case a revision must be handled as an EDIT:
| "a lease that escalated on the old figure must be able to show which figure it used and when it
| changed".
|
| Three teeth, because the naive version of this fix is broken in two ways that are invisible here:
|
|   1. the refusal itself;
|   2. the CODE is normalised. It is stored upper-cased by a dehydrator that has not run at
|      validation time, so a check keyed on the typed value compares `egy_cpi` against a stored
|      `EGY_CPI` — which matches nothing under SQLite's case-sensitive `=` on TEXT. Green here,
|      different on MySQL, i.e. the exact trap CLAUDE.md records for the two drivers;
|   3. the reading may still be EDITED. A duplicate check that does not exclude the row being
|      edited makes every existing reading unsaveable, which is a worse bug than the one it fixes.
*/

use App\Filament\Admin\Resources\RentIndices\Pages\CreateRentIndex;
use App\Filament\Admin\Resources\RentIndices\Pages\EditRentIndex;
use App\Models\RentIndex;
use Database\Seeders\RolesPermissionsSeeder;
use Filament\Facades\Filament;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed(RolesPermissionsSeeder::class);
    ensureAllPropertiesAsset();
    $this->asset = makeAsset();
    $this->actingAs(makeUser('leasing', [$this->asset->id]));
    Filament::setTenant($this->asset);

    // September's published figure, already on the register.
    $this->reading = RentIndex::create(['code' => 'EGY_CPI', 'period' => '2026-09-01', 'value' => 119.6]);
});

it('refuses a second reading for a month the index already has, and says to edit the first', function () {
    Livewire::test(CreateRentIndex::class)
        ->fillForm(['code' => 'EGY_CPI', 'period' => '2026-09-01', 'value' => 121.9])
        ->call('create')
        ->assertHasFormErrors(['period']);

    // The register is unchanged: no second row, and the reading that was there still reads 119.6.
    expect(RentIndex::count())->toBe(1)
        ->and((float) RentIndex::sole()->value)->toBe(119.6);
});

it('catches it however the code was typed, because the code is stored upper-cased', function () {
    // The tooth that is invisible without it: the dehydrator upper-cases on the way in, and a
    // check keyed on the raw state compares `egy_cpi` against the stored `EGY_CPI`.
    Livewire::test(CreateRentIndex::class)
        ->fillForm(['code' => '  egy_cpi  ', 'period' => '2026-09-01', 'value' => 121.9])
        ->call('create')
        ->assertHasFormErrors(['period']);

    expect(RentIndex::count())->toBe(1);
});

it('catches it when the month is typed as any day of that month', function () {
    // `period` means a MONTH and normalises to the 1st, so the check has to snap both sides —
    // `RentIndexScreenIsReachableTest` already pins that a mid-month value is accepted and stored
    // as the 1st, which is exactly the state a raw comparison would miss.
    Livewire::test(CreateRentIndex::class)
        ->fillForm(['code' => 'EGY_CPI', 'period' => '2026-09-23', 'value' => 121.9])
        ->call('create')
        ->assertHasFormErrors(['period']);

    expect(RentIndex::count())->toBe(1);
});

it('CONTROL: still records a month the index does not have yet', function () {
    // Without this the refusal above passes just as happily on a rule that refuses everything.
    Livewire::test(CreateRentIndex::class)
        ->fillForm(['code' => 'EGY_CPI', 'period' => '2026-10-01', 'value' => 121.9])
        ->call('create')
        ->assertHasNoFormErrors();

    expect(RentIndex::count())->toBe(2);
});

it('CONTROL: still records the same month under a DIFFERENT index', function () {
    // The key is (code, period), not period. Two agencies publish for the same month.
    Livewire::test(CreateRentIndex::class)
        ->fillForm(['code' => 'EGY_CPI_URBAN', 'period' => '2026-09-01', 'value' => 118.2])
        ->call('create')
        ->assertHasNoFormErrors();

    expect(RentIndex::count())->toBe(2);
});

it('CONTROL: the revision itself still saves — the reading is not refused by itself', function () {
    // The whole escape the refusal names. A duplicate check that does not exclude the record being
    // edited makes every existing reading unsaveable, which is worse than the bug.
    Livewire::test(EditRentIndex::class, ['record' => $this->reading->getKey()])
        ->fillForm(['value' => 120.4])
        ->call('save')
        ->assertHasNoFormErrors();

    expect((float) $this->reading->fresh()->value)->toBe(120.4)
        ->and(RentIndex::count())->toBe(1);
});
