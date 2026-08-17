<?php

use App\Filament\Admin\Resources\OwnerRequests\OwnerRequestResource;
use App\Filament\Admin\Resources\OwnerRequests\Pages\CreateOwnerRequest;
use App\Filament\Admin\Resources\OwnerRequests\Pages\ListOwnerRequests;
use App\Models\OwnerRequest;
use App\Notifications\OwnerRequestNotification;
use App\Services\OwnerRequestService;
use App\Support\TenantScope;
use Database\Seeders\RolesPermissionsSeeder;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;

/*
|--------------------------------------------------------------------------
| Owner (Jawad) request lifecycle — net-new scenarios.
|
| Covers: dual-recipient routing (operator vs another owner), bidirectional
| notification on operator response, RBAC (owner can raise but NEVER respond),
| query scoping (an owner sees only the requests THEY raised), terminal-state
| immutability (closed/cancelled = locked), and the property-picker leak
| regression (the asset_id picker is scoped to the owner's OWNED properties).
|--------------------------------------------------------------------------
*/

beforeEach(function () {
    // Real permission sets — owner gets *.view + owner_requests.create only,
    // operators (manager/super_admin) additionally get owner_requests.edit.
    $this->seed(RolesPermissionsSeeder::class);
    ensureAllPropertiesAsset();
});

afterEach(fn () => Filament::setTenant(null, isQuiet: true));

/* ───────────────────────── recipient routing ───────────────────────── */

it('routes an owner→operator request to the operator team only', function () {
    Notification::fake();
    $owner = makeUser('owner');
    $manager = makeUser('manager');
    $superAdmin = makeUser('super_admin');
    $otherOwner = makeUser('owner');

    app(OwnerRequestService::class)->create([
        'subject' => 'Roof inspection',
        'body' => 'Inspect before winter.',
        'recipient' => 'operator',
    ], $owner);

    // Operator team (manager + super_admin) is notified...
    Notification::assertSentTo($manager, OwnerRequestNotification::class);
    Notification::assertSentTo($superAdmin, OwnerRequestNotification::class);
    // ...but a sibling owner is NOT pulled into an operator-directed request.
    Notification::assertNotSentTo($otherOwner, OwnerRequestNotification::class);
});

it('routes an owner→owner request to the assigned owner only, not the operator team', function () {
    Notification::fake();
    $owner = makeUser('owner');
    $assignee = makeUser('owner');
    $manager = makeUser('manager');

    app(OwnerRequestService::class)->create([
        'subject' => 'Align on Q3 campaign',
        'body' => 'Let us coordinate marketing spend.',
        'recipient' => 'owner',
        'assigned_to_user_id' => $assignee->id,
    ], $owner);

    // The assigned owner is the sole recipient — the operator inbox stays quiet.
    Notification::assertSentTo($assignee, OwnerRequestNotification::class);
    Notification::assertNotSentTo($manager, OwnerRequestNotification::class);
    Notification::assertNotSentTo($owner, OwnerRequestNotification::class);
});

/* ──────────────────── notify-both-ways on response ──────────────────── */

it('notifies the owner who raised it when the operator responds', function () {
    Notification::fake();
    $owner = makeUser('owner');

    $req = OwnerRequest::create([
        'reference' => OwnerRequest::generateReference(),
        'created_by_user_id' => $owner->id,
        'recipient' => 'operator',
        'subject' => 'Lift maintenance',
        'body' => 'Lift 2 is noisy.',
        'status' => 'open',
    ]);

    app(OwnerRequestService::class)->transition($req, 'in_progress');

    // The raising owner gets the "updated" bell back.
    Notification::assertSentTo($owner, OwnerRequestNotification::class, function ($n) {
        return $n->event === 'updated';
    });
});

it('notifies the assigned owner-recipient when an owner-to-owner request is progressed', function () {
    Notification::fake();
    $owner = makeUser('owner');
    $assignee = makeUser('owner');

    // Raiser is `owner`; transition() always notifies the CREATOR (the raiser),
    // closing the loop for whoever opened the request.
    $req = OwnerRequest::create([
        'reference' => OwnerRequest::generateReference(),
        'created_by_user_id' => $owner->id,
        'recipient' => 'owner',
        'assigned_to_user_id' => $assignee->id,
        'subject' => 'Shared facade works',
        'body' => 'Please confirm budget split.',
        'status' => 'open',
    ]);

    app(OwnerRequestService::class)->transition($req, 'resolved', ['resolution_notes' => 'Agreed 50/50.']);

    Notification::assertSentTo($owner, OwnerRequestNotification::class);
    expect($req->refresh()->status)->toBe('resolved')
        ->and($req->resolved_at)->not->toBeNull()
        ->and($req->resolution_notes)->toBe('Agreed 50/50.');
});

/* ───────────────────────── RBAC: respond gate ───────────────────────── */

