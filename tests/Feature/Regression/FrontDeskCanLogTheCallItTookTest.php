<?php

use App\Filament\Admin\Actions\TenantActions;
use App\Filament\Admin\RelationManagers\PortalUsersRelationManager;
use App\Filament\Admin\RelationManagers\TenantNotesRelationManager;
use App\Filament\Admin\Resources\Tenants\Pages\EditTenant;
use App\Filament\Admin\Resources\Tenants\Pages\ViewTenant;
use App\Filament\Admin\Resources\Tenants\TenantResource;
use App\Models\Note;
use App\Support\Filament\RecordChanged;
use Database\Seeders\RolesPermissionsSeeder;
use Livewire\Livewire;

/**
 * **`customer_service` holds `notes.create` and could not create a note.**
 *
 * The front desk is defined in `RolesPermissionsSeeder` as `tenants.view` · `notes.view` ·
 * `notes.create` · the request permissions — and deliberately NO `tenants.edit`, because it has no
 * work authority. So `ViewTenant` is the only tenant screen it can open, and Filament makes every
 * relation manager on a `ViewRecord` read-only: `RelationManager::getDefaultActionAuthorizationResponse()`
 * returns `Response::deny()` for a `CreateAction` before the action's own gate is consulted.
 *
 * The result was a right that read as granted and reached no screen — `App\Support\PermissionReach`'s
 * failure, in its confusing direction: the role holds the permission, the screen refuses, and
 * nobody can tell policy from bug. Measured on the code as it stood: `isReadOnly: true`,
 * `isAuthorized: false`, `isVisible: false`.
 *
 * **THE ROUTE CHANGED ON 2026-09-05 AND THE REQUIREMENT DID NOT.** The first answer was for
 * `TenantNotesRelationManager` to waive the read-only default, letting its three call-site gates
 * decide. That worked and it cost something: a page whose whole claim is that it does not write
 * rendered *Log communication*, *Edit* and *Delete* inside one of its tabs — reported from the
 * panel as exactly that. So the act moved to `ViewTenant`'s HEADER
 * ({@see TenantActions}), where this panel puts acts, and the
 * tab went back to Filament's default. The front desk keeps its one function, the tabs stop
 * writing, and the two surfaces share one form.
 *
 * What this file still proves is the requirement, not the mechanism: the front desk can log the
 * call it just took, from the only tenant screen it can open, and nothing else came with it.
 */
beforeEach(function () {
    $this->seed(RolesPermissionsSeeder::class);
    ensureAllPropertiesAsset();

    $this->asset = makeAsset();
    $this->tenant = makeTenant(['name' => 'Front Desk Caller Ltd']);
});

/** The relation manager as it is actually mounted — under the VIEW page, which is the whole point. */
function notesRmOnViewPage($tenant)
{
    return Livewire::test(TenantNotesRelationManager::class, [
        'ownerRecord' => $tenant,
        'pageClass' => ViewTenant::class,
    ]);
}

it('lets the front desk log a note from the only tenant screen it can open', function () {
    $desk = makeUser('customer_service', [$this->asset->id]);
    $this->actingAs($desk);

    // The premise, asserted rather than assumed — if the seeder ever grants `tenants.edit` here,
    // this test would be proving something about a different role.
    expect($desk->can('notes.create'))->toBeTrue()
        ->and($desk->can('tenants.edit'))->toBeFalse()
        ->and(TenantResource::canEdit($this->tenant))->toBeFalse();

    // Driven through the real page and the real act, not by asking an action whether it feels
    // authorized: what was reported broken was the operator being unable to write the note down,
    // so the assertion is that the note exists afterwards.
    asTenant($this->asset, function () {
        Livewire::test(ViewTenant::class, ['record' => $this->tenant->getKey()])
            ->assertActionVisible('logCommunication')
            ->callAction('logCommunication', [
                'channel' => 'call',
                'contacted_at' => now()->toDateTimeString(),
                'subject' => 'Service-charge query',
                'body' => 'Asked about the service-charge line on invoice 0042.',
            ]);
    });

    $note = Note::sole();

    expect($note->body)->toBe('Asked about the service-charge line on invoice 0042.')
        ->and($note->noteable_id)->toBe($this->tenant->getKey())
        // Stamped from the session, never from the payload: who made the call is not something
        // the form may state.
        ->and($note->author_id)->toBe($desk->id);
});

it('shows the new note in the tab it was NOT written from', function () {
    // ASSERTING THE ROW IS NOT ASSERTING THE SCREEN, and moving the act to the header is exactly
    // what makes the difference bite: `HasRelationManagers` mounts each manager with a stable
    // `key()`, which is what tells Livewire 3 to leave a child alone when the parent re-renders.
    // So the header saved the note and the tab below went on showing the rows from before the
    // click, under a success toast — which an operator reads as "it did not save", and logs again.
    // `TenantNotesRelationManager` listens for `RecordChanged::EVENT` for that reason; this is the
    // assertion that says so, and the test above would stay green with the listener removed.
    $desk = makeUser('customer_service', [$this->asset->id]);
    $this->actingAs($desk);

    asTenant($this->asset, function () {
        $rm = notesRmOnViewPage($this->tenant);

        expect(tableRows($rm))->toBeEmpty();

        // The page's header act, announcing to the components around it exactly as it does live.
        Livewire::test(ViewTenant::class, ['record' => $this->tenant->getKey()])
            ->callAction('logCommunication', [
                'channel' => 'whatsapp',
                'contacted_at' => now()->toDateTimeString(),
                'subject' => 'Renewal',
                'body' => 'Asked when the renewal offer lands.',
            ]);

        // The relation manager, told to refresh, re-reads.
        $rm->dispatch(RecordChanged::EVENT);

        expect(tableRows($rm)->pluck('body')->all())->toBe(['Asked when the renewal offer lands.']);
    });
});

