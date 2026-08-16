<?php

/*
|--------------------------------------------------------------------------
| The lease form says what it means (2026-08-16)
|--------------------------------------------------------------------------
| Four separate ways this screen could be filled in wrongly and report success.
|
|  1. **Double-booking.** The `unit_id` rule that refuses a second active lease had been moved onto
|     `unit_ownership_id`, where `$value` is an ownership id and `! $value` returned early on every
|     ordinary lease. The option query hides occupied units, so the guard LOOKED present — until
|     "show occupied units" widens exactly that query, at which point nothing was left behind it.
|     `CreateLease` does not run `LeaseCreationService`, so the unit row-lock never saw it either.
|
|  2. **Escalation fields that belong to another clause.** Visibility read "not fixed_amount", so a
|     lease declaring `none` still offered a rate box and a collar — inputs that would be filled in
|     and then read by nothing. And because Filament does not dehydrate a hidden field, switching an
|     existing lease to `none` LEFT the old rate in the column, invisible and still live if the type
|     were ever switched back.
|
|  3. **A clause added after signing never ran.** `next_escalation_date` was armed in `creating`
|     only, so recording an escalation on an existing lease left it null — and
|     `RentEscalationService`'s `whereNotNull` excluded that lease for the rest of its term. The same
|     dead feature the create-side fix was written for, one edit away.
|
|  4. **Percentage rent configured to charge nothing, or everything.** The rate was optional (toggle
|     on, rate blank → 0.00 overage every month, reading as configured on every screen), and a
|     natural breakpoint with no base rent silently becomes "a percentage of every pound of sales
|     from the first one".
|
| Plus the Yardi lock rule: what identifies a lease is chosen once, and what its issued invoices
| were derived from stops moving the moment one exists.
*/

use App\Filament\Admin\Resources\Leases\Pages\CreateLease;
use App\Filament\Admin\Resources\Leases\Pages\EditLease;
use App\Models\Lease;
use Database\Seeders\RolesPermissionsSeeder;
use Filament\Facades\Filament;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed(RolesPermissionsSeeder::class);
    ensureAllPropertiesAsset();
    $this->asset = makeAsset(['code' => 'HW']);
    $this->unit = makeUnit($this->asset, ['code' => 'HW-01', 'status' => 'vacant']);

    $this->actingAs(makeUser('super_admin'));
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    Filament::setTenant($this->asset);
});

afterEach(fn () => Filament::setTenant(null, isQuiet: true));

/** The valid remainder of the form, so each test states only the field it is about. */
function leaseFormBase(int $unitId, int $tenantId, array $overrides = []): array
{
    return array_merge([
        'unit_id' => $unitId,
        'tenant_id' => $tenantId,
        'status' => 'active',
        'commencement_date' => '2026-06-01',
        'expiry_date' => '2027-05-31',
        'term_months' => 12,
        'base_rent_monthly' => 5000,
        'service_charge_monthly' => 1000,
    ], $overrides);
}

// ── 1. Double-booking ──────────────────────────────────────────────────────────────────────────

it('refuses a second active lease even when the occupied units are deliberately shown', function () {
    makeLease($this->unit, makeTenant(), ['status' => 'active']);
    $other = makeTenant();

    Livewire::test(CreateLease::class)
        ->fillForm(leaseFormBase($this->unit->id, $other->id, [
            // The toggle that widens the picker past the occupied filter — the whole point.
            'show_occupied_units' => true,
        ]))
        ->call('create')
        ->assertHasFormErrors(['unit_id']);

    expect(Lease::where('unit_id', $this->unit->id)->where('status', 'active')->count())
        ->toBe(1, 'the unit must never carry two active leases');
});

it('still allows a DRAFT lease on an occupied unit — the refusal is not a blanket one', function () {
    makeLease($this->unit, makeTenant(), ['status' => 'active']);
    $incoming = makeTenant();

    Livewire::test(CreateLease::class)
        ->fillForm(leaseFormBase($this->unit->id, $incoming->id, [
            'show_occupied_units' => true,
            'status' => 'draft',
            'commencement_date' => '2028-01-01',
            'expiry_date' => '2028-12-31',
        ]))
        ->call('create')
        ->assertHasNoFormErrors();

    expect(Lease::where('unit_id', $this->unit->id)->count())->toBe(2);
});

// ── 2. Escalation: one clause on screen at a time ──────────────────────────────────────────────

it('shows no escalation terms at all when the lease states none', function () {
    Livewire::test(CreateLease::class)
        ->fillForm(['escalation_type' => 'none'])
        ->assertFormFieldIsHidden('escalation_rate')
        ->assertFormFieldIsHidden('escalation_amount')
        ->assertFormFieldIsHidden('escalation_floor_rate')
        ->assertFormFieldIsHidden('escalation_ceiling_rate');
});

it('shows the rate and the collar for a percentage clause, and no amount', function () {
    Livewire::test(CreateLease::class)
        ->fillForm(['escalation_type' => 'fixed_percent'])
        ->assertFormFieldIsVisible('escalation_rate')
        ->assertFormFieldIsVisible('escalation_floor_rate')
        ->assertFormFieldIsVisible('escalation_ceiling_rate')
        ->assertFormFieldIsHidden('escalation_amount');
});

it('shows only the amount for a fixed-amount clause — a percent collar cannot bound pounds', function () {
    Livewire::test(CreateLease::class)
        ->fillForm(['escalation_type' => 'fixed_amount'])
        ->assertFormFieldIsVisible('escalation_amount')
        ->assertFormFieldIsHidden('escalation_rate')
        ->assertFormFieldIsHidden('escalation_floor_rate')
        ->assertFormFieldIsHidden('escalation_ceiling_rate');
});

