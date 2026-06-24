<?php

use App\Filament\Admin\Resources\OwnerRequests\OwnerRequestResource;
use App\Filament\Admin\Resources\OwnerRequests\Pages\ListOwnerRequests;
use App\Models\OwnerRequest;
use Database\Seeders\RolesPermissionsSeeder;
use Filament\Facades\Filament;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed(RolesPermissionsSeeder::class);
});

it('shows only operator-directed requests in the admin inbox', function () {
    $owner = makeUser('owner');
    OwnerRequest::create(['reference' => 'OR-A', 'created_by_user_id' => $owner->id, 'recipient' => 'operator', 'subject' => 'a', 'body' => 'b']);
    OwnerRequest::create(['reference' => 'OR-B', 'created_by_user_id' => $owner->id, 'recipient' => 'owner', 'subject' => 'c', 'body' => 'd']);

    $this->actingAs(makeUser('super_admin'));

    $refs = OwnerRequestResource::getEloquentQuery()->pluck('reference')->all();
    expect($refs)->toContain('OR-A')
        ->and($refs)->not->toContain('OR-B');
});

it('renders the admin owner-requests inbox', function () {
    ensureAllPropertiesAsset();
    $this->actingAs(makeUser('super_admin'));
    Filament::setTenant(makeAsset(['code' => 'HW']));

    Livewire::test(ListOwnerRequests::class)->assertOk();
});

it('gates owner requests on the owner_requests permission', function () {
    $this->actingAs(makeUser('viewer'));
    expect(OwnerRequestResource::canViewAny())->toBeTrue();

    // The owner role operates the Owner Portal, not the admin inbox.
    $this->actingAs(makeUser('owner'));
    expect(OwnerRequestResource::canViewAny())->toBeFalse();
});
