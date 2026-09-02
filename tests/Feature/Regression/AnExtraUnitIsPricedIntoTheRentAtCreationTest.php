<?php

/*
|--------------------------------------------------------------------------
| A LEASE THAT OPENS ON TWO SHOPS IS PRICED ON BOTH OF THEM
|--------------------------------------------------------------------------
| A rate-priced lease created on the standard New lease form with an
| ADDITIONAL unit kept billing the MASTER unit's area alone — every figure
| downstream of it wrong, and none of them wrong in a way anybody can see.
|
| `Lease::saving` derives `base_rent_monthly` from `deriveBaseRentFromRate()`,
| which reads the `lease_unit` pivot. On create that pivot is EMPTY — the
| observer writes the master row in `created`, and `CreateLease::afterCreate()`
| attached the additional units LAST of all — so the derivation fell through to
| its own master-unit fallback, and then the charge ladder, the marketing levy
| and the deposit were all built from that figure before the second shop was
| ever attached.
|
| Measured on Val Plaza: A-03 (90 m²) + A-04 (120 m²) at 1,000/m²/yr is
| 210 x 1,000 / 12 = 17,500 a month. The lease saved 7,500, the ladder read
| 7,500 -> 8,025 -> 8,586.75, the levy 375 and the deposit 22,500 — a lease
| under-billed by 10,000 a month for its whole three-year term.
|
| The form told the same lie: `additional_unit_ids` was `->live()` under a
| comment saying the rent re-derives "the moment the let area changes", and
| nothing was wired to `deriveRentInto()` — so the helper text under the rate
| updated to 210.00 m² while the rent field beside it still read 7,500.
*/

use App\Filament\Admin\Resources\Leases\Pages\CreateLease;
use App\Filament\Admin\Resources\Leases\Pages\EditLease;
use App\Models\Charge;
use App\Models\Lease;
use App\Services\RemeasureUnitService;
use Database\Seeders\RolesPermissionsSeeder;
use Filament\Facades\Filament;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed(RolesPermissionsSeeder::class);
    ensureAllPropertiesAsset();
    $this->asset = makeAsset(['code' => 'VP']);

    $this->actingAs(makeUser('super_admin'));
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    Filament::setTenant($this->asset);

    $this->master = makeUnit($this->asset, ['code' => 'A-03', 'area_sqm' => 90]);
    $this->extra = makeUnit($this->asset, ['code' => 'A-04', 'area_sqm' => 120]);
    $this->tenant = makeTenant();
});

afterEach(fn () => Filament::setTenant(null, isQuiet: true));

