<?php

/*
|--------------------------------------------------------------------------
| A second door onto a column carries the same bound as the first
|--------------------------------------------------------------------------
| Found on 2026-09-05 while performing a soak act: a value longer than its column came back as a
| QueryException — a 500 page — rather than as a validation message under the field.
|
| The pattern is this codebase's most repeated one, in a smaller key: a column is written by more
| than one screen, and only the first one states its rule. `TenantForm` bounds a tenant's name,
| phone and email at 100/20/150; the lease form's inline **create tenant** — the "+" a leasing agent
| uses without leaving the lease — bounded NOTHING, so a pasted 300-character trade name reached
| `tenants.name` (varchar 255) and the database refused it. `PostDatedChequeForm` bounds `bank_name`
| at its column's 200; the bulk **lodge a series** modal beside it did not. And the vendor portal's
| profile form bounded the email at Filament's default 255 while `vendor_contacts.email` is 200 —
| the opposite error, a form that validates a value the database then refuses.
|
| The adversarial review then found the same modal's OTHER field is the worse one: `first_cheque_number`
| writes `cheque_number` — varchar(100) and NOT NULL — with no bound at all, three lines below the
| `bank_name` this change had just capped. And a bound there is necessary and not sufficient, because
| the series GENERATOR grows the number: a 98-character non-numeric first number becomes 101 on the
| tenth cheque, failing mid-transaction as an unhandled QueryException; and a numeric tail longer
| than a 64-bit integer overflows the `(int)` cast, so two cheques mint the SAME number and the
| series dies on the unique index with a message describing nothing the operator did. Both are
| refused up front now, in the operator's own words.
|
| Low severity for the tenant fields and not for the cheque one: the operator loses what they typed,
| and an error page where a field message belongs is the difference between "I typed too much" and
| "this system is broken".
|
| The test drives the ACTION, because that is where a bound either fires or does not. It is paired
| with a control that must SUCCEED, or a modal that refused everything would satisfy it.
*/

use App\Filament\Admin\Resources\Leases\Pages\CreateLease;
use App\Filament\Admin\Resources\PostDatedCheques\Pages\ListPostDatedCheques;
use App\Filament\Admin\Resources\Tenants\Pages\EditTenant;
use App\Models\PostDatedCheque;
use App\Models\Tenant;
use App\Models\Unit;
use App\Services\PostDatedChequeService;
use Database\Seeders\RolesPermissionsSeeder;
use Filament\Facades\Filament;
use Filament\Schemas\Schema;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed(RolesPermissionsSeeder::class);
    ensureAllPropertiesAsset();
    $this->asset = makeAsset(['code' => 'DB']);

    // Order matters: Filament's TenantSet event needs an authenticated user.
    $this->actingAs(makeUser('super_admin', [$this->asset->id]));
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    Filament::setTenant($this->asset);

    $this->tenant = Tenant::create([
        'name' => 'Bound Test Retail',
        'email' => 'bound@doors.test',
        'type' => 'company',
        'status' => 'active',
    ]);
});

afterEach(fn () => Filament::setTenant(null, isQuiet: true));

it('refuses an over-long bank name on the lodge-a-series modal instead of letting the database do it', function () {
    $tooLong = str_repeat('B', 260);   // the column is varchar(200)

    Livewire::test(ListPostDatedCheques::class)
        ->assertOk()
        ->callAction('lodge_series', data: [
            'asset_id' => $this->asset->id,
            'tenant_id' => $this->tenant->id,
            'bank_name' => $tooLong,
            'first_cheque_number' => '900100',
            'amount' => 25000,
            'count' => 3,
            'interval_months' => 1,
            'first_cheque_date' => now()->addMonth()->startOfMonth()->toDateString(),
            'received_date' => now()->toDateString(),
        ])
        ->assertHasActionErrors(['bank_name']);

    expect(PostDatedCheque::count())->toBe(0);
});

it('still lodges a series with an ordinary bank name — the control', function () {
    Livewire::test(ListPostDatedCheques::class)
        ->callAction('lodge_series', data: [
            'asset_id' => $this->asset->id,
            'tenant_id' => $this->tenant->id,
            'bank_name' => 'Commercial International Bank',
            'first_cheque_number' => '900100',
            'amount' => 25000,
            'count' => 3,
            'interval_months' => 1,
            'first_cheque_date' => now()->addMonth()->startOfMonth()->toDateString(),
            'received_date' => now()->toDateString(),
        ])
        ->assertHasNoActionErrors();

    expect(PostDatedCheque::count())->toBe(3);
});

