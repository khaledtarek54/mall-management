<?php

use App\Filament\Admin\Resources\OwnerRequests\OwnerRequestResource;
use App\Filament\Admin\Resources\OwnerRequests\Pages\ListOwnerRequests;
use App\Models\OwnerRequest;
use App\Services\OwnerRequestService;
use Database\Seeders\RolesPermissionsSeeder;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed(RolesPermissionsSeeder::class);
});

it('operator inbox shows operator-directed requests only', function () {
    $owner = makeUser('owner');
    OwnerRequest::create(['reference' => 'OR-OP', 'created_by_user_id' => $owner->id, 'recipient' => 'operator', 'subject' => 'a', 'body' => 'b']);
    OwnerRequest::create(['reference' => 'OR-OWN', 'created_by_user_id' => $owner->id, 'recipient' => 'owner', 'subject' => 'c', 'body' => 'd']);

    $this->actingAs(makeUser('super_admin'));

    $refs = OwnerRequestResource::getEloquentQuery()->pluck('reference')->all();
    expect($refs)->toContain('OR-OP')->and($refs)->not->toContain('OR-OWN');
});

it('owners see only the requests they raised', function () {
    $owner = makeUser('owner');
    $other = makeUser('owner');
    OwnerRequest::create(['reference' => 'OR-MINE', 'created_by_user_id' => $owner->id, 'recipient' => 'operator', 'subject' => 'a', 'body' => 'b']);
    OwnerRequest::create(['reference' => 'OR-THEIRS', 'created_by_user_id' => $other->id, 'recipient' => 'operator', 'subject' => 'c', 'body' => 'd']);

    $this->actingAs($owner);

    $refs = OwnerRequestResource::getEloquentQuery()->pluck('reference')->all();
    expect($refs)->toContain('OR-MINE')->and($refs)->not->toContain('OR-THEIRS');
});

it('owners can view + create but not respond; operators can respond', function () {
    $this->actingAs(makeUser('owner'));
    expect(OwnerRequestResource::canViewAny())->toBeTrue()
        ->and(OwnerRequestResource::canCreate())->toBeTrue()
        ->and(OwnerRequestResource::canEdit(new OwnerRequest()))->toBeFalse();

    $this->actingAs(makeUser('manager'));
    expect(OwnerRequestResource::canEdit(new OwnerRequest()))->toBeTrue();
});

it('lets an owner raise a request from the admin app', function () {
    Notification::fake();
    $owner = makeUser('owner');
    $this->actingAs($owner);

    $req = app(OwnerRequestService::class)->create(['subject' => 'Roof', 'body' => 'Inspect', 'recipient' => 'operator'], $owner);

    expect(OwnerRequestResource::getEloquentQuery()->pluck('id')->all())->toContain($req->id);
});

it('renders the admin owner-requests list', function () {
    ensureAllPropertiesAsset();
    $this->actingAs(makeUser('super_admin'));
    Filament::setTenant(makeAsset(['code' => 'HW']));

    Livewire::test(ListOwnerRequests::class)->assertOk();
});
