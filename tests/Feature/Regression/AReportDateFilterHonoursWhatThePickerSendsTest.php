<?php

/*
|--------------------------------------------------------------------------
| Changing "As of" changes the report
|--------------------------------------------------------------------------
| Reported on 2026-09-01 as "I change the As of and nothing happens" — on the
| rent roll first, then confirmed on AR ageing, which is what identified it as
| shared rather than one screen's problem.
|
| `ArAging::parseAsOf()` is the one parser behind all SIX date-filtered reports,
| and it read:
|
|     try { return CarbonImmutable::createFromFormat('Y-m-d', $value)->endOfDay(); }
|     catch (\Throwable) { return CarbonImmutable::now()->endOfDay(); }
|
| `createFromFormat('Y-m-d', …)` accepts a bare date and nothing else. Filament's
| date picker writes its state back through JS that uses THREE formats —
| `YYYY-MM-DD`, `YYYY-MM-DD HH:mm:ss` and `YYYY-MM-DDTHH:mm:ss` — so the moment
| it sent either of the last two, the catch swallowed the operator's date and
| every one of those reports answered about TODAY.
|
| Nothing looked wrong, which is why it lasted: the picker kept displaying the
| chosen date (it holds the real state), the Livewire request fired and returned
| 200, no error was logged, and the report simply reported a different day from
| the one on screen. The only visible tell was the subheading disagreeing with
| the input beside it.
|
| **Why the suite never caught it.** Every test — including the ones written
| hours earlier for this very page — set a clean `Y-m-d`, through
| `withQueryParams()` or `fillForm()`. So did typing `?asOf=2026-09-16` into the
| URL by hand, which is exactly why that worked while the picker did not. A test
| that only ever sends the one format the parser accepts cannot see a parser
| that accepts only one format.
*/

use App\Filament\Admin\Pages\ArAging;
use App\Filament\Admin\Pages\RentRoll;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesPermissionsSeeder;
use Filament\Facades\Filament;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed(RolesPermissionsSeeder::class);
    ensureAllPropertiesAsset();
    $this->asset = makeAsset(['code' => 'DF']);
    $this->actingAs(makeUser('super_admin'));
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    Filament::setTenant($this->asset);
});

afterEach(fn () => Filament::setTenant(null, isQuiet: true));

/*
| THE DEFECT — every format the picker's own JS can send.
|
| Listed explicitly rather than parameterised over "some datetime string",
| because the point is that these are the three the component actually emits.
*/
it('honours every format the date picker sends', function (string $sent) {
    expect(ArAging::parseAsOf($sent)->toDateString())->toBe('2026-09-16');
})->with([
    'bare date' => '2026-09-16',
    'with time' => '2026-09-16 00:00:00',
    'ISO' => '2026-09-16T00:00:00',
    // A time other than midnight must not roll the date forward or back: the
    // report is date-only, so the time is discarded rather than honoured.
    'midday' => '2026-09-16 12:30:00',
]);

/*
| CONTROL — the fallback still exists, and still means today.
|
| A report must render rather than 500 on a malformed query string. Without
| this, "be lenient" could be satisfied by removing the guard altogether.
*/
it('still falls back to today for something it cannot read', function () {
    CarbonImmutable::setTestNow('2026-09-01 10:00:00');

    expect(ArAging::parseAsOf('not a date')->toDateString())->toBe('2026-09-01')
        ->and(ArAging::parseAsOf('')->toDateString())->toBe('2026-09-01')
        ->and(ArAging::parseAsOf(null)->toDateString())->toBe('2026-09-01');

    CarbonImmutable::setTestNow();
});

/*
| …and the same thing driven through the real page, in the shape the browser
| sends. `withQueryParams()` and `fillForm()` both send a clean `Y-m-d`, which
| is precisely the one format that always worked — so neither could ever have
| caught this.
*/
it('moves the report when the picker writes a datetime back', function () {
    makeLease(
        makeUnit($this->asset, ['code' => 'DF-01', 'area_sqm' => 70]),
        makeTenant(['name' => 'Starts Later']),
        ['status' => 'active', 'commencement_date' => '2026-09-16', 'expiry_date' => '2029-09-15'],
    );

    Livewire::test(RentRoll::class)
        ->assertSee('as at 01/09/2026')
        // Exactly what the component posts — not the tidy string a test would pick.
        ->set('asOf', '2026-09-16 00:00:00')
        ->assertSee('as at 16/09/2026')
        ->assertSee('DF-01');
});
