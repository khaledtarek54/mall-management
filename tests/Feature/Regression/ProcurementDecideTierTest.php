<?php

use App\Models\PurchaseRequest;
use App\Services\PurchaseRequestService;
use Database\Seeders\ApprovalRulesSeeder;
use Database\Seeders\RolesPermissionsSeeder;

/**
 * Regression — gap-analysis **F-102 / F-103** (module 29): the approval ladder must gate every
 * verb `procurement.decide` covers, not just two of them.
 *
 * THE BUG. `approve()` and `reject()` re-checked the value against `ApprovalPolicy` — `reject()`
 * with an explicit rationale: *"Refusing is as much an act of authority as approving: whoever
 * cannot approve a 50,000 request should not be able to block it either."* **`cancel()` and
 * `order()` checked the base permission only.** So the tier was on 2 of the 4 verbs, and a manager
 * who could not approve a 50,000 purchase could still:
 *   - **cancel** one — the other refusal path, same origin state, same terminal outcome, and
 *     reachable even after a tier_3 senior had approved AND ordered it, unwinding a commitment
 *     they could not have made; or
 *   - **place the order** — the act that actually commits the mall's money — while the doc claims
 *     *"whoever may place the order is exactly whoever may approve it."*
 *
 * The check was duplicated verbatim in the two verbs that had it, which is what let the other two
 * diverge. It is now one method, four callers.
 *
 * Round-2 pattern, seventh instance: **a guard that already existed, one branch away.**
 */
beforeEach(function () {
    $this->seed(RolesPermissionsSeeder::class);
    $this->seed(ApprovalRulesSeeder::class);

    $this->asset = makeAsset(['code' => 'PDT']);
    $this->svc = app(PurchaseRequestService::class);

    // A manager: holds procurement.decide + tier_2, but NOT tier_3.
    $this->manager = makeUser('manager', [$this->asset->id]);
    // A senior who genuinely may authorise 50,000.
    $this->senior = makeUser('super_admin', [$this->asset->id]);
    // A third party raises the request — approving your own is separately blocked, and this
    // test is about the TIER, not that rule.
    $this->buyer = makeUser('operations', [$this->asset->id]);
});

/**
 * A 50,000 purchase — above the tier_2 band, so only a tier_3 holder may decide it.
 *
 * The line is not decoration: `total_value` is DERIVED from the lines by recomputeTotal(), and
 * approve() refuses a request with none (FR-PROC-01). A header carrying 50,000 with no lines is a
 * state the product cannot produce — the same fixture trap the F-84 tests had.
 */
function pdtRequestFor(float $value): PurchaseRequest
{
    $request = PurchaseRequest::create([
        'asset_id' => test()->asset->id,
        'reference' => 'PR-PDT-'.uniqid(),
        'status' => PurchaseRequest::STATUS_REQUESTED,
        'justification' => 'Chiller overhaul parts',
        'requested_by_user_id' => test()->buyer->id, // a third party — self-approval is a separate rule
    ]);

    $request->lines()->create([
        'description' => 'Compressor assembly',
        'quantity' => 1,
        'unit_cost' => $value,
    ]);

    return $request->refresh(); // the line's saved() hook derived total_value + required_permission
}

function pdtBigRequest(): PurchaseRequest
{
    return pdtRequestFor(50000);
}

it('refuses a cancel by someone who could not approve the amount', function () {
    $request = pdtBigRequest();

    expect(fn () => $this->svc->cancel($request, 'Not needed', $this->manager))
        ->toThrow(DomainException::class);

    expect($request->fresh()->status)->toBe(PurchaseRequest::STATUS_REQUESTED);
});

it('refuses a cancel of a purchase a senior already approved and ordered', function () {
    // The sharpest version: unwinding a commitment the actor could never have made.
    $request = pdtBigRequest();
    $this->svc->approve($request, null, $this->senior);
    $this->svc->order($request->fresh(), null, 'PO-1', $this->senior);

    expect(fn () => $this->svc->cancel($request->fresh(), 'Changed my mind', $this->manager))
        ->toThrow(DomainException::class);

    expect($request->fresh()->status)->toBe(PurchaseRequest::STATUS_ORDERED);
});

it('refuses an order by someone who could not approve the amount', function () {
    // Ordering is what commits the money to the supplier.
    $request = pdtBigRequest();
    $this->svc->approve($request, null, $this->senior);

    expect(fn () => $this->svc->order($request->fresh(), null, 'PO-2', $this->manager))
        ->toThrow(DomainException::class);

    expect($request->fresh()->status)->toBe(PurchaseRequest::STATUS_APPROVED);
});

it('still lets an authorised senior cancel and order', function () {
    // The guard must refuse the unauthorised WITHOUT breaking the real workflow.
    $request = pdtBigRequest();
    $this->svc->approve($request, null, $this->senior);

    $ordered = $this->svc->order($request->fresh(), null, 'PO-3', $this->senior);
    expect($ordered->status)->toBe(PurchaseRequest::STATUS_ORDERED);

    $cancelled = $this->svc->cancel($ordered->fresh(), 'Supplier withdrew', $this->senior);
    expect($cancelled->status)->toBe(PurchaseRequest::STATUS_CANCELLED);
});

it('still lets a manager decide an amount within their own tier', function () {
    // The ladder must not become "seniors only" — a manager's own band still works, on all
    // four verbs.
    $small = pdtRequestFor(500); // tier_1 band

    $this->svc->approve($small, null, $this->manager);
    $this->svc->order($small->fresh(), null, 'PO-4', $this->manager);

    expect($small->fresh()->status)->toBe(PurchaseRequest::STATUS_ORDERED)
        ->and($this->svc->cancel($small->fresh(), 'Duplicate', $this->manager)->status)
        ->toBe(PurchaseRequest::STATUS_CANCELLED);
});

it('gates every verb the decide permission covers', function () {
    // The structural point: the tier check was on 2 of 4. If someone adds a fifth decide verb,
    // this is the reminder that the permission alone is not the gate.
    $source = file_get_contents((new ReflectionClass(PurchaseRequestService::class))->getFileName());

    expect(substr_count($source, 'assertMayDecideValue'))->toBe(5); // 1 definition + 4 callers
});