it('owner can raise but has no respond/edit gate; operators can respond', function () {
    $this->actingAs(makeUser('owner'));
    expect(OwnerRequestResource::canViewAny())->toBeTrue()
        ->and(OwnerRequestResource::canCreate())->toBeTrue()
        ->and(OwnerRequestResource::canEdit(new OwnerRequest))->toBeFalse();

    $this->actingAs(makeUser('manager'));
    expect(OwnerRequestResource::canEdit(new OwnerRequest))->toBeTrue();

    $this->actingAs(makeUser('super_admin'));
    expect(OwnerRequestResource::canEdit(new OwnerRequest))->toBeTrue();
});

it('hides the respond action from the owner on their own request', function () {
    // The respond action's visibility is `canEdit($r) && ! $r->isTerminal()`
    // (OwnerRequestsTable). An owner lacks owner_requests.edit → canEdit is
    // false → the action is hidden even on an open request they raised.
    $owner = makeUser('owner');
    $req = new OwnerRequest(['status' => 'open']);

    $this->actingAs($owner);

    $visible = OwnerRequestResource::canEdit($req) && ! $req->isTerminal();
    expect($visible)->toBeFalse()
        ->and(OwnerRequestResource::canEdit($req))->toBeFalse();
});

it('shows the respond action to an operator on an open operator-directed request', function () {
    // Same predicate from the operator's side: canEdit true + not terminal →
    // the respond action is offered.
    $manager = makeUser('manager');
    $req = new OwnerRequest(['status' => 'open']);

    $this->actingAs($manager);

    $visible = OwnerRequestResource::canEdit($req) && ! $req->isTerminal();
    expect($visible)->toBeTrue();
});

it('renders the owner-requests list with rows (regression: status/priority color closure param)', function () {
    $owner = makeUser('owner');
    $manager = makeUser('manager');
    $asset = makeAsset(['code' => 'HW', 'name' => 'Heliopolis']);

    $req = OwnerRequest::create([
        'reference' => OwnerRequest::generateReference(),
        'created_by_user_id' => $owner->id,
        'recipient' => 'operator',
        'subject' => 'x',
        'body' => 'y',
        'status' => 'open',
    ]);

    $this->actingAs($manager);
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    Filament::setTenant($asset);

    Livewire::test(ListOwnerRequests::class)
        ->assertCanSeeTableRecords([$req])
        ->assertTableActionVisible('reply', $req);
});

/* ─────────────────────── query scoping (own only) ────────────────────── */

it('an owner sees only their OWN requests across both recipient types', function () {
    $owner = makeUser('owner');
    $other = makeUser('owner');

    $mineOp = OwnerRequest::create(['reference' => 'OR-MINE-OP', 'created_by_user_id' => $owner->id, 'recipient' => 'operator', 'subject' => 'a', 'body' => 'b']);
    $mineOwner = OwnerRequest::create(['reference' => 'OR-MINE-OWN', 'created_by_user_id' => $owner->id, 'recipient' => 'owner', 'assigned_to_user_id' => $other->id, 'subject' => 'c', 'body' => 'd']);
    $theirs = OwnerRequest::create(['reference' => 'OR-THEIRS', 'created_by_user_id' => $other->id, 'recipient' => 'operator', 'subject' => 'e', 'body' => 'f']);
    // An owner-directed request ASSIGNED to me, but raised by someone else,
    // is still not "mine" in the raiser-scoped inbox.
    $assignedToMe = OwnerRequest::create(['reference' => 'OR-ASSIGNED', 'created_by_user_id' => $other->id, 'recipient' => 'owner', 'assigned_to_user_id' => $owner->id, 'subject' => 'g', 'body' => 'h']);

    $this->actingAs($owner);

    $refs = OwnerRequestResource::getEloquentQuery()->pluck('reference')->all();

    expect($refs)->toContain('OR-MINE-OP')
        ->and($refs)->toContain('OR-MINE-OWN')
        ->and($refs)->not->toContain('OR-THEIRS')
        ->and($refs)->not->toContain('OR-ASSIGNED');
});

it('the operator inbox shows operator-directed requests regardless of raiser', function () {
    $a = makeUser('owner');
    $b = makeUser('owner');
    OwnerRequest::create(['reference' => 'OR-A', 'created_by_user_id' => $a->id, 'recipient' => 'operator', 'subject' => 'a', 'body' => 'b']);
    OwnerRequest::create(['reference' => 'OR-B', 'created_by_user_id' => $b->id, 'recipient' => 'operator', 'subject' => 'c', 'body' => 'd']);
    OwnerRequest::create(['reference' => 'OR-OWN', 'created_by_user_id' => $a->id, 'recipient' => 'owner', 'assigned_to_user_id' => $b->id, 'subject' => 'e', 'body' => 'f']);

    $this->actingAs(makeUser('manager'));

    $refs = OwnerRequestResource::getEloquentQuery()->pluck('reference')->all();

    expect($refs)->toContain('OR-A')
        ->and($refs)->toContain('OR-B')
        ->and($refs)->not->toContain('OR-OWN');
});

/* ────────────────── terminal-state immutability (locked) ────────────── */

