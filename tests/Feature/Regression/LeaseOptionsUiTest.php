<?php

use App\Filament\Admin\RelationManagers\LeaseOptionsRelationManager;
use App\Filament\Admin\Resources\Leases\Pages\EditLease;
use App\Models\LeaseOption;
use Database\Seeders\RolesPermissionsSeeder;
use Filament\Facades\Filament;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;

beforeEach(function () {
    $this->seed(RolesPermissionsSeeder::class);
    $this->asset = makeAsset();
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $this->actingAs(makeUser('super_admin', [$this->asset->id]));
    Filament::setTenant($this->asset);
});
afterEach(fn () => Filament::setTenant(null, isQuiet: true));

it('renders the options panel with the deadline front and centre', function () {
    $lease = makeLease(makeUnit($this->asset), null, ['status' => 'active', 'expiry_date' => '2030-12-31']);
    LeaseOption::create([
        'lease_id' => $lease->id, 'type' => 'renewal', 'status' => 'open',
        'earliest_notice_date' => '2030-01-01', 'latest_notice_date' => '2030-04-01',
        'rent_basis' => 'uplift_percent', 'uplift_percent' => 10,
    ]);

    Livewire::test(LeaseOptionsRelationManager::class, ['ownerRecord' => $lease, 'pageClass' => EditLease::class])
        ->assertOk()
        ->assertSee(__('admin.lease_options.types.renewal'))
        ->assertSee('01/04/2030');
});

it('refuses a read-only viewer resolving an option, and lets an authorised user do it', function () {
    $lease = makeLease(makeUnit($this->asset), null, ['status' => 'active']);
    $option = LeaseOption::create([
        'lease_id' => $lease->id, 'type' => 'renewal', 'status' => 'open',
        'latest_notice_date' => '2030-04-01',
    ]);

    // viewer holds leases.view but not leases.edit.
    $this->actingAs(makeUser('viewer', [$this->asset->id]));
    Filament::setTenant($this->asset);

    $action = Livewire::test(LeaseOptionsRelationManager::class, ['ownerRecord' => $lease, 'pageClass' => EditLease::class])
        ->instance()->getTable()->getAction('exercise');

    expect(fn () => $action->call(['record' => $option]))
        ->toThrow(HttpException::class);
    expect($option->fresh()->status)->toBe('open');

    // Control: without this the refusal would pass just as happily if call() never ran the closure.
    $this->actingAs(makeUser('leasing', [$this->asset->id]));
    Filament::setTenant($this->asset);

    Livewire::test(LeaseOptionsRelationManager::class, ['ownerRecord' => $lease, 'pageClass' => EditLease::class])
        ->instance()->getTable()->getAction('exercise')->call(['record' => $option]);

    expect($option->fresh()->status)->toBe('exercised');
});
