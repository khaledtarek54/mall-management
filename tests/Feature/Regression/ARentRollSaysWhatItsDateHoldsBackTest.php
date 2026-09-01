<?php

/*
|--------------------------------------------------------------------------
| A rent roll says what its as-of date is holding back
|--------------------------------------------------------------------------
| Reported on 2026-09-01 as "I can't find C-16 in the rent roll" — after
| activating a lease commencing on the 16th. Nothing was wrong: a rent roll is
| a snapshot of what the mall is contracted to earn ON a day, so a lease that
| starts in fifteen days is correctly absent from today's.
|
| It reads as a broken report all the same, and that is the failure this
| codebase keeps meeting: correct behaviour that is indistinguishable from
| missing data gets reported as missing data, or worse, not reported at all.
| The operator searches the unit code, gets nothing, and there is no path from
| an empty search back to a date printed among four other figures.
|
| The page's `empty` state already explains the whole-table case and CANNOT
| reach this one — the table is full, thirty-four other leases are on it, and
| only the searched lease is missing.
|
| Not-yet-commenced only. A lease that has ENDED is intuitively absent from a
| rent roll, and counting those would put a permanent line on any mall with
| history — which is how a notice stops being read. The same rule the ledger
| reports' unallocated notice follows: silent when there is nothing to say.
*/

use App\Filament\Admin\Pages\RentRoll;
use App\Services\Reports\ReportService;
use App\Support\Filament\ReportSearch;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesPermissionsSeeder;
use Filament\Facades\Filament;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed(RolesPermissionsSeeder::class);
    ensureAllPropertiesAsset();
    $this->asset = makeAsset(['code' => 'RR']);
    $this->actingAs(makeUser('super_admin'));
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    Filament::setTenant($this->asset);

    // Live today, so the roll is never empty — the whole point is that the
    // report LOOKS fine while the lease somebody is hunting for is absent.
    makeLease(
        makeUnit($this->asset, ['code' => 'RR-01', 'area_sqm' => 100]),
        makeTenant(['name' => 'Already Trading']),
        ['status' => 'active', 'commencement_date' => '2026-01-01', 'expiry_date' => '2027-12-31'],
    );

    $this->future = makeLease(
        makeUnit($this->asset, ['code' => 'RR-02', 'area_sqm' => 70]),
        makeTenant(['name' => 'Starts Later']),
        ['status' => 'active', 'commencement_date' => '2026-09-16', 'expiry_date' => '2029-09-15'],
    );
});

it('counts only the leases the date holds back', function () {
    $service = app(ReportService::class);

    expect($service->rentRollNotYetCommenced(CarbonImmutable::parse('2026-09-01'), $this->asset->id))->toBe(1)
        // On the commencement day itself it is on the roll, so nothing is held back.
        ->and($service->rentRollNotYetCommenced(CarbonImmutable::parse('2026-09-16'), $this->asset->id))->toBe(0);
});

/*
| CONTROL — the lease is genuinely on the roll once the date reaches it.
|
| Without this the notice could be describing a lease the report can never
| show, which would be a worse bug wearing a helpful sentence.
*/
it('puts the lease on the roll from its commencement day', function () {
    $service = app(ReportService::class);

    $before = $service->rentRoll(CarbonImmutable::parse('2026-09-15'), $this->asset->id);
    $after = $service->rentRoll(CarbonImmutable::parse('2026-09-16'), $this->asset->id);

    expect($before->firstWhere('reference', $this->future->reference))->toBeNull()
        ->and($after->firstWhere('reference', $this->future->reference))->not->toBeNull();
});

/*
| `asOf` is read from the QUERY STRING in mount(), never from a Livewire
| parameter — passing it as one leaves the page on today's date and both
| assertions then describe the same render, which is how the first version of
| this test "passed" the positive case and failed the negative one.
*/
it('says so on the page when the date holds a lease back', function () {
    Livewire::withQueryParams(['asOf' => '2026-09-01'])
        ->test(RentRoll::class)
        ->assertSee('commences after this date');
});

it('says nothing once the date reaches the lease', function () {
    Livewire::withQueryParams(['asOf' => '2026-09-16'])
        ->test(RentRoll::class)
        ->assertDontSee('commences after this date');
});

it('counts leases in words that read properly at one', function () {
    Livewire::withQueryParams(['asOf' => '2026-09-01'])
        ->test(RentRoll::class)
        // "1 leases" is what the glued-on count produced, on the line an owner
        // reads first. Arabic needs the distinction more than English does.
        ->assertSee('1 lease ·')
        ->assertDontSee('1 leases');
});

it('is written in Arabic too', function () {
    $ar = trans_choice('admin.rent_roll.not_yet_commenced', 1, ['count' => 1], 'ar');

    expect($ar)->not->toBe('admin.rent_roll.not_yet_commenced')
        // Lang::has() would pass on an English sentence sitting in the Arabic
        // file, which is the realistic failure when a key is added in one pass.
        ->and(preg_match('/\p{Arabic}/u', $ar))->toBe(1)
        ->and($ar)->not->toContain('commences');
});

/*
|--------------------------------------------------------------------------
| …and you can find a shop on it without paging
|--------------------------------------------------------------------------
| The other half of the same report. C-16 was row 34 of 34 at a default page
| size of 25 — on page two, with the search box switched off. Filament offers
| the search state to a `->records()` closure and filters NOTHING itself, so
| `->searchable(false)` was honest: there was nothing to search with.
*/

it('finds a lease by unit code, brand or reference', function () {
    $rows = app(ReportService::class)->rentRoll(CarbonImmutable::parse('2026-09-16'), $this->asset->id);

    expect(ReportSearch::apply($rows, 'RR-02', ['unit', 'units', 'tenant', 'reference'])->pluck('unit')->all())
        ->toBe(['RR-02'])
        ->and(ReportSearch::apply($rows, 'Starts Later', ['unit', 'units', 'tenant', 'reference'])->pluck('unit')->all())
        ->toBe(['RR-02'])
        // Words AND, so a second word narrows rather than widens.
        ->and(ReportSearch::apply($rows, 'Starts RR-01', ['unit', 'units', 'tenant', 'reference'])->count())
        ->toBe(0)
        // A query that folds to nothing means "do not search", never "match all"
        // — and never "match none", which would empty the report on a stray keystroke.
        ->and(ReportSearch::apply($rows, '  ...  ', ['unit', 'units', 'tenant', 'reference'])->count())
        ->toBe($rows->count());
});

it('folds both sides, so an Arabic spelling still matches', function () {
    $rows = collect([
        ['unit' => 'K-01', 'units' => 'K-01', 'tenant' => 'شركه الغرير', 'reference' => 'LSE-1'],
    ]);

    // The two spellings of the same word. Folding only one side matches nothing,
    // which is the trap a raw-column search key is banned for elsewhere.
    expect(ReportSearch::apply($rows, 'شركة', ['unit', 'units', 'tenant', 'reference'])->count())->toBe(1);
});

/*
| Driven through the real page, not by asserting a flag: `->searchable()` is
| only half of it — Filament renders the box and filters nothing unless the
| `->records()` closure uses the search it is handed, so a flag assertion would
| pass on a box that does nothing.
*/
it('actually filters the page when you type in the box', function () {
    Livewire::withQueryParams(['asOf' => '2026-09-16'])
        ->test(RentRoll::class)
        ->assertSee('RR-01')
        ->searchTable('RR-02')
        ->assertSee('RR-02')
        ->assertDontSee('RR-01');
});
