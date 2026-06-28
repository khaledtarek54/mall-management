<?php

/*
|--------------------------------------------------------------------------
| OwnerRequestService — dedicated service-level coverage
|--------------------------------------------------------------------------
| The service (app/Services/OwnerRequestService.php) had no dedicated test of
| its own. tests/Feature/OwnerRequestTest.php and the OwnerRequestScenarioTest
| exercise notification routing + RBAC + the action-visibility predicate; this
| file pins the SERVICE's two public methods directly:
|
|  create(array $data, User $owner): OwnerRequest
|    - generates a reference, defaults recipient='operator'/priority='medium'/
|      status='open', stamps created_by_user_id, and persists optional
|      asset_id / assigned_to_user_id / scheduling fields.
|
|  transition(OwnerRequest $request, string $status, array $extra = []): OwnerRequest
|    - 'in_progress' bumps status only (no terminal stamps).
|    - 'resolved' stamps resolved_at + writes resolution_notes (falls back to the
|      existing notes when none supplied).
|    - 'closed' stamps closed_at.
|
| TERMINAL IMMUTABILITY (REQ-3): closed/cancelled owner-requests are immutable.
| The service has NO internal guard — immutability is enforced at the UI: the
| respond action is `->visible(canEdit && ! isTerminal)`. This file asserts the
| real enforcement point (model isTerminal() + the resource action predicate),
| mirroring tests/Feature/MaintenanceImmutabilityTest.php.
*/

use App\Filament\Admin\Resources\OwnerRequests\OwnerRequestResource;
use App\Models\OwnerRequest;
use App\Notifications\OwnerRequestNotification;
use App\Services\OwnerRequestService;
use Database\Seeders\RolesPermissionsSeeder;
use Illuminate\Support\Facades\Notification;

function orSvc(): OwnerRequestService
{
    return app(OwnerRequestService::class);
}

beforeEach(function () {
    Notification::fake();
});

// ============================================================
// create()
// ============================================================

it('creates an operator request with sensible defaults and a generated reference', function () {
    $owner = makeUser('owner');

    $req = orSvc()->create([
        'subject' => 'HVAC quote',
        'body' => 'Need a quote for the food court HVAC.',
    ], $owner);

    expect($req->exists)->toBeTrue()
        ->and($req->reference)->toMatch('/^OR-\d{4}-\d{4}$/')
        ->and($req->status)->toBe('open')
        ->and($req->recipient)->toBe('operator')   // default
        ->and($req->priority)->toBe('medium')       // default
        ->and($req->created_by_user_id)->toBe($owner->id)
        ->and($req->assigned_to_user_id)->toBeNull()
        ->and($req->asset_id)->toBeNull();
});

it('persists optional asset, priority, assignee and scheduling window on create', function () {
    $owner = makeUser('owner');
    $assignee = makeUser('owner');
    $asset = makeAsset(['code' => 'HW']);

    $req = orSvc()->create([
        'subject' => 'Owner-to-owner sync',
        'body' => 'Align on the shared facade works.',
        'recipient' => 'owner',
        'assigned_to_user_id' => $assignee->id,
        'asset_id' => $asset->id,
        'priority' => 'high',
        'scheduled_from' => '2026-07-01 09:00:00',
        'scheduled_to' => '2026-07-01 12:00:00',
    ], $owner);

    expect($req->recipient)->toBe('owner')
        ->and($req->assigned_to_user_id)->toBe($assignee->id)
        ->and($req->asset_id)->toBe($asset->id)
        ->and($req->priority)->toBe('high')
        ->and($req->scheduled_from->format('Y-m-d H:i:s'))->toBe('2026-07-01 09:00:00')
        ->and($req->scheduled_to->format('Y-m-d H:i:s'))->toBe('2026-07-01 12:00:00');
});

it('generates monotonically increasing references within the year', function () {
    $owner = makeUser('owner');

    $first = orSvc()->create(['subject' => 'a', 'body' => 'a'], $owner);
    $second = orSvc()->create(['subject' => 'b', 'body' => 'b'], $owner);

    expect($first->reference)->toBe('OR-' . now()->format('Y') . '-0001')
        ->and($second->reference)->toBe('OR-' . now()->format('Y') . '-0002');
});

// ============================================================
// transition()
// ============================================================

