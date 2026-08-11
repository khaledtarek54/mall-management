<?php

use App\Filament\Admin\Resources\PurchaseRequests\Pages\ListPurchaseRequests;
use App\Models\PurchaseRequest;
use Database\Seeders\RolesPermissionsSeeder;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Livewire\Livewire;

/**
 * Characterisation — gap-analysis **D-95** (module 29): the table `EditAction` may only edit a
 * request while it is still `requested`, and that gate must hold against a directly-mounted action
 * (not just a hidden button).
 *
 * The finding flagged that `visible()` is a display gate, not (per the framework's documented
 * `mountAction`) a dispatch gate, so the edit action carried no `->authorize()` while its five
 * siblings did. The fix mirrors `visible()` in `->authorize()`.
 *
 * WHAT THIS TEST PINS. It mounts the row action directly (the unified `mountAction`, which does NOT
 * pre-assert visibility) on an approved request and confirms the justification cannot be rewritten.
 * In THIS Filament build a hidden table action already refuses to mount, so this passes with or
 * without the `->authorize()` — the value is locking the *invariant* (an approved request's terms
 * are immutable through the edit action) so it survives a future Filament that decouples mount from
 * visibility, or a change that makes the action visible. It is characterisation, not a
 * fails-without-fix regression.
 */
beforeEach(function () {
    $this->seed(RolesPermissionsSeeder::class);
    $this->asset = makeAsset(['code' => 'PEG']);
    // super_admin so the permission half of the gate (canEdit) passes — the STATUS half is what
    // must block, and that's exactly what the finding is about.
    $this->actingAs(makeUser('super_admin', [$this->asset->id]));
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    Filament::setTenant($this->asset);
});

afterEach(fn () => Filament::setTenant(null, isQuiet: true));

function pegRequest(string $status, string $justification): PurchaseRequest
{
    $request = PurchaseRequest::create([
        'asset_id' => test()->asset->id,
        'reference' => 'PR-PEG-'.uniqid(),
        'justification' => $justification,
        'requested_by_user_id' => auth()->id(),
    ]);

    // The line goes on while the request is still `requested`, then the status moves — because a
    // line may no longer be ADDED to a decided request (PurchaseRequestLine's approval freeze).
    // Building the fixture in the order the product does keeps it a reachable state.
    $request->lines()->create(['description' => 'Compressor assembly', 'quantity' => 1, 'unit_cost' => 4000]);
    $request->forceFill(['status' => $status])->saveQuietly();

    return $request->refresh();
}

it('refuses to rewrite the justification of an approved request through the edit action (D-95)', function () {
    $request = pegRequest(PurchaseRequest::STATUS_APPROVED, 'Original — approved as written');

    // Mount the row action directly (bypasses visibility pre-checks), then try to rewrite.
    Livewire::test(ListPurchaseRequests::class)
        ->mountAction(TestAction::make('edit')->table($request))
        ->setActionData(['justification' => 'tampered behind the approval'])
        ->callMountedAction();

    // The gate holds — the approved request's terms are untouched.
    expect($request->fresh()->justification)->toBe('Original — approved as written');
});

it('still allows editing a request that is still in the requested state', function () {
    $request = pegRequest(PurchaseRequest::STATUS_REQUESTED, 'Original');

    Livewire::test(ListPurchaseRequests::class)
        ->mountAction(TestAction::make('edit')->table($request))
        ->setActionData(['asset_id' => $this->asset->id, 'justification' => 'Refined justification'])
        ->callMountedAction();

    expect($request->fresh()->justification)->toBe('Refined justification');
});
