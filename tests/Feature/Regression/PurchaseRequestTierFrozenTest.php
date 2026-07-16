<?php

use App\Models\ApprovalRule;
use App\Models\PurchaseRequest;
use App\Services\PurchaseRequestService;
use Database\Seeders\ApprovalRulesSeeder;
use Database\Seeders\RolesPermissionsSeeder;

/**
 * Regression — gap-analysis **F-104** (module 29): `required_permission` was NULL on every
 * production row, and a request for nothing was approvable.
 *
 * THE BUG. `PurchaseRequestService::request()` freezes the tier and refuses an empty request. The
 * Filament create page never calls it — and couldn't in that shape: **the create form collects no
 * lines**, they are added afterwards through the relation manager, so the service's
 * header-plus-lines signature never fitted the UI. `request()` had no production caller at all.
 * Net effect:
 *
 *  - **`required_permission` NULL on 100% of real rows.** `pr_pending_queue_index` on
 *    `(status, required_permission)` indexed a permanently null column, and the list table rendered
 *    its `unknown` fallback — "Needs a higher authority" — on every request, including a 500 EGP one
 *    needing only a supervisor.
 *  - **FR-PROC-01's "item(s)" unenforced**: a header with no lines has total 0, which lands in the
 *    tier_1 band and was duly approvable.
 *
 * THE FIX FITS THE UI RATHER THAN THE AUDIT'S SUGGESTION. Routing create through `request()` was the
 * proposed remedy and is wrong — the form has no lines to give it. Instead the tier is derived where
 * the total already is (`recomputeTotal()`, which the line's `saved()`/`deleted()` hooks call), and
 * the "must have item(s)" rule is enforced at `approve()` — the first moment the lines are settled,
 * which the create page by definition is not.
 */
beforeEach(function () {
    $this->seed(RolesPermissionsSeeder::class);
    $this->seed(ApprovalRulesSeeder::class);

    $this->asset = makeAsset(['code' => 'PTF']);
    $this->svc = app(PurchaseRequestService::class);
    $this->buyer = makeUser('operations', [$this->asset->id]);
    $this->senior = makeUser('super_admin', [$this->asset->id]);
});

/** A header exactly as the Filament create page makes one — no lines, no service. */
function ptfHeader(): PurchaseRequest
{
    return PurchaseRequest::create([
        'asset_id' => test()->asset->id,
        'reference' => 'PR-PTF-'.uniqid(),
        'justification' => 'Parts for the chiller',
        'requested_by_user_id' => test()->buyer->id,
    ]);
}

it('freezes the tier as lines are added — not only when the service creates it', function () {
    $request = ptfHeader();

    // A header alone has no value, so no tier is owed yet.
    expect((float) $request->total_value)->toBe(0.0);

    // A line arrives through the relation manager — the ONLY way the product adds one.
    $request->lines()->create(['description' => 'Compressor', 'quantity' => 1, 'unit_cost' => 50000]);

    expect((float) $request->fresh()->total_value)->toBe(50000.0)
        ->and($request->fresh()->required_permission)
        ->toBe(ApprovalRule::TIER_3, 'the record must say who was SUPPOSED to approve it');
});

it('re-derives the tier when the lines change', function () {
    $request = ptfHeader();
    $line = $request->lines()->create(['description' => 'Filters', 'quantity' => 1, 'unit_cost' => 500]);

    expect($request->fresh()->required_permission)->toBe(ApprovalRule::TIER_1);

    // The buyer adds the compressor they forgot — the tier owed rises with the value.
    $request->lines()->create(['description' => 'Compressor', 'quantity' => 1, 'unit_cost' => 50000]);
    expect($request->fresh()->required_permission)->toBe(ApprovalRule::TIER_3);

    // ...and falls again if it is removed. The frozen answer tracks the request while it is open.
    $request->lines()->where('id', '!=', $line->id)->get()->each->delete();
    expect($request->fresh()->required_permission)->toBe(ApprovalRule::TIER_1);
});

it('stops re-deriving once the request leaves requested', function () {
    // After a decision the tier is history: it records the policy in force when it was raised.
    // (approve() judges the CURRENT total anyway — that is the guard against "approved at 500,
    // quietly becomes 50,000".)
    $request = ptfHeader();
    $request->lines()->create(['description' => 'Filters', 'quantity' => 1, 'unit_cost' => 500]);

    $this->svc->approve($request->fresh(), null, $this->senior);
    expect($request->fresh()->status)->toBe(PurchaseRequest::STATUS_APPROVED)
        ->and($request->fresh()->required_permission)->toBe(ApprovalRule::TIER_1);

    // A line changing now must not rewrite the record's account of who should have signed it.
    $request->lines()->create(['description' => 'Compressor', 'quantity' => 1, 'unit_cost' => 50000]);

    expect($request->fresh()->required_permission)
        ->toBe(ApprovalRule::TIER_1, 'the frozen tier is history once decided');
});

it('refuses to approve a request for nothing', function () {
    // FR-PROC-01. A header with no lines has total 0 → the tier_1 band → previously approvable.
    $request = ptfHeader();

    expect(fn () => $this->svc->approve($request, null, $this->senior))
        ->toThrow(DomainException::class);

    expect($request->fresh()->status)->toBe(PurchaseRequest::STATUS_REQUESTED);
});

it('still approves a request that has items', function () {
    // The guard must refuse the empty one WITHOUT breaking the real workflow.
    $request = ptfHeader();
    $request->lines()->create(['description' => 'Filters', 'quantity' => 2, 'unit_cost' => 250]);

    expect($this->svc->approve($request->fresh(), null, $this->senior)->status)
        ->toBe(PurchaseRequest::STATUS_APPROVED);
});
