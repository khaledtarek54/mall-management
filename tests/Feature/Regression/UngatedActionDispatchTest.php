<?php

/*
|--------------------------------------------------------------------------
| A write action must be gated, not merely hidden
|--------------------------------------------------------------------------
| Companion to ActionAuthzConformanceTest, which scans for the SHAPE (an `->action()` with no
| `->authorize()`). This asserts the resulting BEHAVIOUR on the action with the sharpest
| consequence: `is_internal = false` publishes a staff note to the tenant's portal view, so
| toggling it is a disclosure, not a cosmetic flag.
|
| Two things worth knowing if you extend this file:
|
| 1. Dispatch with mountAction + callMountedAction, never `->callAction()`. callAction() asserts the
|    action is VISIBLE first, so it reports a visible()-only action as safe while saying nothing
|    about whether it is gated.
|
| 2. ALWAYS pair a refusal test with the authorised control below it. A refusal test passes just as
|    happily when the dispatch is a no-op — a first draft of this file "proved" three actions were
|    refused when in fact the harness had never invoked them at all, and only the control exposed
|    that.
*/

use App\Filament\Admin\RelationManagers\TenantRequestCommentsRelationManager;
use App\Filament\Admin\Resources\TenantRequests\Pages\EditTenantRequest;
use App\Models\TenantRequest;
use App\Models\TenantRequestComment;
use Database\Seeders\RolesPermissionsSeeder;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed(RolesPermissionsSeeder::class);
    ensureAllPropertiesAsset();
    $this->asset = makeAsset();
    Filament::setCurrentPanel(Filament::getPanel('admin'));
});

afterEach(fn () => Filament::setTenant(null, isQuiet: true));

/** Authenticate as a role, THEN scope the panel to the asset (setTenant needs a user). */
function ungatedActAs(string $role): void
{
    test()->actingAs(makeUser($role, [test()->asset->id]));
    Filament::setTenant(test()->asset);
}

/** A request carrying one INTERNAL staff comment. */
function requestWithInternalComment(string $reference): TenantRequestComment
{
    $unit = makeUnit(test()->asset);
    $tenant = makeTenant();
    $lease = makeLease($unit, $tenant, ['status' => 'active']);

    $request = TenantRequest::create([
        'reference' => $reference,
        'tenant_id' => $tenant->id,
        'unit_id' => $unit->id,
        'lease_id' => $lease->id,
        'request_type' => 'maintenance',
        'category' => 'electrical',
        'title' => 'Ceiling light out',
        'description' => 'The ceiling light has stopped working entirely.',
        'status' => 'submitted',
        'priority' => 'medium',
        'submitted_at' => now(),
    ]);

    $author = makeUser('manager', [test()->asset->id]);

    return TenantRequestComment::create([
        'tenant_request_id' => $request->id,
        'author_type' => $author->getMorphClass(),
        'author_id' => $author->id,
        'body' => 'Internal: the tenant is 3 months in arrears — do not commit to a date.',
        'is_internal' => true,
    ]);
}

function dispatchToggleVisibility(TenantRequestComment $comment): void
{
    Livewire::test(TenantRequestCommentsRelationManager::class, [
        'ownerRecord' => $comment->request,
        'pageClass' => EditTenantRequest::class,
    ])
        ->mountAction(TestAction::make('toggleVisibility')->table($comment))
        ->callMountedAction();
}

it('refuses a viewer exposing an internal staff comment to the tenant', function () {
    $comment = requestWithInternalComment('TR-AUTHZ-0001');

    ungatedActAs('viewer');

    dispatchToggleVisibility($comment);

    expect($comment->fresh()->is_internal)->toBeTrue('an internal staff note must not become tenant-visible');
});

it('control: an authorised user CAN toggle a comment to public', function () {
    // Without this the refusal above proves nothing — it would pass identically if the dispatch
    // silently did nothing for everyone.
    $comment = requestWithInternalComment('TR-AUTHZ-0002');

    ungatedActAs('manager');

    dispatchToggleVisibility($comment);

    expect($comment->fresh()->is_internal)->toBeFalse('the dispatch must actually work for an authorised user');
});
