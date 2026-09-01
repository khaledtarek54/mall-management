<?php

/*
|--------------------------------------------------------------------------
| A "+" beside a picker must be able to create something
|--------------------------------------------------------------------------
| Found by clicking it (2026-09-01), on `/admin/AW/leases/create` — the first
| screen a leasing agent opens:
|
|   LogicException: Select field [data.tenant_id] must have a
|                   [createOptionUsing()] closure set.
|
| Filament's create-option action is the ONLY thing that reads
| `createOptionUsing`, and it throws when the closure is null. A relationship
| select gets one for free — `Select::relationship()` installs a closure that
| creates the related model (Select.php:959). A select with no relationship
| gets nothing, so `createOptionForm()` on its own advertises a button that
| cannot do anything except produce an error page.
|
| The lease's tenant picker carried `->relationship('tenant', 'name', …)` until
| d9587a86 moved it to `EntitySelect`, which dropped the relationship and left
| the create form behind. The button has been a guaranteed 500 ever since —
| and it is invisible from the component: the form renders, the "+" renders,
| the picker works, and only pressing it fails. Nothing in the suite pressed it.
|
| Three of this codebase's own rules are why the sweep at the bottom is here
| rather than a one-line fix: enumerate a set like this by grep and never from
| the diff you just wrote; a gate that greps raw source fires on a SENTENCE, so
| this one strips comments (the fix's own comment names `createOptionUsing()`
| in prose); and a refusal test needs a control, so the first case proves a
| lease can still be created through the form at all — restoring the
| relationship changes how Filament dehydrates the field, and `leases.tenant_id`
| is NOT NULL.
*/

use App\Filament\Admin\Resources\Leases\Pages\CreateLease;
use App\Models\Lease;
use App\Models\Tenant;
use Database\Seeders\RolesPermissionsSeeder;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed(RolesPermissionsSeeder::class);
    ensureAllPropertiesAsset();
    $this->asset = makeAsset(['code' => 'QC']);

    $this->actingAs(makeUser('super_admin'));
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    Filament::setTenant($this->asset);
});

afterEach(fn () => Filament::setTenant(null, isQuiet: true));

/*
| CONTROL — the form still writes a lease.
|
| `Select::relationship()` re-declares `dehydrated()`, and the FK is NOT NULL,
| so "the button works now" is worth nothing if the ordinary path stopped
| saving. This must pass for the assertions below to mean anything.
*/
it('still creates a lease through the form', function () {
    $unit = makeUnit($this->asset, ['code' => 'QC-01']);
    $tenant = makeTenant(['name' => 'Control Tenant']);

    Livewire::test(CreateLease::class)
        ->fillForm([
            'unit_id' => $unit->id,
            'tenant_id' => $tenant->id,
            'status' => 'active',
            'commencement_date' => '2026-06-01',
            'expiry_date' => '2027-05-31',
            'term_months' => 12,
            'base_rent_monthly' => 5000,
            'service_charge_monthly' => 1000,
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    expect(Lease::where('tenant_id', $tenant->id)->first())
        ->not->toBeNull()
        ->and(Lease::where('tenant_id', $tenant->id)->first()->unit_id)->toBe($unit->id);
});

/*
| THE DEFECT — the exact null Filament throws on.
|
| Asserted on the BUILT component, not on the source: a call site can carry
| `createOptionForm()` and lose its relationship in a refactor without either
| line changing, which is precisely what happened.
*/
it('gives the tenant quick-create a closure that can actually create', function () {
    $component = Livewire::test(CreateLease::class)
        ->instance()->form->getComponent('tenant_id');

    expect($component->hasCreateOptionActionFormSchema())->toBeTrue(
        'the lease form offers a "+" beside Tenant — if that is removed, remove this test with it'
    );

    expect($component->getCreateOptionUsing())->not->toBeNull(
        'pressing "+" throws LogicException without it'
    );
});

it('creates a real tenant when the quick-create runs', function () {
    $before = Tenant::count();

    Livewire::test(CreateLease::class)
        ->callAction(
            TestAction::make('createOption')->schemaComponent('tenant_id'),
            ['name' => 'Quick Created Retailer', 'phone' => '01000000000', 'email' => 'qc@example.test'],
        )
        ->assertHasNoActionErrors();

    expect(Tenant::count())->toBe($before + 1);

    $created = Tenant::where('name', 'Quick Created Retailer')->first();

    expect($created)->not->toBeNull()
        // The party code is allocated by the model, so a tenant born in a modal
        // is a real counterparty rather than a half-record.
        ->and($created->code)->not->toBeNull();
});

/*
| THE GATE — every other "+" in the panel, so the next one cannot ship dead.
|
| Comments are stripped, because the fix's own comment discusses
| `createOptionUsing()` and a raw grep would read that sentence as code.
*/
it('never offers a create-option form with no way to create', function () {
    $offenders = [];

    foreach (filamentSources() as $path) {
        $source = sourceWithoutComments($path);

        if (! str_contains($source, '->createOptionForm(')) {
            continue;
        }

        // The chain is windowed on BOTH sides of the call, because the two
        // working spellings sit on opposite sides of it: `->relationship()`
        // comes BEFORE (it installs the closure as a side effect), and an
        // explicit `->createOptionUsing()` comes after the form array. The
        // backward window stops at the picker's own `::make(`, so a correct
        // sibling in the same file cannot vouch for a broken one.
        $offset = 0;

        while (($at = strpos($source, '->createOptionForm(', $offset)) !== false) {
            $offset = $at + 1;

            $chainStart = strrpos(substr($source, 0, $at), '::make(');
            $before = $chainStart === false ? '' : substr($source, $chainStart, $at - $chainStart);
            $after = substr($source, $at, 600);

            if (! str_contains($before, '->relationship(')
                && ! str_contains($after, '->createOptionUsing(')) {
                $offenders[] = basename($path);
            }
        }
    }

    expect($offenders)->toBe([], implode(', ', $offenders)
        .' — a create-option form with neither ->createOptionUsing() nor ->relationship() '
        .'is a "+" button that throws LogicException when pressed.');
});
