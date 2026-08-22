<?php

use App\Filament\Admin\RelationManagers\PortalUsersRelationManager;
use App\Filament\Admin\RelationManagers\TenantNotesRelationManager;
use App\Filament\Admin\Resources\Tenants\Pages\EditTenant;
use App\Filament\Admin\Resources\Tenants\Pages\ViewTenant;
use App\Models\Note;
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
 * "The page is a View page" is a UI inference, not an authorization fact — this panel has no
 * policies and gates on permissions at the call site — so `TenantNotesRelationManager` waives the
 * default and the three call-site gates decide. Which is only safe BECAUSE they exist: the refusal
 * half below is what says so, and would go red if any of them were dropped.
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
    $this->actingAs(makeUser('customer_service', [$this->asset->id]));

    // The premise, asserted rather than assumed — if the seeder ever grants `tenants.edit` here,
    // this test would be proving something about a different role.
    expect(auth()->user()->can('notes.create'))->toBeTrue()
        ->and(auth()->user()->can('tenants.edit'))->toBeFalse();

    asTenant($this->asset, function () {
        $rm = notesRmOnViewPage($this->tenant);
        $create = $rm->instance()->getTable()->getHeaderActions()[0];

        expect($create->isAuthorized())->toBeTrue()
            ->and($create->isVisible())->toBeTrue();
    });
});

it('still refuses a role that does not hold notes.create', function () {
    // The control, and it is doing real work: waiving Filament's read-only default is only safe
    // because each action carries its own `->authorize()`. Drop one and this goes red — which is
    // the difference between a considered waiver and an open door.
    $this->actingAs(makeUser('vendor', [$this->asset->id]));

    expect(auth()->user()->can('notes.view'))->toBeTrue()
        ->and(auth()->user()->can('notes.create'))->toBeFalse();

    asTenant($this->asset, function () {
        $rm = notesRmOnViewPage($this->tenant);

        foreach ($rm->instance()->getTable()->getHeaderActions() as $action) {
            expect($action->isAuthorized())->toBeFalse()
                ->and($action->isVisible())->toBeFalse();
        }
    });
});

it('refuses EDIT and DELETE to the front desk, which holds neither', function () {
    // Waiving read-only opens the whole relation manager, not just the button that needed it — so
    // the row actions have to be checked too, or "let the front desk log a call" quietly became
    // "let the front desk rewrite the file".
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

        foreach ($rm->instance()->getTable()->getRecordActions() as $action) {
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
