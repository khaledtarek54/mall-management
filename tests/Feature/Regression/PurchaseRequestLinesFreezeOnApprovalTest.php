<?php

use App\Models\PurchaseRequest;
use App\Services\PurchaseRequestService;
use Database\Seeders\ApprovalRulesSeeder;
use Database\Seeders\RolesPermissionsSeeder;

/**
 * A purchase request's lines are settled once it is approved.
 *
 * THE GAP (module 29 close-out, 2026-08-11) — and this one defeats the approval ladder rather than
 * merely misstating a balance.
 *
 * `PurchaseRequestLine::saved`/`deleted` call `recomputeTotal()`, which re-derives `total_value`
 * from Σ lines. `required_permission` — the tier that decides WHO may approve — is deliberately
 * frozen there: `recomputeTotal()` only re-derives it `if ($this->status === STATUS_REQUESTED)`,
 * so the record keeps saying who was *supposed* to sign it off even after someone edits the bands.
 * That freeze is right, and it was the whole point of the F-104 fix.
 *
 * But nothing stopped the LINES moving after approval. So:
 *
 *   1. raise a request for 5,000 → tier_1 → a supervisor approves it, correctly;
 *   2. add a 500,000 line to the approved request;
 *   3. `total_value` re-derives to 505,000 while `required_permission` stays frozen at tier_1.
 *
 * The mall is now committed to a purchase two tiers above what anybody with the authority signed
 * off, and the record asserts that a supervisor approved it. The one mechanism whose whole job is
 * to fail closed, failing open — the same sentence `ApprovalPolicy` already carries about its own
 * band resolution, arriving from the other side.
 *
 * The rule already existed in the UI: `PurchaseRequestLinesRelationManager::editable()` is
 * `status === requested`, gating the add / edit / delete actions. It was a property of that screen —
 * an import, the console, a service or a future screen wrote lines freely.
 *
 * Fixed at the model, mirroring the header freeze that `PurchaseRequest::updating` already applies
 * (asset / warehouse / justification, from the same close-out) — the lines are just as much of what
 * the approval signed off on as the warehouse the goods land in.
 */
beforeEach(function () {
    $this->seed(RolesPermissionsSeeder::class);
    $this->seed(ApprovalRulesSeeder::class);

    $this->asset = makeAsset(['code' => 'PRF']);
    $this->buyer = makeUser('operations', [$this->asset->id]);
    $this->senior = makeUser('super_admin', [$this->asset->id]);
});

function prfRequest(float $lineCost = 5000): PurchaseRequest
{
    $request = PurchaseRequest::create([
        'asset_id' => test()->asset->id,
        'reference' => 'PR-PRF-'.uniqid(),
        'justification' => 'Parts for the chiller',
        'requested_by_user_id' => test()->buyer->id,
    ]);

    $request->lines()->create(['description' => 'Filters', 'quantity' => 1, 'unit_cost' => $lineCost]);

    return $request->fresh();
}

it('refuses a line added after the request was approved', function () {
    $request = prfRequest(5000);
    $tier = $request->required_permission;

    app(PurchaseRequestService::class)->approve($request, null, $this->senior);

    expect(fn () => $request->fresh()->lines()->create([
        'description' => 'Compressor', 'quantity' => 1, 'unit_cost' => 500000,
    ]))->toThrow(DomainException::class);

    // The commitment and the authority that signed it off still agree.
    $request->refresh();
    expect(round((float) $request->total_value, 2))->toBe(5000.0)
        ->and($request->required_permission)->toBe($tier);
});

it('refuses editing a line on an approved request', function () {
    $request = prfRequest(5000);
    app(PurchaseRequestService::class)->approve($request, null, $this->senior);

    $line = $request->fresh()->lines()->first();

    expect(fn () => $line->update(['unit_cost' => 500000]))->toThrow(DomainException::class);
    expect(round((float) $request->fresh()->total_value, 2))->toBe(5000.0);
});

it('refuses removing a line from an approved request', function () {
    $request = prfRequest(5000);
    app(PurchaseRequestService::class)->approve($request, null, $this->senior);

    expect(fn () => $request->fresh()->lines()->first()->delete())->toThrow(DomainException::class);
    expect($request->fresh()->lines()->count())->toBe(1);
});

it('still lets a REQUESTED request be built and corrected', function () {
    // The control the three refusals need: without it they would pass just as happily if lines
    // were frozen outright, which would make the module unusable.
    $request = prfRequest(5000);

    expect(fn () => $request->lines()->create([
        'description' => 'Gaskets', 'quantity' => 2, 'unit_cost' => 250,
    ]))->not->toThrow(DomainException::class);

    expect(round((float) $request->fresh()->total_value, 2))->toBe(5500.0);

    $line = $request->fresh()->lines()->first();
    expect(fn () => $line->update(['unit_cost' => 6000]))->not->toThrow(DomainException::class);
    expect(fn () => $line->delete())->not->toThrow(DomainException::class);
});

it('does not block the approval itself', function () {
    // The transition reads the request's status, so approving must not trip the line guard on
    // its way through.
    $request = prfRequest(5000);

    $approved = app(PurchaseRequestService::class)->approve($request, null, $this->senior);

    expect($approved->status)->toBe(PurchaseRequest::STATUS_APPROVED);
});