function createRatePricedLease(array $additional): Lease
{
    Livewire::test(CreateLease::class)
        ->fillForm([
            'unit_id' => test()->master->id,
            'additional_unit_ids' => $additional,
            'tenant_id' => test()->tenant->id,
            'status' => 'active',
            'commencement_date' => '2026-09-01',
            'expiry_date' => '2029-08-31',
            'term_months' => 36,
            'rent_pricing_basis' => Lease::RENT_RATE,
            'base_rent_rate_per_sqm_year' => 1000,
            'service_charge_monthly' => 1000,
            'security_deposit_months' => 3,
            'has_marketing_levy' => true,
            'escalation_rate' => 7,
            'escalation_type' => 'fixed_percent',
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    return Lease::latest('id')->firstOrFail();
}

it('prices the rent on every unit the lease opens on, not just the master', function () {
    $lease = createRatePricedLease([$this->extra->id]);

    // 210 m² x 1,000 / 12 — the whole let area, which is what a rate means.
    expect($lease->totalAreaSqm())->toBe(210.0)
        ->and((float) $lease->base_rent_monthly)->toBe(17500.0);
});

it('builds the charge ladder, the levy and the deposit from that rent', function () {
    $lease = createRatePricedLease([$this->extra->id]);

    $rent = $lease->charges()->where('type', 'base_rent')->orderBy('start_date')->get();

    expect((float) $rent->first()->amount)->toBe(17500.0)
        // The escalation ladder is projected for the whole term off the same figure.
        ->and($rent->pluck('amount')->map(fn ($a) => (float) $a)->all())
        ->toBe([17500.0, 18725.0, 20035.75]);

    // The marketing levy is 5% of base rent, so it moves with it.
    expect((float) $lease->charges()->where('type', 'marketing')->orderBy('start_date')->first()->amount)
        ->toBe(875.0);

    // Three months' rent, recomputed by Lease::saving from the corrected rent.
    expect((float) $lease->security_deposit)->toBe(52500.0);
});

it('leaves a single-unit rate-priced lease exactly as it was', function () {
    $lease = createRatePricedLease([]);

    expect((float) $lease->base_rent_monthly)->toBe(7500.0)
        ->and((float) $lease->charges()->where('type', 'base_rent')->orderBy('start_date')->first()->amount)->toBe(7500.0)
        ->and((float) $lease->security_deposit)->toBe(22500.0);
});

it('moves the rent on the FORM as the extra space is picked', function () {
    Livewire::test(CreateLease::class)
        ->fillForm([
            'unit_id' => $this->master->id,
            'rent_pricing_basis' => Lease::RENT_RATE,
            'base_rent_rate_per_sqm_year' => 1000,
            'security_deposit_months' => 3,
        ])
        // The master alone, before the expansion is chosen.
        ->assertFormSet(['base_rent_monthly' => 7500.0])
        ->fillForm(['additional_unit_ids' => [$this->extra->id]])
        // The operator must see the money move as they pick space — the array form,
        // because assertFormSet(fn () => …) ignores what its closure returns.
        ->assertFormSet(['base_rent_monthly' => 17500.0, 'security_deposit' => 52500.0]);
});

it('moves the rent on the FORM when the MASTER unit itself changes', function () {
    // The master picker had the same omission, and it is invisible in the test above: filling the
    // rate and the unit together lets the RATE field's own hook do the work. Only changing the
    // premises AFTER the rate is typed asks the picker's own question.
    Livewire::test(CreateLease::class)
        ->fillForm([
            'rent_pricing_basis' => Lease::RENT_RATE,
            'base_rent_rate_per_sqm_year' => 1000,
        ])
        ->fillForm(['unit_id' => $this->master->id])
        ->assertFormSet(['base_rent_monthly' => 7500.0])
        // 120 m² instead of 90 — the rent is a function of the space, so it follows.
        ->fillForm(['unit_id' => $this->extra->id])
        ->assertFormSet(['base_rent_monthly' => 10000.0]);
});

it('does not reprice a FLAT lease from the area it happens to hold', function () {
    Livewire::test(CreateLease::class)
        ->fillForm([
            'unit_id' => $this->master->id,
            'tenant_id' => $this->tenant->id,
            'status' => 'active',
            'commencement_date' => '2026-09-01',
            'expiry_date' => '2027-08-31',
            'term_months' => 12,
            'rent_pricing_basis' => Lease::RENT_FLAT,
            'base_rent_monthly' => 9000,
            'additional_unit_ids' => [$this->extra->id],
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $lease = Lease::latest('id')->firstOrFail();

    // A flat rent is a negotiated sum. The area it covers changes nothing about it.
    expect((float) $lease->base_rent_monthly)->toBe(9000.0)
        ->and((float) Charge::where('lease_id', $lease->id)->where('type', 'base_rent')->value('amount'))->toBe(9000.0);
});

it('never restates a LIVE lease from the space it holds — that act needs a date', function () {
    // The dangerous direction of the same fix. Re-deriving a lease that has BILLED rewrites months
    // already invoiced from a rent nobody agreed to for them; `LeaseSpaceChangeService` exists to
    // take that effective date. Every trigger on the form is disabled on Edit, and
    // `repriceFromPremises()` refuses on its own once an invoice exists — two layers, and this
    // asserts the outcome rather than either mechanism.
    $lease = createRatePricedLease([$this->extra->id]);

    expect((float) $lease->base_rent_monthly)->toBe(17500.0);

    // The space is remeasured after the lease is live — a wall moved, a survey corrected.
    // Through the service: `Unit::area_sqm` refuses a direct write for the same reason this test
    // exists — a measurement carries the date it takes effect from.
    app(RemeasureUnitService::class)->record($this->extra, 300, ['reason' => 'Survey']);

    Livewire::test(EditLease::class, ['record' => $lease->getKey()])
        ->fillForm(['notes' => 'Survey corrected the north wall.'])
        ->call('save')
        ->assertHasNoFormErrors();

    // 390 m² would imply 32,500. The rent does not move: the contract says 17,500 until somebody
    // records a premises change with a date.
    expect((float) $lease->refresh()->base_rent_monthly)->toBe(17500.0)
        ->and((float) Charge::where('lease_id', $lease->id)->where('type', 'base_rent')
            ->orderBy('start_date')->value('amount'))->toBe(17500.0);
});

it('refuses to reprice a lease that has already billed', function () {
    $lease = createRatePricedLease([]);

    // The premises grow after the fact — the shape `LeaseSpaceChangeService` owns.
    $lease->syncUnits([$this->master->id, $this->extra->id], $this->master->id);
    $lease->load('units');

    expect($lease->totalAreaSqm())->toBe(210.0)
        // Nothing billed yet: origination's own correction still applies.
        ->and($lease->repriceFromPremises())->toBeTrue()
        ->and((float) $lease->base_rent_monthly)->toBe(17500.0);

    // Now it has. The same call must refuse rather than restate the month that was invoiced.
    makeInvoice($lease, ['asset_id' => $this->asset->id]);

    app(RemeasureUnitService::class)->record($this->extra, 300, ['reason' => 'Survey']);
    $lease->load('units');

    expect($lease->repriceFromPremises())->toBeFalse()
        ->and((float) $lease->fresh()->base_rent_monthly)->toBe(17500.0);
});
