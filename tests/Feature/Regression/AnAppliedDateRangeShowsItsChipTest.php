<?php

use App\Filament\Admin\Resources\Employees\Pages\ListEmployees;
use App\Models\Employee;
use Database\Seeders\RolesPermissionsSeeder;
use Filament\Facades\Filament;
use Livewire\Livewire;

/**
 * AN APPLIED DATE RANGE NARROWS THE LIST AND SAYS SO IN THE FILTER BAR.
 *
 * Nine registers carried a hand-written date-range filter with NO chip (2026-09-12): apply it and
 * the rows shrink with nothing in the bar to say why or to clear it — SW-025's defect on a
 * different filter. `App\Support\Filament\DateRangeFilter` had carried the chip since it was
 * extracted; these nine were written beside it. The employees register was one of the nine.
 *
 * Both halves asserted: the narrowing (the control — a filter matching nothing would satisfy any
 * chip assertion) and the chip.
 */
beforeEach(function () {
    $this->seed(RolesPermissionsSeeder::class);
    ensureAllPropertiesAsset();

    $this->asset = makeAsset();
    $this->actingAs(makeUser('super_admin', [$this->asset->id]));
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    Filament::setTenant($this->asset);
});

afterEach(fn () => Filament::setTenant(null, isQuiet: true));

it('narrows the employees register by hire date and names the range in the chip', function () {
    $january = Employee::create(['asset_id' => $this->asset->id, 'code' => 'E-JAN', 'name' => 'Mona Adel', 'hire_date' => '2026-01-15', 'base_salary' => 6000, 'payment_method' => 'bank']);
    $june = Employee::create(['asset_id' => $this->asset->id, 'code' => 'E-JUN', 'name' => 'Omar Said', 'hire_date' => '2026-06-15', 'base_salary' => 6000, 'payment_method' => 'bank']);

    $component = Livewire::test(ListEmployees::class)
        ->filterTable('hire_date', ['from' => '2026-05-01', 'until' => '2026-07-31']);

    // The control: the range really narrows.
    expect(tableRows($component)->pluck('code')->all())->toBe(['E-JUN']);

    $labels = collect($component->instance()->getTable()->getFilterIndicators())
        ->map(fn ($i) => (string) $i->getLabel())
        ->implode(' | ');

    // The chip names the range in the operator's date format — and exists at all.
    expect($labels)->toContain('01/05/2026')->toContain('31/07/2026');

    $component->assertOk();
});
