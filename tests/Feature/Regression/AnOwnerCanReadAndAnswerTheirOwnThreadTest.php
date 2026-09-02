<?php

use App\Filament\Admin\Resources\OwnerRequests\OwnerRequestResource;
use App\Filament\Admin\Resources\OwnerRequests\Pages\ListOwnerRequests;
use App\Models\OwnerRequest;
use App\Services\OwnerRequestService;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Database\Seeders\RolesPermissionsSeeder;
use Livewire\Livewire;

/**
 * MODULE 15 BUILT A TWO-WAY THREAD AND GAVE ONE PARTY NO DOOR ONTO IT.
 *
 * `OwnerRequestService::notifyCounterparty()` says it in writing — it bells "the operator team when
 * the owner replies" — so the service was designed for both directions from the start. The only
 * surface onto the conversation was the reply modal, gated on `canEdit()`, and the `owner` role
 * holds `owner_requests.view` and `.create` and deliberately NOT `.edit`. So Jawad could raise a
 * request, be answered, and neither see the answer nor respond to it: the mechanism was complete
 * and the door was missing, which is the shape this codebase keeps finding.
 *
 * **And a GENERAL request was invisible to the operator too.** `asset_id` is nullable here on
 * purpose — `PropertyField::PORTFOLIO_LEVEL` records why, *"a general question is about no single
 * mall"* — and it is what the form defaults to. The operator inbox scoped with
 * `whereIn('asset_id', $ids)`, which never matches NULL, so a property-restricted operator's inbox
 * silently omitted exactly the requests addressed to the operator generally. Same trap as EG-27's
 * financial statements and the department pickers that offered zero options.
 *
 * Every refusal is paired with a control that must succeed.
 */
beforeEach(function () {
    // The real catalogue: `seedRoles()` creates empty roles, and every gate here turns on which
    // permissions a role actually holds.
    $this->seed(RolesPermissionsSeeder::class);

    $this->mall = makeAsset(['code' => 'AA']);
    $this->other = makeAsset(['code' => 'BB']);

    $this->owner = makeUser('owner', [$this->mall->id]);
    $this->operator = makeUser('manager', [$this->mall->id]);

    $this->svc = app(OwnerRequestService::class);
});

/** A request the owner raised, optionally about one mall. */
function ownerRequestFrom(?int $assetId, array $attrs = []): OwnerRequest
{
    return test()->svc->create(array_merge([
        'subject' => 'Facade budget split',
        'body' => 'Please confirm the 60/40 split for the facade works.',
        'recipient' => 'operator',
        'asset_id' => $assetId,
    ], $attrs), test()->owner);
}

it('shows a GENERAL request to a property-restricted operator', function () {
    $general = ownerRequestFrom(null);
    $mine = ownerRequestFrom(test()->mall->id, ['subject' => 'Mall AA lighting']);
    $theirs = ownerRequestFrom(test()->other->id, ['subject' => 'Mall BB roof']);

    $this->actingAs($this->operator);
    Filament::setCurrentPanel(Filament::getPanel('admin'));

    $ids = asTenant($this->mall, fn () => OwnerRequestResource::getEloquentQuery()->pluck('id'));

    // The control and the refusal together: a scope that returned everything would satisfy the
    // first two assertions and leak the other mall's request.
    expect($ids)->toContain($general->id)
        ->and($ids)->toContain($mine->id)
        ->and($ids)->not->toContain($theirs->id);
});

it('lets the owner read the answer they were given', function () {
    $request = ownerRequestFrom(null);
    $this->svc->reply($request, $this->operator, 'Reviewing with the contractor now.');

    $this->actingAs($this->owner);
    Filament::setCurrentPanel(Filament::getPanel('admin'));

    asTenant($this->mall, fn () => Livewire::test(ListOwnerRequests::class)
        ->mountAction(TestAction::make('conversation')->table($request))
        ->assertHasNoActionErrors());
});

