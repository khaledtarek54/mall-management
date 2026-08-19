<?php

/*
|--------------------------------------------------------------------------
| Retiring a trade made every record carrying it uneditable (2026-08-20)
|--------------------------------------------------------------------------
| Found reviewing close-out step 1, not by the suite — 5,732 tests were green.
|
| `Trade` is `#[DeletableWhenUnused]`: a trade that has routed work cannot be deleted, and both the
| model and the screen guide tell the operator to **deactivate** it instead. That is the documented
| path, and taking it broke the module: `Trade::options()` returned active trades only, Filament
| validates a `Select` against its options with `Rule::in`, and so a work order carrying the retired
| trade failed validation on a field nobody had touched. An operator fixing a typo in a title got an
| error on the trade.
|
| The shape was already understood here — `Vendor::assignableOptions($keepId)` keeps a
| no-longer-dispatchable vendor in the list flagged `⚠` for exactly this reason — and simply was not
| applied to the trade itself.
|
| Each test below pairs the retired case with a control that must also pass, because a picker that
| offered EVERYTHING would satisfy the refusal-free assertions and be a different bug.
*/

use App\Filament\Admin\Resources\Equipment\Pages\EditEquipment;
use App\Filament\Admin\Resources\FacilityWorkOrders\Pages\EditFacilityWorkOrder;
use App\Filament\Admin\Resources\ServicePlans\Pages\EditServicePlan;
use App\Models\Equipment;
use App\Models\FacilityWorkOrder;
use App\Models\ServicePlan;
use App\Models\Trade;
use Database\Seeders\RolesPermissionsSeeder;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed(RolesPermissionsSeeder::class);
    ensureAllPropertiesAsset();
    $this->asset = makeAsset();
    $this->actingAs(makeUser('manager', [$this->asset->id]));
    $this->trade = Trade::where('code', 'hvac')->firstOrFail();
});

function retireTheTrade($ctx): void
{
    $ctx->trade->update(['is_active' => false]);
}

it('still edits a work order whose trade has been retired', function () {
    $wo = FacilityWorkOrder::create([
        'asset_id' => $this->asset->id, 'work_order_type' => 'ppm', 'title' => 'Chiller service',
        'trade_id' => $this->trade->id, 'status' => 'open', 'priority' => 'medium',
        'scheduled_for' => now()->toDateString(),
    ]);

    retireTheTrade($this);

    asTenant($this->asset, function () use ($wo) {
        Livewire::test(EditFacilityWorkOrder::class, ['record' => $wo->getRouteKey()])
            ->fillForm(['title' => 'Chiller service (revised)'])
            ->call('save')
            ->assertHasNoFormErrors();
    });

    expect($wo->fresh()->title)->toBe('Chiller service (revised)')
        // …and it KEPT its trade. Silently clearing the field would also make the form save.
        ->and($wo->fresh()->trade_id)->toBe($this->trade->id);
});

it('still edits a service plan whose trade has been retired', function () {
    $plan = ServicePlan::create([
        'asset_id' => $this->asset->id, 'title' => 'Monthly HVAC service', 'trade_id' => $this->trade->id,
        'frequency_unit' => 'months', 'frequency_value' => 1,
        'next_due_date' => now()->addMonth()->toDateString(), 'is_active' => true,
    ]);

    retireTheTrade($this);

    asTenant($this->asset, function () use ($plan) {
        Livewire::test(EditServicePlan::class, ['record' => $plan->getRouteKey()])
            ->fillForm(['title' => 'Monthly HVAC service (revised)'])
            ->call('save')
            ->assertHasNoFormErrors();
    });

    expect($plan->fresh()->trade_id)->toBe($this->trade->id);
});

it('still edits a machine whose trade has been retired', function () {
    $machine = Equipment::create([
        'asset_id' => $this->asset->id, 'code' => 'CH-01', 'name_en' => 'Chiller',
        'name_ar' => 'مبرد', 'trade_id' => $this->trade->id, 'is_active' => true,
    ]);

    retireTheTrade($this);

    asTenant($this->asset, function () use ($machine) {
        Livewire::test(EditEquipment::class, ['record' => $machine->getRouteKey()])
            ->fillForm(['name_en' => 'Chiller (north)'])
            ->call('save')
            ->assertHasNoFormErrors();
    });

    expect($machine->fresh()->trade_id)->toBe($this->trade->id);
});

/**
 * The control. A retired trade is kept for the record that already carries it — it must NOT come
 * back as a choice for everyone else, or "deactivate" would mean nothing.
 */
it('offers a retired trade only to the record that already carries it', function () {
    retireTheTrade($this);

    expect(Trade::options())->not->toHaveKey($this->trade->id)
        ->and(Trade::options($this->trade->id))->toHaveKey($this->trade->id)
        // …and flagged, so nobody re-picks it thinking it is current.
        ->and(Trade::options($this->trade->id)[$this->trade->id])->toContain('⚠');
});

/** A filter must offer it too: the rows still carry it, and hiding the value hides the rows. */
it('lets a filter reach records carrying a retired trade', function () {
    retireTheTrade($this);

    expect(Trade::options(activeOnly: false))->toHaveKey($this->trade->id);
});
