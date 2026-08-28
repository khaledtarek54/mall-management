<?php

/*
|--------------------------------------------------------------------------
| Space moves through Change premises, not through the form (2026-08-28)
|--------------------------------------------------------------------------
| Reported from the panel. Adding a unit through the lease form's "additional units" field changed
| the area and left the rent behind: measured, a 110 m² lease at 4,800/m² went to 200 m² and kept
| billing 44,000 where 80,000 was due — silently, with the charge schedule and the billing forecast
| both still showing the old figure.
|
| `EditLease::afterSave()` calls `syncUnits()`, which attaches the units and nothing else.
|
| **Re-deriving there is not the fix, and that is the whole point.** Re-rating needs an EFFECTIVE
| DATE, and a form save has nowhere to put one — so it could only restate the rent from the start of
| the lease, rewriting months that have already been billed. `LeaseSpaceChangeService` takes that
| date, re-derives at it, and closes and reopens the charge row. Yardi treats a premises change as
| an amendment with a date for the same reason.
|
| Refused on the WRITE, not only on the field: a disabled input's value still arrives in the
| Livewire payload, which is this codebase's standing rule for every pinned field.
*/

use App\Filament\Admin\Resources\Leases\Pages\EditLease;
use App\Models\Lease;
use App\Models\Unit;
use App\Services\ChargeScheduleService;
use App\Services\LeaseSpaceChangeService;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesPermissionsSeeder;
use Filament\Facades\Filament;
use Livewire\Livewire;

beforeEach(function () {
    ensureAllPropertiesAsset();
    $this->seed(RolesPermissionsSeeder::class);

    $this->asset = makeAsset();
    $this->unit = makeUnit($this->asset, ['area_sqm' => 110]);
    $this->extra = makeUnit($this->asset, ['area_sqm' => 90, 'code' => 'A-03']);

    $this->lease = makeLease($this->unit, makeTenant(), [
        'status' => 'active',
        'commencement_date' => '2026-08-01',
        'expiry_date' => '2029-07-31',
        'base_rent_monthly' => 44000,
        'base_rent_rate_per_sqm_year' => 4800,
        'rent_pricing_basis' => 'rate',
    ]);
    $this->lease->units()->syncWithoutDetaching([$this->unit->id]);

    app(ChargeScheduleService::class)->setAmount(
        $this->lease, 'base_rent', 44000, CarbonImmutable::parse('2026-08-01'),
    );

    $this->actingAs(makeUser('super_admin', [$this->asset->id]));
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    Filament::setTenant($this->asset);
});

afterEach(fn () => Filament::setTenant(null, isQuiet: true));

/** Save the edit form with an extra unit attached — the reported path. */
function saveWithExtraUnit($ctx)
{
    return Livewire::test(EditLease::class, ['record' => $ctx->lease->getKey()])
        ->set('data.additional_unit_ids', [$ctx->extra->id])
        ->call('save');
}

it('refuses to move space from the form on a live lease', function () {
    expect(fn () => saveWithExtraUnit($this))->toThrow(DomainException::class);

    // And nothing was half-done: the unit is not attached and the rent is untouched.
    expect($this->lease->fresh()->units()->count())->toBe(1)
        ->and(round((float) $this->lease->fresh()->base_rent_monthly, 2))->toBe(44000.0);
});

it('accepts the same change through Change premises, and RE-RATES it', function () {
    // The remedy the refusal names, and the assertion that makes the refusal defensible: the
    // operator is not blocked, they are sent to the path that does the arithmetic.
    app(LeaseSpaceChangeService::class)->expand($this->lease, [
        'unit_ids' => [$this->extra->id],
        'effective_from' => '2026-09-01',
        'reason' => 'Tenant took the adjacent shop',
    ]);

    $lease = $this->lease->fresh();

    expect($lease->units()->count())->toBe(2)
        // 4,800 × 200 ÷ 12 — the whole reason a rate is held rather than an amount.
        ->and(round((float) $lease->base_rent_monthly, 2))->toBe(80000.0);
});

it('leaves a DRAFT lease freely editable', function () {
    // The control. A draft has billed nothing and has no history to rewrite, so the form is the
    // right place to correct data entry.
    $this->lease->update(['status' => 'draft']);

    saveWithExtraUnit($this);

    expect($this->lease->fresh()->units()->count())->toBe(2);
});

it('does not refuse a save that changes nothing about the units', function () {
    // The ordinary case: editing notes on a live lease must not be blocked by a guard about space.
    Livewire::test(EditLease::class, ['record' => $this->lease->getKey()])
        ->set('data.notes', 'Renegotiated the fit-out window')
        ->call('save')
        ->assertHasNoErrors();

    expect(Lease::whereKey($this->lease->id)->value('notes'))->toBe('Renegotiated the fit-out window')
        ->and($this->lease->fresh()->units()->count())->toBe(1);
});