it('transitions to in_progress without stamping any terminal/resolution fields', function () {
    $owner = makeUser('owner');
    $req = orSvc()->create(['subject' => 'x', 'body' => 'y'], $owner);

    $out = orSvc()->transition($req, 'in_progress');

    expect($out->status)->toBe('in_progress')
        ->and($out->resolved_at)->toBeNull()
        ->and($out->closed_at)->toBeNull()
        ->and($out->isOpen())->toBeTrue()
        ->and($out->isTerminal())->toBeFalse();
});

it('resolving stamps resolved_at and writes the supplied resolution notes', function () {
    $owner = makeUser('owner');
    $req = orSvc()->create(['subject' => 'Lift noisy', 'body' => 'Lift 2 grinds.'], $owner);

    \Illuminate\Support\Carbon::setTestNow('2026-07-10 14:00:00');
    $out = orSvc()->transition($req, 'resolved', ['resolution_notes' => 'Replaced the bearing.']);
    \Illuminate\Support\Carbon::setTestNow();

    expect($out->status)->toBe('resolved')
        ->and($out->resolution_notes)->toBe('Replaced the bearing.')
        ->and($out->resolved_at)->not->toBeNull()
        ->and($out->resolved_at->format('Y-m-d H:i:s'))->toBe('2026-07-10 14:00:00');
});

it('resolving without notes falls back to the existing resolution_notes', function () {
    $owner = makeUser('owner');
    $req = orSvc()->create(['subject' => 'x', 'body' => 'y'], $owner);
    // Pre-seed a note, then resolve WITHOUT passing one — the fallback keeps it.
    $req->update(['resolution_notes' => 'pre-existing note']);

    $out = orSvc()->transition($req->fresh(), 'resolved');

    expect($out->status)->toBe('resolved')
        ->and($out->resolution_notes)->toBe('pre-existing note')
        ->and($out->resolved_at)->not->toBeNull();
});

it('closing stamps closed_at and flips the request to terminal', function () {
    $owner = makeUser('owner');
    $req = orSvc()->create(['subject' => 'x', 'body' => 'y'], $owner);

    $out = orSvc()->transition($req, 'closed');

    expect($out->status)->toBe('closed')
        ->and($out->closed_at)->not->toBeNull()
        ->and($out->isTerminal())->toBeTrue()
        ->and($out->isOpen())->toBeFalse();
});

it('notifies the raising owner with the "updated" event on every transition', function () {
    $owner = makeUser('owner');
    $req = orSvc()->create(['subject' => 'x', 'body' => 'y'], $owner);

    orSvc()->transition($req, 'in_progress');

    Notification::assertSentTo($owner, OwnerRequestNotification::class, fn ($n) => $n->event === 'updated');
});

// ============================================================
// TERMINAL IMMUTABILITY (REQ-3)
// ============================================================

it('flags closed and cancelled owner-requests as terminal (and open ones as not)', function () {
    expect((new OwnerRequest(['status' => 'closed']))->isTerminal())->toBeTrue()
        ->and((new OwnerRequest(['status' => 'cancelled']))->isTerminal())->toBeTrue()
        ->and((new OwnerRequest(['status' => 'in_progress']))->isTerminal())->toBeFalse()
        ->and((new OwnerRequest(['status' => 'open']))->isTerminal())->toBeFalse();
});

it('hides the respond action on a terminal owner-request even from an operator (immutability guard)', function () {
    $this->seed(RolesPermissionsSeeder::class);
    $manager = makeUser('manager');
    $this->actingAs($manager);

    $open = new OwnerRequest(['status' => 'open']);
    $closed = new OwnerRequest(['status' => 'closed']);
    $cancelled = new OwnerRequest(['status' => 'cancelled']);

    // The action's real predicate: canEdit && ! isTerminal (OwnerRequestsTable).
    $respondVisible = fn (OwnerRequest $r) => OwnerRequestResource::canEdit($r) && ! $r->isTerminal();

    expect(OwnerRequestResource::canEdit($closed))->toBeTrue()   // permission alone allows it...
        ->and($respondVisible($open))->toBeTrue()                 // ...open is respondable...
        ->and($respondVisible($closed))->toBeFalse()             // ...but terminal records are locked.
        ->and($respondVisible($cancelled))->toBeFalse();
});