it('shows the rate and collar for CPI — the rate arms the anniversary and the collar clamps it', function () {
    Livewire::test(CreateLease::class)
        ->fillForm(['escalation_type' => 'cpi'])
        ->assertFormFieldIsVisible('escalation_rate')
        ->assertFormFieldIsVisible('escalation_floor_rate')
        ->assertFormFieldIsHidden('escalation_amount');
});

it('clears the escalation terms when a lease is switched to none, so nothing survives unseen', function () {
    $lease = makeLease($this->unit, makeTenant(), [
        'escalation_type' => 'fixed_percent',
        'escalation_rate' => 7,
        'escalation_floor_rate' => 3,
        'escalation_ceiling_rate' => 10,
    ]);

    expect($lease->fresh()->next_escalation_date)->not->toBeNull();

    $lease->update(['escalation_type' => 'none']);

    $lease = $lease->fresh();
    expect((float) $lease->escalation_rate)->toBe(0.0)
        ->and($lease->escalation_floor_rate)->toBeNull()
        ->and($lease->escalation_ceiling_rate)->toBeNull()
        ->and($lease->next_escalation_date)->toBeNull();
});

// ── 3. A clause recorded after signing still runs ──────────────────────────────────────────────

it('arms the anniversary when an escalation clause is added to an EXISTING lease', function () {
    $lease = makeLease($this->unit, makeTenant(), [
        'escalation_type' => 'none',
        'escalation_rate' => 0,
        'commencement_date' => '2026-01-01',
    ]);

    expect($lease->fresh()->next_escalation_date)->toBeNull();

    // The ordinary correction: the clause was in the contract, nobody recorded it at signing.
    $lease->update(['escalation_type' => 'fixed_percent', 'escalation_rate' => 7]);

    expect($lease->fresh()->next_escalation_date?->format('Y-m-d'))
        ->toBe('2027-01-01', 'the sweep filters on whereNotNull — a null here is a lease that never escalates');
});

it('treats a stated type with no figure as no clause at all', function () {
    $lease = makeLease($this->unit, makeTenant(), [
        'escalation_type' => 'fixed_percent',
        'escalation_rate' => 0,
    ]);

    expect($lease->fresh()->next_escalation_date)->toBeNull();
});

// ── 4. Percentage rent that can actually charge something ──────────────────────────────────────

it('refuses percentage rent with no rate', function () {
    Livewire::test(CreateLease::class)
        ->fillForm(leaseFormBase($this->unit->id, makeTenant()->id, [
            'has_percentage_rent' => true,
            'percentage_rent_calculation_type' => 'artificial',
            'percentage_rent_threshold' => 1000000,
            'percentage_rent_rate' => null,
        ]))
        ->call('create')
        ->assertHasFormErrors(['percentage_rent_rate']);
});

it('refuses a natural breakpoint on a lease with no base rent', function () {
    Livewire::test(CreateLease::class)
        ->fillForm(leaseFormBase($this->unit->id, makeTenant()->id, [
            'base_rent_monthly' => 0,
            'has_percentage_rent' => true,
            'percentage_rent_calculation_type' => 'natural_breakpoint',
            'percentage_rent_rate' => 8,
        ]))
        ->call('create')
        ->assertHasFormErrors(['percentage_rent_calculation_type']);
});

it('accepts a natural breakpoint once there is a base rent to subtract', function () {
    Livewire::test(CreateLease::class)
        ->fillForm(leaseFormBase($this->unit->id, makeTenant()->id, [
            'base_rent_monthly' => 5000,
            'has_percentage_rent' => true,
            'percentage_rent_calculation_type' => 'natural_breakpoint',
            'percentage_rent_rate' => 8,
        ]))
        ->call('create')
        ->assertHasNoFormErrors();
});

// ── 5. What identifies a lease is chosen once (Yardi) ──────────────────────────────────────────

it('locks the master unit and the tenant once the lease exists', function () {
    $lease = makeLease($this->unit, makeTenant());

    Livewire::test(EditLease::class, ['record' => $lease->getKey()])
        ->assertFormFieldIsDisabled('unit_id')
        ->assertFormFieldIsDisabled('tenant_id')
        // The premises themselves stay negotiable — expansion and contraction are ordinary.
        ->assertFormFieldIsEnabled('additional_unit_ids');
});

it('leaves the term dates editable until the lease has been invoiced, then locks them', function () {
    $lease = makeLease($this->unit, makeTenant(), ['rent_commencement_date' => '2026-04-01']);

    Livewire::test(EditLease::class, ['record' => $lease->getKey()])
        ->assertFormFieldIsEnabled('commencement_date')
        ->assertFormFieldIsEnabled('rent_commencement_date');

    makeInvoice($lease);

    Livewire::test(EditLease::class, ['record' => $lease->getKey()])
        ->assertFormFieldIsDisabled('commencement_date')
        ->assertFormFieldIsDisabled('rent_commencement_date')
        ->assertFormFieldIsDisabled('fit_out_scope');
});

it('does not offer terminated or renewed as statuses to type', function () {
    $lease = makeLease($this->unit, makeTenant());

    Livewire::test(EditLease::class, ['record' => $lease->getKey()])
        ->assertFormFieldExists('status', function ($field) {
            $offered = array_keys($field->getOptions());

            return ! in_array('terminated', $offered, true)
                && ! in_array('renewed', $offered, true)
                && in_array('active', $offered, true);
        });
});
