<?php

use App\Filament\Admin\Resources\Leases\Pages\EditLease;
use App\Models\Lease;
use App\Support\DeletionPolicy;
use Database\Seeders\RolesPermissionsSeeder;
use Filament\Actions\Action;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;

/**
 * A Delete button that cannot delete does not look like one that will.
 *
 * Reported by the tester on a lease: press Delete, confirm on a modal that asks nothing but "are you
 * sure", watch the page reload, and find the lease still there with **no message of any kind**.
 *
 * Nothing was broken underneath. `RefusesDeletionWhenReferenced` did exactly its job — the lease had
 * an open invoice, the refusal fired, nothing was deleted — and the operator was told none of it. A
 * destructive control that silently does nothing is read as a broken button, which is how it was
 * filed. `isDeletableNow()` had carried the docblock *"Drives the UI so the button matches the
 * outcome"* since it was written, and drove nothing.
 *
 * **Disabled, not hidden, and deliberately not an authorization failure.** Blockers are a RULE the
 * operator ran into, not a right they lack — folding them into `isAuthorized()` would remove the
 * button and answer 403, which is the same silence in a different costume. Yardi refuses rather than
 * warns here and shows the reason; disabled keeps the affordance on screen and lets the tooltip and
 * the modal carry it.
 */
beforeEach(function () {
    $this->seed(RolesPermissionsSeeder::class);
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->actingAs(makeUser('super_admin'));
    $this->asset = makeAsset();
});

function leaseDeleteAction(Lease $lease): Action
{
    return Livewire::test(EditLease::class, ['record' => $lease->getRouteKey()])
        ->instance()
        ->getAction('delete');
}

it('disables Delete on a lease that history still points at', function () {
    $lease = makeLease(makeUnit($this->asset), makeTenant(), ['status' => 'active']);
    makeInvoice($lease);

    asTenant($this->asset, function () use ($lease) {
        expect(leaseDeleteAction($lease->fresh())->isDisabled())->toBeTrue();
    });
});

it('leaves Delete usable on a lease nothing references', function () {
    // The control. The assertion above passes just as happily on a button that is always disabled,
    // and a Delete nobody can ever press is a worse bug than the one being fixed.
    $lease = makeLease(makeUnit($this->asset), makeTenant(), ['status' => 'draft']);

    asTenant($this->asset, function () use ($lease) {
        expect(leaseDeleteAction($lease->fresh())->isDisabled())->toBeFalse();
    });
});

it('says WHAT is blocking it and what to do instead', function () {
    $lease = makeLease(makeUnit($this->asset), makeTenant(), ['status' => 'active']);
    makeInvoice($lease);

    asTenant($this->asset, function () use ($lease) {
        $action = leaseDeleteAction($lease->fresh());

        // The count and the noun, so the operator can go and look — not a bare "cannot delete".
        expect((string) $action->getTooltip())->toContain('1 invoices')
            // …and the documented escape, which for a lease is termination.
            ->and((string) $action->getTooltip())->toContain('terminate the lease')
            // The modal carries the same sentence: a tooltip needs hover, and a touch device has none.
            ->and((string) $action->getModalDescription())->toBe((string) $action->getTooltip());
    });
});

it('still refuses the write if the button is dispatched anyway', function () {
    // The disabled state is a UI truth, not a gate — the payload still carries the click. The model
    // remains the layer that actually refuses, and this pins that the fix did not replace it.
    $lease = makeLease(makeUnit($this->asset), makeTenant(), ['status' => 'active']);
    makeInvoice($lease);

    expect(fn () => $lease->fresh()->delete())->toThrow(DomainException::class);

    expect(Lease::whereKey($lease->id)->exists())->toBeTrue();
});

it('refuses in the reader s language, from ONE wording', function () {
    // The button and the model's refusal read the same key, so the sentence an operator is shown
    // before they press cannot drift from the one they are shown after.
    $lease = makeLease(makeUnit($this->asset), makeTenant(), ['status' => 'active']);
    makeInvoice($lease);

    try {
        $lease->fresh()->delete();
        $thrown = null;
    } catch (DomainException $e) {
        $thrown = $e->getMessage();
    }

    asTenant($this->asset, function () use ($lease, $thrown) {
        expect($thrown)->toBe((string) leaseDeleteAction($lease->fresh())->getTooltip());
    });
});

it('costs one record s worth of counts, asked once however many times it is read', function () {
    // The button reads the answer THREE times on one render — to disable, to word the tooltip, and
    // to word the modal — so the memoisation is what keeps this from tripling. Bounded by the
    // POLICY's relation count, never by rows: all six `DeletableWhenUnused` models put Delete on the
    // record page and none on a table row, which is what was checked before this was written.
    $lease = makeLease(makeUnit($this->asset), makeTenant(), ['status' => 'draft']);
    $fresh = $lease->fresh();

    $queries = 0;
    DB::listen(function () use (&$queries) {
        $queries++;
    });

    $fresh->deletionBlockers();
    $afterFirst = $queries;

    $fresh->deletionBlockers();
    $fresh->deletionBlockers();

    expect($afterFirst)->toBeLessThanOrEqual(count(
        DeletionPolicy::blockingRelationsFor(Lease::class)
    ))
        // Read three times, asked once.
        ->and($queries)->toBe($afterFirst);
});
