<?php

/*
|--------------------------------------------------------------------------
| A one-off must not replace the charge it was meant to top up (2026-08-28)
|--------------------------------------------------------------------------
| Found by an operator following a correction through the panel. A service charge invoiced at 11,000
| should have been 14,000, so the 3,000 shortfall was added as a ONE-TIME charge of the same type —
| and October went from 14,000 to **3,000**. The month under-billed by 14,000, silently.
|
| `ChargeScheduleService::setAmount()` RESTATES: it closes the row in force and opens a new one.
| That is right for a rent change or an escalation step, and catastrophic for a one-off, because the
| schedule holds ONE row per type per month by design — `Charge`'s own overlap guard refuses two. So
| a one-time row of a live type cannot sit beside the recurring one; it can only take its place.
|
| Where a later row happens to exist the damage is one month. Where none does — the ordinary case —
| the recurring charge simply ENDS, for the rest of the term.
|
| A one-off top-up belongs under its own charge code, which is what Yardi does and what `other` is
| in the catalogue for: the tenant then reads a line that says what it is, rather than a service
| charge that silently changed size for one month.
*/

use App\Filament\Admin\RelationManagers\ChargeScheduleRelationManager;
use App\Filament\Admin\Resources\Leases\Pages\EditLease;
use App\Models\Charge;
use App\Services\ChargeScheduleService;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesPermissionsSeeder;
use Filament\Facades\Filament;
use Livewire\Livewire;

beforeEach(function () {
    ensureAllPropertiesAsset();
    $this->seed(RolesPermissionsSeeder::class);

    $this->asset = makeAsset();
    $this->lease = makeLease(makeUnit($this->asset, ['area_sqm' => 110]), makeTenant(), [
        'status' => 'active',
        'commencement_date' => '2026-09-01',
        'expiry_date' => '2029-08-31',
    ]);

    // The recurring charge the operator is trying to top up.
    app(ChargeScheduleService::class)->setAmount(
        $this->lease, 'service_charge', 14000, CarbonImmutable::parse('2026-09-01'),
    );

    $this->actingAs(makeUser('super_admin', [$this->asset->id]));
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    Filament::setTenant($this->asset);
});

afterEach(fn () => Filament::setTenant(null, isQuiet: true));

function addOneOff($ctx, string $type, float $amount, string $from, string $frequency = 'one_time')
{
    return Livewire::test(ChargeScheduleRelationManager::class, [
        'ownerRecord' => $ctx->lease,
        'pageClass' => EditLease::class,
    ])->callTableAction('addCharge', data: [
        'type' => $type,
        'amount' => $amount,
        'frequency' => $frequency,
        'effective_from' => $from,
    ]);
}

/** What the schedule says this type bills in the month containing `$on`. */
function billsOn($lease, string $type, string $on): float
{
    $row = app(ChargeScheduleService::class)->rowInForce($lease, $type, CarbonImmutable::parse($on));

    return $row ? round((float) $row->amount, 2) : 0.0;
}

it('refuses a one-off on a type that is already running', function () {
    // A DomainException is this app's refusal — it renders as a message, not an error page.
    expect(fn () => addOneOff($this, 'service_charge', 3000, '2026-10-01'))
        ->toThrow(DomainException::class);

    // The recurring charge is untouched — the whole point.
    expect(billsOn($this->lease, 'service_charge', '2026-10-01'))->toBe(14000.0)
        ->and(billsOn($this->lease, 'service_charge', '2027-05-01'))->toBe(14000.0);
});

it('does not silently eat the rest of the term', function () {
    // The worse case, and the ordinary one: with no later row, a replacement ends the charge for
    // good. Nothing of the sort is written.
    try {
        addOneOff($this, 'service_charge', 3000, '2026-10-01');
    } catch (DomainException) {
        // The refusal under test.
    }

    expect(Charge::where('lease_id', $this->lease->id)
        ->where('type', 'service_charge')
        ->where('frequency', 'one_time')
        ->count())->toBe(0);
});

it('allows the top-up under its OWN code', function () {
    // The remedy the refusal names. `other` is a distinct type, so it sits beside the service
    // charge instead of over it — and the tenant reads a line that says what it is.
    addOneOff($this, 'other', 3000, '2026-10-01')->assertHasNoTableActionErrors();

    expect(billsOn($this->lease, 'service_charge', '2026-10-01'))->toBe(14000.0)
        ->and(billsOn($this->lease, 'other', '2026-10-01'))->toBe(3000.0);
});

it('still allows a RESTATEMENT of the recurring charge', function () {
    // The control. The refusal is about `one_time` only — changing what the service charge bills
    // from a date is the ordinary act this screen exists for, and must keep working.
    addOneOff($this, 'service_charge', 17000, '2026-10-01', frequency: 'monthly')
        ->assertHasNoTableActionErrors();

    expect(billsOn($this->lease, 'service_charge', '2026-09-01'))->toBe(14000.0)
        ->and(billsOn($this->lease, 'service_charge', '2026-10-01'))->toBe(17000.0);
});

it('still allows a one-off on a type with nothing running', function () {
    // A genuine one-off — a fit-out fee, a key deposit — has no recurring row to displace.
    addOneOff($this, 'other', 5000, '2026-11-01')->assertHasNoTableActionErrors();

    expect(billsOn($this->lease, 'other', '2026-11-01'))->toBe(5000.0);
});
