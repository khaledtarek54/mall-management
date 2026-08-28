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
| `MonthPicker` is Filament's own date picker with the day grid taken out. Its panel already carried
| a month select and a year input above that grid, so the header stays, the days go, and either
| control commits. Everything else is upstream's: the panel, the Alpine component, the keyboard
| handling, and `minDate`/`maxDate` — which matters, because the lease's billing period is bounded
| by `BillingWindow` and a first attempt built this on `Select` instead, where those methods do not
| exist. That shipped a 500 on /admin/leases/{id}/edit.
|
| Three fields are genuinely months — a rent-index reading, a payroll run, and the lease's billing
| period, which proved it by forcing `format('Y-m-01')` onto whatever day was clicked. Invoice, bank
| statement and sales-declaration periods are NOT: a part-month invoice really does start on the
| 16th, and converting those would lose a real date.
*/

use App\Filament\Admin\Resources\Leases\Pages\EditLease;
use App\Filament\Admin\Resources\Payrolls\Pages\CreatePayroll;
use App\Filament\Admin\Resources\RentIndices\Pages\CreateRentIndex;
use App\Models\RentIndex;
use App\Support\BillingWindow;
use App\Support\Filament\MonthPicker;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesPermissionsSeeder;
use Filament\Facades\Filament;
use Filament\Forms\Components\DatePicker;
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

it('renders a calendar with no days in it', function () {
    $view = resource_path('views/forms/components/month-picker.blade.php');

    expect($view)->toBeReadableFile();

    $blade = file_get_contents($view);

    // The day grid and its weekday labels are gone…
    expect($blade)->not->toContain('fi-fo-date-time-picker-calendar-day')
        ->and($blade)->not->toContain('daysInFocusedMonth')
        // …and the month/year header — the part that makes it still a PICKER — stays.
        ->and($blade)->toContain('fi-fo-date-time-picker-month-select')
        ->and($blade)->toContain('fi-fo-date-time-picker-year-input')
        // Either control commits, or the panel opens and nothing can be chosen at all.
        ->and(substr_count($blade, 'selectDate(1)'))->toBe(2);
});

it('is a DatePicker, so the date bounds still work', function () {
    // The first attempt built this on `Select`, which has no minDate() — and the lease page, whose
    // period is bounded by the billing window, 500'd. The type is the fix.
    $page = Livewire::test(CreateRentIndex::class)->instance();
    $field = $page->getSchema('form')->getComponent('period');

    expect($field)->toBeInstanceOf(MonthPicker::class)
        ->and($field)->toBeInstanceOf(DatePicker::class)
        ->and($field->getView())->toBe('forms.components.month-picker');
});

it('mounts every screen it now lives on', function () {
    // `ResourceFormSmokeTest` mounts CREATE pages, so an EDIT page's header ACTION is exactly the
    // gap it does not cover — and that is where the 500 was.
    $lease = makeLease(makeUnit($this->asset, ['area_sqm' => 110]), makeTenant(), [
        'status' => 'active',
        'commencement_date' => '2026-08-01',
        'expiry_date' => '2029-07-31',
    ]);

    Livewire::test(EditLease::class, ['record' => $lease->getKey()])
        ->assertOk()
        // An action's schema is built when it MOUNTS, which is where the fatal was.
        ->mountAction('generateInvoice')
        ->assertOk();

    Livewire::test(CreatePayroll::class)->assertOk();
    Livewire::test(CreateRentIndex::class)->assertOk();
});

it('keeps the billing window on the lease period', function () {
    $lease = makeLease(makeUnit($this->asset, ['area_sqm' => 110]), makeTenant(), [
        'status' => 'active',
        'commencement_date' => '2026-08-01',
        'expiry_date' => '2029-07-31',
    ]);

    $component = Livewire::test(EditLease::class, ['record' => $lease->getKey()])
        ->mountAction('generateInvoice');

    $page = $component->instance();
    $field = $page->getSchema($page->getMountedActionSchemaName())->getComponent('period');

    // One screen must not refuse to PREVIEW a month the other would happily BILL.
    expect(CarbonImmutable::parse($field->getMinDate()))->toEqual(BillingWindow::earliest())
        ->and(CarbonImmutable::parse($field->getMaxDate()))->toEqual(BillingWindow::latest());
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
