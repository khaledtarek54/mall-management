<?php

use App\Filament\Portal\Resources\MaintenanceRequests\Pages\ViewMaintenanceRequest;
use Filament\Facades\Filament;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Regression — a read-only TenantUser must not be able to DISPATCH portal write actions.
 *
 * THE BUG (fixed 2026-07-16). The portal's rule is that only an admin TenantUser may write.
 * That was enforced by hiding buttons — `->visible(...)` — which is **not** an authorization
 * gate. Filament's `InteractsWithActions::mountAction()` checks `$action->isDisabled()` and
 * never `$action->isVisible()`, so a hidden action is still mountable and callable by anyone
 * who can craft the Livewire request. `visible()` styles the UI; it does not defend it.
 *
 * Two shapes of the same hole:
 *   - ViewMaintenanceRequest::addComment/cancel had **no isAdmin() check at all** — they
 *     gated on record status only, so a read-only user could cancel their company's request
 *     from the UI, no crafting required.
 *   - ViewInvoice::payNow/payDemo had isAdmin() inside `visible()` only — invisible to a
 *     read-only user, still dispatchable by one.
 *
 * `InvoicesTable` had it right all along (`visible(...)` for the UI **plus**
 * `abort_unless(Portal::isAdmin(), 403)` inside `->action()`), which is the pattern these now
 * follow. The old TenantUserGatingTest only asserted `canCreate()` return values and never
 * dispatched anything, which is why the hole survived.
 *
 * ⚠️ NOTE FOR FUTURE TESTS: `->callAction()` is NOT an attack. Filament's test helper calls
 * `assertActionVisible()` first (TestsActions.php:84), so it can only ever reach an action
 * the UI would show — it would report this bug as fixed while it was still exploitable.
 * These tests go through `mountAction` + `callMountedAction`, the real runtime entrypoints,
 * which is why they can prove the guard rather than the styling.
 *
 * Blast radius is within-tenant (cross-tenant access is separately blocked and returns 404),
 * but "a viewer can cancel the company's maintenance request" is still an authz failure.
 */
beforeEach(function () {
    $this->tenant = makeTenant();
    $this->request = makeMaintenanceRequest([
        'tenant_id' => $this->tenant->id,
        'status' => 'submitted',
    ]);

    Filament::setCurrentPanel(Filament::getPanel('portal'));
});

/** Dispatch an action the way the runtime does, bypassing the UI. Swallows the abort. */
function dispatchPortalAction(string $page, string $record, string $action): void
{
    try {
        Livewire::test($page, ['record' => $record])
            ->call('mountAction', $action)
            ->call('callMountedAction');
    } catch (HttpException) {
        // Expected once guarded — the assertion is the absent side effect.
    }
}

it('refuses a read-only tenant user cancelling a request, even dispatched directly', function () {
    $this->actingAs(makeTenantUser($this->tenant, isAdmin: false), 'portal');

    dispatchPortalAction(ViewMaintenanceRequest::class, $this->request->getRouteKey(), 'cancel');

    expect($this->request->fresh()->status)
        ->toBe('submitted', 'a read-only tenant user must not be able to cancel the request');
});

it('refuses a read-only tenant user commenting on a request, even dispatched directly', function () {
    $this->actingAs(makeTenantUser($this->tenant, isAdmin: false), 'portal');

    dispatchPortalAction(ViewMaintenanceRequest::class, $this->request->getRouteKey(), 'addComment');

    expect($this->request->fresh()->comments()->count())
        ->toBe(0, 'a read-only tenant user must not be able to comment');
});

it('hides the write actions from a read-only tenant user', function () {
    // The UI half. Necessary but NOT sufficient — see the note above.
    $this->actingAs(makeTenantUser($this->tenant, isAdmin: false), 'portal');

    Livewire::test(ViewMaintenanceRequest::class, ['record' => $this->request->getRouteKey()])
        ->assertActionHidden('cancel')
        ->assertActionHidden('addComment');
});

it('still lets an admin tenant user comment and cancel', function () {
    // The guard must block the read-only user WITHOUT breaking the real workflow.
    $this->actingAs(makeTenantUser($this->tenant, isAdmin: true), 'portal');

    Livewire::test(ViewMaintenanceRequest::class, ['record' => $this->request->getRouteKey()])
        ->assertActionVisible('addComment')
        ->callAction('addComment', ['body' => 'Any update on this?']);

    expect($this->request->fresh()->comments()->count())->toBe(1);

    Livewire::test(ViewMaintenanceRequest::class, ['record' => $this->request->getRouteKey()])
        ->callAction('cancel');

    expect($this->request->fresh()->status)->toBe('cancelled');
});