it('refuses a cheque number that cannot carry the whole series, before writing any of it', function () {
    // 98 characters is INSIDE the field's 100 bound; the tenth cheque of the series is not.
    $service = app(PostDatedChequeService::class);

    $lodge = fn (string $first, int $count = 10) => $service->lodgeSeries([
        'asset_id' => $this->asset->id,
        'tenant_id' => $this->tenant->id,
        'bank_name' => 'CIB',
        'first_cheque_number' => $first,
        'amount' => 25000,
        'count' => $count,
        'interval_months' => 1,
        'first_cheque_date' => now()->addMonth()->startOfMonth()->toDateString(),
        'received_date' => now()->toDateString(),
    ]);

    // No numeric tail → the generator appends "-N", which is what overflows the column.
    expect(fn () => $lodge(str_repeat('A', 98)))->toThrow(DomainException::class);

    // A run of digits longer than a 64-bit integer cannot be counted up from at all.
    expect(fn () => $lodge('9'.str_repeat('0', 20)))->toThrow(DomainException::class);

    // Past the column outright.
    expect(fn () => $lodge(str_repeat('C', 120)))->toThrow(DomainException::class);

    // Nothing was written on any of the three — the refusal is before the transaction.
    expect(PostDatedCheque::count())->toBe(0);

    // The control: a real cheque book number mints the whole series.
    expect($lodge('900100', 12))->toHaveCount(12);
    expect(PostDatedCheque::max('cheque_number'))->toBe('900111');
});

it('lets an imported tenant be saved from its own edit page', function () {
    // The lockout the one source fixed: `TenantImporter` accepted these three values, the form
    // refused them, and the operator met a length message on fields they never touched — on a
    // record the system itself had created.
    $imported = Tenant::create([
        'name' => str_repeat('N', 150),                   // the form used to cap at 100
        'email' => str_repeat('e', 100).'@example.test',  // …at 150
        'phone' => '+20 2 2735 1234 / 2735 5678',         // …at 20 (24 characters)
        'type' => 'company',
        'status' => 'active',
        // A company tenant's address parts are required by the form, so the fixture states them —
        // otherwise the save fails for a reason that has nothing to do with field widths.
        'address_governorate' => 'Cairo',
        'address_city' => 'New Cairo',
        'address_street' => 'Ring Road',
        'address_building_number' => '20',
    ]);

    Livewire::test(EditTenant::class, ['record' => $imported->getKey()])
        ->call('save')
        ->assertHasNoFormErrors();
});

it('bounds the lease form\'s inline create-tenant fields exactly as the tenant form does', function () {
    // The bound lives on the Select's create-option form, which only exists once the field is built
    // inside a mounted page — the reason this is asserted through the page rather than by
    // constructing the component (a detached component throws on `$container`).
    Unit::create([
        'asset_id' => $this->asset->id,
        'code' => 'A-01',
        'area_sqm' => 100,
        'status' => 'vacant',
    ]);

    $page = Livewire::test(CreateLease::class);

    // A flat walk includes LAYOUT components (Tabs, Section…), which have no name — hence the
    // method_exists guard rather than a bare ->getName().
    $picker = collect($page->instance()->getSchema('form')->getFlatComponents(withHidden: true))
        ->first(fn ($component) => method_exists($component, 'getName') && $component->getName() === 'tenant_id');

    expect($picker)->not->toBeNull('The lease form no longer has a tenant picker to create through.');

    $caps = collect($picker->getCreateOptionActionForm(Schema::make($page->instance())) ?? [])
        ->filter(fn ($c) => method_exists($c, 'getMaxLength') && method_exists($c, 'getName'))
        ->mapWithKeys(fn ($c) => [$c->getName() => $c->getMaxLength()]);

    // AGREEMENT, read from `Tenant::FIELD_MAX` — not three literals. Asserting the integers would
    // have gone on passing while somebody changed the register's own form and the doors diverged
    // again, which is the whole defect this test is named after.
    expect($caps->all())->toBe([
        'name' => Tenant::FIELD_MAX['name'],
        'phone' => Tenant::FIELD_MAX['phone'],
        'email' => Tenant::FIELD_MAX['email'],
    ]);
});
