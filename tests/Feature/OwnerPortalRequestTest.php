<?php

use App\Filament\Owner\Resources\OwnerRequests\OwnerRequestResource;
use App\Filament\Owner\Resources\OwnerRequests\Pages\CreateOwnerRequest;
use App\Models\OwnerRequest;
use App\Services\OwnerRequestService;
use Database\Seeders\RolesPermissionsSeeder;
use Filament\Facades\Filament;
use Livewire\Livewire;

beforeEach(fn () => $this->seed(RolesPermissionsSeeder::class));

it('scopes the owner portal to the owner\'s own requests', function () {
    $owner = makeUser('owner');
    $other = makeUser('owner');
    $this->actingAs($owner);

    $mine = app(OwnerRequestService::class)->create(['subject' => 'A', 'body' => 'B', 'recipient' => 'operator'], $owner);
    app(OwnerRequestService::class)->create(['subject' => 'C', 'body' => 'D', 'recipient' => 'operator'], $other);

    $ids = OwnerRequestResource::getEloquentQuery()->pluck('id')->all();
    expect($ids)->toContain($mine->id)->and($ids)->toHaveCount(1);
});

it('owner portal allows create but not edit', function () {
    $this->actingAs(makeUser('owner'));

    expect(OwnerRequestResource::canCreate())->toBeTrue()
        ->and(OwnerRequestResource::canEdit(new OwnerRequest()))->toBeFalse();
});

it('renders the owner portal create-request page', function () {
    $this->actingAs(makeUser('owner'));
    Filament::setCurrentPanel(Filament::getPanel('owner'));

    Livewire::test(CreateOwnerRequest::class)->assertOk();
});
