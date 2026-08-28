<?php

/*
|--------------------------------------------------------------------------
| A charge cannot start before the lease does (2026-08-28)
|--------------------------------------------------------------------------
| Found from the panel while adding a service charge to a live lease: the Add-charge modal defaulted
| its start date to the current month, on a lease commencing the month AFTER — and accepted it.
|
| No money was ever at risk, and that is exactly what made it worth guarding. `planInvoiceForLease()`
| already clamps the billable window to the commencement date, so a charge dated earlier bills
| nothing for those months — measured: a lease commencing 1 September with a charge from 1 August
| billed **0.00 in August** and 11,000 in September.
|
| So the form accepted a date it would silently ignore. The operator sets August, opens the August
| run, finds nothing, and goes looking for a fault in the billing — a value that looks stored and
| has no effect, which is this codebase's most repeated defect.
|
| The floor is COMMENCEMENT (possession), not rent commencement: a tenant fitting out before rent
| starts is still consuming security and power, so a service charge from the day they took the keys
| is a real charge.
|
| **No ceiling at expiry, deliberately.** A lease in holdover has an expiry date in the past on
| purpose and is still billing, so an upper bound would block adding a charge to exactly the leases
| that most often need one.
*/

use App\Filament\Admin\RelationManagers\ChargeScheduleRelationManager;
use App\Filament\Admin\Resources\Leases\Pages\EditLease;
use App\Models\Charge;
use Database\Seeders\RolesPermissionsSeeder;
use Filament\Facades\Filament;
use Livewire\Livewire;

beforeEach(function () {
    ensureAllPropertiesAsset();
    $this->seed(RolesPermissionsSeeder::class);

    $this->asset = makeAsset();
    $this->unit = makeUnit($this->asset, ['area_sqm' => 110]);
    $this->lease = makeLease($this->unit, makeTenant(), [
        'status' => 'active',
        'commencement_date' => '2026-09-01',
        'expiry_date' => '2029-08-31',
    ]);

    $this->actingAs(makeUser('super_admin', [$this->asset->id]));
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    Filament::setTenant($this->asset);
});

afterEach(fn () => Filament::setTenant(null, isQuiet: true));

/** The Add-charge action on the lease's schedule tab, as the operator meets it. */
function addChargeFrom($ctx, string $from)
{
    return Livewire::test(ChargeScheduleRelationManager::class, [
        'ownerRecord' => $ctx->lease,
        'pageClass' => EditLease::class,
    ])->callTableAction('addCharge', data: [
        'type' => 'service_charge',
        'amount' => 11000,
        'frequency' => 'monthly',
        'effective_from' => $from,
    ]);
}

it('refuses a start date before the lease commenced', function () {
    addChargeFrom($this, '2026-08-01')->assertHasTableActionErrors(['effective_from']);

    expect(Charge::where('lease_id', $this->lease->id)->where('type', 'service_charge')->count())->toBe(0);
});

it('accepts the commencement day itself', function () {
    // The boundary, and where an off-by-one would land: the first legitimate day is the day the
    // tenant takes possession, not the day after.
    addChargeFrom($this, '2026-09-01')->assertHasNoTableActionErrors();

    expect(Charge::where('lease_id', $this->lease->id)->where('type', 'service_charge')->count())->toBe(1);
});

it('accepts a date well inside the term', function () {
    // The control. A guard that refused everything would satisfy the refusal above and make the
    // screen useless — adding a charge part-way through a lease is the ordinary case.
    addChargeFrom($this, '2027-03-01')->assertHasNoTableActionErrors();

    expect(Charge::where('lease_id', $this->lease->id)->where('type', 'service_charge')->count())->toBe(1);
});

it('still allows a charge on a lease in HOLDOVER, whose expiry is deliberately in the past', function () {
    // Why there is no upper bound. A holdover lease has run past its expiry and is still billing;
    // bounding the field at expiry would block the leases most likely to need a new charge.
    // A lease that ran from 2025 and is now past its expiry — a backwards term is refused by the
    // model, and rightly: "a lease with a backwards term never bills again".
    $this->lease->update([
        'commencement_date' => '2025-01-01',
        'expiry_date' => '2026-03-31',
        'holdover_from' => '2026-04-01',
    ]);

    addChargeFrom($this, '2026-10-01')->assertHasNoTableActionErrors();

    expect(Charge::where('lease_id', $this->lease->id)->where('type', 'service_charge')->count())->toBe(1);
});