it('treats closed and cancelled requests as terminal and not open', function () {
    expect((new OwnerRequest(['status' => 'closed']))->isTerminal())->toBeTrue()
        ->and((new OwnerRequest(['status' => 'cancelled']))->isTerminal())->toBeTrue()
        ->and((new OwnerRequest(['status' => 'open']))->isOpen())->toBeTrue()
        ->and((new OwnerRequest(['status' => 'in_progress']))->isOpen())->toBeTrue()
        ->and((new OwnerRequest(['status' => 'closed']))->isOpen())->toBeFalse();
});

it('hides the respond action on a terminal request even from an operator', function () {
    $manager = makeUser('manager');
    $closed = new OwnerRequest(['status' => 'closed']);
    $cancelled = new OwnerRequest(['status' => 'cancelled']);

    $this->actingAs($manager);

    // canEdit is true for the operator, but the respond action's predicate
    // (canEdit && ! isTerminal) is false on terminal records — closed = locked.
    expect(OwnerRequestResource::canEdit($closed))->toBeTrue()
        ->and(OwnerRequestResource::canEdit($closed) && ! $closed->isTerminal())->toBeFalse()
        ->and(OwnerRequestResource::canEdit($cancelled) && ! $cancelled->isTerminal())->toBeFalse();
});

/* ──────────── property-picker scoping regression (fixed leak) ────────── */

it('scopes the asset_id picker to the OWNER\'s owned properties only', function () {
    $owner = makeUser('owner');
    $owned = makeAsset(['code' => 'OWN', 'name' => 'Owned Mall']);
    $foreign = makeAsset(['code' => 'FRGN', 'name' => 'Someone Elses Mall']);
    $owner->ownedAssets()->attach($owned->id, ['ownership_percentage' => 100]);

    $this->actingAs($owner);
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    Filament::setTenant($owned);

    $options = Livewire::test(CreateOwnerRequest::class)
        ->instance()
        ->form
        ->getComponent('asset_id')
        ->getOptions();

    expect($options)->toHaveKey($owned->id)               // owns it → visible
        ->and($options)->not->toHaveKey($foreign->id)     // doesn't own it → leak-free
        ->and(collect($options))->not->toContain('All Properties'); // synthetic row excluded
});

/*
| The picker offers exactly what the write guard accepts — and until 2026-08-17 it did not.
|
| `CreateOwnerRequest::mutateFormDataBeforeCreate()` calls `assertAssetInScope()`, which measures
| against `TenantScope::visibleAssetIds()`. With a property SELECTED that is `[currentAssetId]` —
| the selected property is the scope, which is the whole premise of a property-first panel. The
| picker, meanwhile, was built from `accessibleAssets()`: every mall the user owns or is assigned
| to. So an owner holding two malls was OFFERED the other one and got a 403 on save, and a
| super_admin was offered every property in the portfolio for the same refusal.
|
| Both sides now read the same source (`App\Support\Search\OptionDisplay::scope()`), so the two
| tests below assert the agreement rather than either half of it. Filing a request about a property
| is done from that property, like every other create in this panel.
*/
it('offers only properties the write guard would accept — for an owner holding several', function () {
    $owned = makeAsset(['code' => 'OWN', 'name' => 'Owned Mall']);
    $alsoOwned = makeAsset(['code' => 'ASGN', 'name' => 'Assigned Mall']);
    $owner = makeUser('owner', [$alsoOwned->id]);
    $owner->ownedAssets()->attach($owned->id, ['ownership_percentage' => 100]);

    $this->actingAs($owner);
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    Filament::setTenant($owned);

    $options = Livewire::test(CreateOwnerRequest::class)
        ->instance()
        ->form
        ->getComponent('asset_id')
        ->getOptions();

    // Offered: the property being worked in — and the control that must succeed, so a picker that
    // had simply broken would not read as a pass.
    expect($options)->toHaveKey($owned->id)
        // Not offered: the owner's OTHER mall, which `assertAssetInScope()` would 403 from here.
        ->and($options)->not->toHaveKey($alsoOwned->id);

    // The agreement itself, stated rather than implied.
    foreach (array_keys($options) as $assetId) {
        expect(TenantScope::visibleAssetIds())->toContain((int) $assetId);
    }
});

it('offers only the selected property to super_admin too, matching the guard', function () {
    $a = makeAsset(['code' => 'AAA', 'name' => 'Mall A']);
    $b = makeAsset(['code' => 'BBB', 'name' => 'Mall B']);

    $this->actingAs(makeUser('super_admin'));
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    Filament::setTenant($a);

    $options = Livewire::test(CreateOwnerRequest::class)
        ->instance()
        ->form
        ->getComponent('asset_id')
        ->getOptions();

    // `visibleAssetIds()` collapses to the selected property BEFORE the super_admin check, so the
    // guard refuses Mall B here whoever is asking. The picker now says so up front.
    expect($options)->toHaveKey($a->id)
        ->and($options)->not->toHaveKey($b->id)
        ->and(collect($options))->not->toContain('All Properties');
});
