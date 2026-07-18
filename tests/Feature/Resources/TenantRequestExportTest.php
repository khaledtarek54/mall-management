<?php

use App\Filament\Admin\Resources\MaintenanceRequests\Pages\ListMaintenanceRequests;
use Database\Seeders\RolesPermissionsSeeder;
use Filament\Actions\Exports\Models\Export;
use Filament\Actions\Testing\TestAction;
use Livewire\Livewire;

/**
 * FR-REQ-12 — the request/work-order queue is exportable, but export is an OVERSIGHT capability,
 * gated on `maintenance.view_all`. A technician who sees only their own work has no reason to
 * bulk-export the board; a coordinator / customer_service / manager who oversees it does. The
 * export itself runs through the resource query, so property + AssignmentScope scoping already
 * apply — nobody exports rows they cannot see.
 */
beforeEach(function () {
    $this->seed(RolesPermissionsSeeder::class);
    ensureAllPropertiesAsset();
    $this->asset = makeAsset(['code' => 'EXP']);
});

it('offers the export to an oversight role (coordinator)', function () {
    $this->actingAs(makeUser('coordinator', [$this->asset->id]));

    asTenant($this->asset, function () {
        Livewire::test(ListMaintenanceRequests::class)->assertTableActionVisible('export');
    });
});

it('offers the export to customer service (fields any call, oversees the board)', function () {
    $this->actingAs(makeUser('customer_service', [$this->asset->id]));

    asTenant($this->asset, function () {
        Livewire::test(ListMaintenanceRequests::class)->assertTableActionVisible('export');
    });
});

it('hides the export from a technician (own work only, no view_all)', function () {
    $this->actingAs(makeUser('technician', [$this->asset->id]));

    asTenant($this->asset, function () {
        Livewire::test(ListMaintenanceRequests::class)->assertTableActionHidden('export');
    });
});

it('a technician cannot dispatch the export by mounting it directly', function () {
    // assertTableActionHidden proves only the DISPLAY gate. Dispatch through the unified
    // mountAction (the visibility-bypassing path) and confirm the gate still holds — the action
    // does not mount (authorize() feeds isDisabled()) and no export is produced.
    $this->actingAs(makeUser('technician', [$this->asset->id]));

    asTenant($this->asset, function () {
        Livewire::test(ListMaintenanceRequests::class)
            ->mountAction(TestAction::make('export')->table())
            ->assertActionNotMounted('export');
    });

    expect(Export::count())->toBe(0);
});
