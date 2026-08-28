<?php

/*
|--------------------------------------------------------------------------
| A field that means a month asks for a month (2026-08-28)
|--------------------------------------------------------------------------
| Reported from the panel while entering a rent index: the period field opened a calendar of DAYS
| and made the operator click the 14th of a field that means "August 2026". The value was then
| normalised to the 1st behind their back — so what they picked and what was stored differed,
| silently, on a field where the day had never been part of the answer.
|
| Filament's `DatePicker` has no month-only mode, and the panel already answered this correctly
| elsewhere: `BillingRunPreview` picks its period from a Select of months. `MonthPicker` is that
| idiom once, so a month field looks and behaves the same everywhere.
|
| Three fields are genuinely months — a rent-index reading, a payroll run, and a billing period
| (which proved it by forcing `format('Y-m-01')` on whatever day was clicked). Invoice, bank
| statement and sales-declaration periods are NOT: a part-month invoice really does start on the
| 16th, and turning those into month pickers would lose a real date.
|
| Driven through the real pages: a Filament component outside a mounted container throws the moment
| a closure is evaluated, so the first version of this file measured nothing at all.
*/

use App\Filament\Admin\Resources\RentIndices\Pages\CreateRentIndex;
use App\Models\RentIndex;
use App\Support\Filament\MonthPicker;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesPermissionsSeeder;
use Filament\Facades\Filament;
use Livewire\Livewire;

beforeEach(function () {
    ensureAllPropertiesAsset();
    $this->seed(RolesPermissionsSeeder::class);
    $this->asset = makeAsset();
    $this->actingAs(makeUser('super_admin', [$this->asset->id]));
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    Filament::setTenant($this->asset);
});

afterEach(fn () => Filament::setTenant(null, isQuiet: true));

/** The period field as the rent-index form builds it. */
function periodField()
{
    $page = Livewire::test(CreateRentIndex::class)->instance();

    return $page->getSchema('form')->getComponent('period');
}

it('is a month picker, not a date picker', function () {
    expect(periodField())->toBeInstanceOf(MonthPicker::class);
});

it('offers months, never a day', function () {
    $options = periodField()->getOptions();

    expect($options)->not->toBeEmpty();

    // Every key is the first of a month — there is no way to express the 14th.
    foreach (array_keys($options) as $value) {
        expect(CarbonImmutable::parse($value)->day)->toBe(1);
    }
});

it('opens on this month, newest first', function () {
    // A period is filled far more often for a recent month than an old one, so a list that opened
    // on 2016 would make the common case the longest scroll.
    $keys = array_keys(periodField()->getOptions());

    expect($keys[0])->toBe(CarbonImmutable::now()->addMonths(3)->startOfMonth()->toDateString())
        ->and(CarbonImmutable::parse($keys[0]))->toBeGreaterThan(CarbonImmutable::parse(end($keys)));
});

it('stores the first of the month', function () {
    Livewire::test(CreateRentIndex::class)
        ->fillForm([
            'code' => 'CPI-EG',
            'period' => CarbonImmutable::now()->startOfMonth()->toDateString(),
            'value' => 140,
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    expect(RentIndex::first()->period->day)->toBe(1);
});

it('suggests the codes already in use', function () {
    // The reported failure: CPI-EG typed once and EGY_CPI the next time, giving two series of one
    // reading each — and rent that then never escalates, because the review month's reading is
    // filed under a name the lease does not know.
    RentIndex::create(['code' => 'CPI-EG', 'period' => '2026-01-01', 'value' => 130]);

    expect(Livewire::test(CreateRentIndex::class)->instance()
        ->getSchema('form')->getComponent('code')->getDatalistOptions())->toContain('CPI-EG');
});