it('still refuses the act to a role that does not hold notes.create', function () {
    // The control, and it is doing real work: a refusal test passes just as happily when the act
    // has quietly stopped existing, so it is paired with the case above that must succeed.
    // `viewer` is the right foil — it can open the page (`tenants.view`) and read the notes tab
    // (`notes.view`), and holds nothing that may write.
    $reader = makeUser('viewer', [$this->asset->id]);
    $this->actingAs($reader);

    expect($reader->can('notes.view'))->toBeTrue()
        ->and($reader->can('notes.create'))->toBeFalse();

    asTenant($this->asset, function () {
        $page = Livewire::test(ViewTenant::class, ['record' => $this->tenant->getKey()]);

        $page->assertActionHidden('logCommunication');

        // Hidden is the UI half. The gate is the other one, and it is a separate layer here: the
        // act declares `->authorize()`, so `AuthorizedAction::call()` aborts 403 on dispatch even
        // if a release ever stopped hidden implying disabled. Asserting the predicate directly is
        // what CLAUDE.md prescribes — neither `callAction()` nor `mountAction` can prove a gate,
        // because both refuse a hidden action first and go green whether or not the gate exists.
        expect($page->instance()->getAction('logCommunication')->isAuthorized())->toBeFalse();
    });

    expect(Note::count())->toBe(0);
});

it('refuses EDIT and DELETE to the front desk, which holds neither', function () {
    // "Let the front desk log a call" must not have become "let the front desk rewrite the file".
    // Under the waiver this was the row actions' own `->authorize()` doing the work; now Filament's
    // read-only default refuses them first. Both are worth asserting the same way, because what
    // matters is the OUTCOME for this role and not which layer produced it.
    $desk = makeUser('customer_service', [$this->asset->id]);

    $note = $this->tenant->notes()->create([
        'author_id' => $desk->id,
        'channel' => 'call',
        'contacted_at' => now(),
        'subject' => 'Service-charge query',
        'body' => 'Asked about the service-charge line on invoice 0042.',
    ]);

    $this->actingAs($desk);

    expect(auth()->user()->can('notes.edit'))->toBeFalse();

    asTenant($this->asset, function () use ($note) {
        $rm = notesRmOnViewPage($this->tenant);
        $rowActions = $rm->instance()->getTable()->getRecordActions();

        // The premise: `foreach` over an empty array asserts nothing, and a manager that had lost
        // its row actions entirely would satisfy every refusal below.
        expect($rowActions)->not->toBeEmpty();

        foreach ($rowActions as $action) {
            $bound = $action->getClone()->record($note);

            expect($bound->isAuthorized())->toBeFalse(
                "{$action->getName()} is offered to a role holding only notes.create."
            );
        }
    });
});

it('still denies a relation manager that has NOT waived the read-only default', function () {
    // The other half of the seam, and the half nothing covered. `defaultAuthorizationAllows()` was
    // added so a call-site `->authorize()` narrows Filament's default instead of REPLACING it — but
    // every `pageClass` in the suite was an `Edit*` page, so the `isReadOnly()` branch it exists for
    // was never once evaluated. A guard whose only branch is untested is the shape this codebase has
    // been bitten by repeatedly.
    //
    // `PortalUsersRelationManager` is the control precisely because it did NOT waive read-only: a
    // manager who may edit the tenant everywhere else is still refused from the View page, which is
    // what says the waiver on the notes manager is a decision rather than a side effect.
    $this->actingAs(makeUser('manager', [$this->asset->id]));

    expect(auth()->user()->can('tenants.edit'))->toBeTrue();

    asTenant($this->asset, function () {
        $rm = Livewire::test(PortalUsersRelationManager::class, [
            'ownerRecord' => $this->tenant,
            'pageClass' => ViewTenant::class,
        ]);

        expect($rm->instance()->isReadOnly())->toBeTrue();

        foreach ($rm->instance()->getTable()->getHeaderActions() as $action) {
            expect($action->isAuthorized())->toBeFalse(
                "{$action->getName()} survived the read-only-on-a-View-page deny."
            );
        }
    });

    // …and the SAME user, the SAME action, on the EDIT page, is allowed — or the assertion above
    // would pass just as happily against a gate that refuses everyone everywhere.
    asTenant($this->asset, function () {
        $rm = Livewire::test(PortalUsersRelationManager::class, [
            'ownerRecord' => $this->tenant,
            'pageClass' => EditTenant::class,
        ]);

        expect($rm->instance()->isReadOnly())->toBeFalse();

        $allowed = collect($rm->instance()->getTable()->getHeaderActions())
            ->filter(fn ($action) => $action->isAuthorized());

        expect($allowed)->not->toBeEmpty('Nothing is creatable even on the Edit page — the control proves nothing.');
    });
});