it('lets the owner answer their own thread, and tells the operator', function () {
    $request = ownerRequestFrom(null);
    $this->svc->reply($request, $this->operator, 'Reviewing with the contractor now.');

    $this->actingAs($this->owner);
    Filament::setCurrentPanel(Filament::getPanel('admin'));

    asTenant($this->mall, fn () => Livewire::test(ListOwnerRequests::class)
        ->mountAction(TestAction::make('reply')->table($request))
        ->setActionData(['body' => 'Thanks — I need it by Sunday.'])
        ->callMountedAction()
        ->assertHasNoActionErrors());

    expect($request->refresh()->replies->pluck('body')->all())->toBe([
        'Reviewing with the contractor now.',
        'Thanks — I need it by Sunday.',
    ]);
});

it('does not let the owner move the status of their own request', function () {
    // Marking a request resolved is the OPERATOR saying the work is done. The Select is hidden for
    // a non-editor AND dropped in the action, because a hidden field still arrives in the payload.
    $request = ownerRequestFrom(null);

    $this->actingAs($this->owner);
    Filament::setCurrentPanel(Filament::getPanel('admin'));

    // **TWO layers, and only the first is reachable from here.** The Select is `visible(canEdit)`,
    // so Filament never dehydrates it and the submitted status is dropped before the action runs —
    // which is what this asserts. The action ALSO drops it server-side, and that layer cannot be
    // driven through a Livewire test at all: `callMountedAction()` re-derives `$data` from the
    // schema, so an injected `mountedActions.0.data.status` is discarded on the way in (measured).
    // It stays because it is a stated intent, where upstream dehydration is an implementation
    // detail a release can change — the same reasoning `FilamentActionDispatchContractTest` records
    // for `visible()`-only actions.
    asTenant($this->mall, fn () => Livewire::test(ListOwnerRequests::class)
        ->mountAction(TestAction::make('reply')->table($request))
        ->setActionData(['body' => 'Closing this off myself.', 'status' => 'resolved'])
        ->callMountedAction()
        ->assertHasNoActionErrors());

    expect($request->refresh()->status)->toBe('open')
        ->and($request->resolved_at)->toBeNull()
        // …but the message itself is on the thread. A guard that refused the whole reply would be
        // the original defect wearing a different coat.
        ->and($request->replies)->toHaveCount(1);
});

it('still lets the operator move the status — the control for the refusal above', function () {
    $request = ownerRequestFrom(null);

    $this->actingAs($this->operator);
    Filament::setCurrentPanel(Filament::getPanel('admin'));

    asTenant($this->mall, fn () => Livewire::test(ListOwnerRequests::class)
        ->mountAction(TestAction::make('reply')->table($request))
        ->setActionData(['body' => 'Agreed — 60/40 confirmed.', 'status' => 'resolved'])
        ->callMountedAction()
        ->assertHasNoActionErrors());

    expect($request->refresh()->status)->toBe('resolved')
        ->and($request->resolved_at)->not->toBeNull();
});

it('does not let one owner answer another owner s request', function () {
    // "It is mine" is what `getEloquentQuery()` already establishes, so the reply predicate asks the
    // same question rather than granting a new right. A second owner must not reach it.
    $request = ownerRequestFrom(null);
    $stranger = makeUser('owner', [$this->mall->id]);

    $this->actingAs($stranger);
    Filament::setCurrentPanel(Filament::getPanel('admin'));

    asTenant($this->mall, function () use ($request) {
        expect(OwnerRequestResource::getEloquentQuery()->pluck('id'))->not->toContain($request->id);

        // Not "the button is hidden" — the ROW is not there at all, which is the stronger refusal
        // and the one the scope actually makes. Filament cannot even resolve the record.
        Livewire::test(ListOwnerRequests::class)
            ->assertCanNotSeeTableRecords([$request]);
    });
});
