<?php

use App\Models\OwnerRequest;
use App\Notifications\OwnerRequestNotification;
use App\Services\OwnerRequestService;
use Database\Seeders\RolesPermissionsSeeder;
use Illuminate\Support\Facades\Notification;

it('creates an owner request with a reference and notifies the operator team', function () {
    Notification::fake();
    $this->seed(RolesPermissionsSeeder::class);
    $owner = makeUser('owner');
    $manager = makeUser('manager');

    $req = app(OwnerRequestService::class)->create([
        'subject' => 'Roof inspection',
        'body' => 'Please inspect the roof before winter.',
        'recipient' => 'operator',
    ], $owner);

    expect($req->reference)->toStartWith('OR-')
        ->and($req->status)->toBe('open')
        ->and($req->created_by_user_id)->toBe($owner->id);

    Notification::assertSentTo($manager, OwnerRequestNotification::class);
});

it('routes an owner-to-owner request to the assigned owner', function () {
    Notification::fake();
    $owner = makeUser('owner');
    $other = makeUser('owner');

    app(OwnerRequestService::class)->create([
        'subject' => 'Coordinate marketing spend',
        'body' => 'Let us align on the Q3 campaign.',
        'recipient' => 'owner',
        'assigned_to_user_id' => $other->id,
    ], $owner);

    Notification::assertSentTo($other, OwnerRequestNotification::class);
});

it('transitions status, stamps resolved_at, and notifies the owner', function () {
    Notification::fake();
    $owner = makeUser('owner');

    $req = OwnerRequest::create([
        'reference' => 'OR-2026-0001',
        'created_by_user_id' => $owner->id,
        'recipient' => 'operator',
        'subject' => 'x',
        'body' => 'y',
        'status' => 'open',
    ]);

    app(OwnerRequestService::class)->transition($req, 'resolved', ['resolution_notes' => 'Done.']);

    expect($req->refresh()->status)->toBe('resolved')
        ->and($req->resolved_at)->not->toBeNull();

    Notification::assertSentTo($owner, OwnerRequestNotification::class);
});

it('marks closed and cancelled requests as terminal', function () {
    expect((new OwnerRequest(['status' => 'closed']))->isTerminal())->toBeTrue()
        ->and((new OwnerRequest(['status' => 'cancelled']))->isTerminal())->toBeTrue()
        ->and((new OwnerRequest(['status' => 'open']))->isTerminal())->toBeFalse();
});
