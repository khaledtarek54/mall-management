<?php

use App\Enums\TenantRequestType;
use App\Models\TenantRequest;
use App\Services\TenantRequestService;

/**
 * A request that ASKED for something has to record whether it was granted.
 *
 * **The bug this closes.** The seven statuses carry no outcome, so the mobile permit card
 * inferred one from the lifecycle — `resolved`/`closed` → "Approved". A staff REJECTION therefore
 * read to the tenant as an approval, on the artifact they hand a security guard. The app could not
 * have done better; nothing on the wire said otherwise.
 */
function decidableRequest(string $type = 'permit'): TenantRequest
{
    $request = makeTenantRequest([
        'request_type' => $type,
        'category' => $type === 'permit' ? 'fit_out' : 'parking',
        'status' => 'in_progress',
    ]);

    // The resolution-evidence gate (FR-USR-06) is separate and already enforced; satisfy it so
    // these cases exercise the DECISION guard rather than tripping over that one.
    $request->addMediaFromString('evidence')
        ->usingFileName('done.jpg')
        ->toMediaCollection('attachments');

    return $request->refresh();
}

it('refuses to resolve a permit without saying yes or no', function () {
    $request = decidableRequest();

    expect(fn () => app(TenantRequestService::class)->transition($request, 'resolved', [
        'resolution_notes' => 'Work inspected.',
    ]))->toThrow(DomainException::class);

    expect($request->refresh()->status)->toBe('in_progress');
});

it('records an approval, who gave it and when', function () {
    $request = decidableRequest();
    $staff = makeUser();

    app(TenantRequestService::class)->transition($request, 'resolved', [
        'resolution_notes' => 'Fit-out approved for the requested window.',
        'decision' => 'approved',
        'decided_by' => $staff->id,
    ]);

    $request->refresh();

    expect($request->decision)->toBe('approved')
        ->and($request->wasApproved())->toBeTrue()
        ->and($request->wasRejected())->toBeFalse()
        ->and($request->decided_at)->not->toBeNull()
        ->and($request->decided_by)->toBe($staff->id)
        // A row that HAS an answer is never "unknown" — that is what the client keys off.
        ->and($request->decisionUnknown())->toBeFalse();
});

it('records a rejection — and this is the case that used to read as approved', function () {
    $request = decidableRequest();

    app(TenantRequestService::class)->transition($request, 'resolved', [
        'resolution_notes' => 'Refused.',
        'decision' => 'rejected',
        'decision_reason' => 'The proposed hoarding blocks a fire exit.',
    ]);

    $request->refresh();

    // Status is identical to the approved case above — which is precisely why inferring the
    // outcome from it was wrong, and why this assertion is the point of the whole change.
    expect($request->status)->toBe('resolved')
        ->and($request->wasRejected())->toBeTrue()
        ->and($request->wasApproved())->toBeFalse()
        ->and($request->decision_reason)->toBe('The proposed hoarding blocks a fire exit.');
});

it('refuses a rejection with no reason', function () {
    $request = decidableRequest();

    // A tenant told only "rejected" resubmits the same request on Monday.
    expect(fn () => app(TenantRequestService::class)->transition($request, 'resolved', [
        'resolution_notes' => 'Refused.',
        'decision' => 'rejected',
    ]))->toThrow(DomainException::class);

    expect($request->refresh()->decision)->toBeNull();
});

it('does not ask a maintenance request for a decision', function () {
    // A leaking pipe is fixed or it is not. Forcing approve/reject here would make the operator
    // pick a word that does not describe what they did.
    $request = makeTenantRequest([
        'request_type' => 'maintenance',
        'category' => 'plumbing',
        'status' => 'in_progress',
    ]);
    $request->addMediaFromString('x')->usingFileName('fixed.jpg')->toMediaCollection('attachments');

    app(TenantRequestService::class)->transition($request->refresh(), 'resolved', [
        'resolution_notes' => 'Seal replaced.',
    ]);

    $request->refresh();

    expect($request->status)->toBe('resolved')
        ->and($request->requiresDecision())->toBeFalse()
        ->and($request->decision)->toBeNull()
        // Null here means "was never a question", NOT "answer unknown".
        ->and($request->decisionUnknown())->toBeFalse();
});

it('flags a legacy resolved permit as unknown, never as approved', function () {
    // Rows written before the column existed. The whole reason `decision` is nullable rather than
    // defaulted: defaulting it to `approved` would have recreated the bug at scale, silently.
    $request = makeTenantRequest([
        'request_type' => 'permit',
        'category' => 'signage',
        'status' => 'closed',
    ]);

    expect($request->decision)->toBeNull()
        ->and($request->requiresDecision())->toBeTrue()
        ->and($request->decisionUnknown())->toBeTrue()
        ->and($request->wasApproved())->toBeFalse();
});

it('asks access and document requests too, but not enquiries or billing', function () {
    expect(TenantRequestType::Permit->requiresDecision())->toBeTrue()
        ->and(TenantRequestType::Access->requiresDecision())->toBeTrue()
        ->and(TenantRequestType::Document->requiresDecision())->toBeTrue()
        ->and(TenantRequestType::Maintenance->requiresDecision())->toBeFalse()
        ->and(TenantRequestType::Complaint->requiresDecision())->toBeFalse()
        ->and(TenantRequestType::Inquiry->requiresDecision())->toBeFalse()
        ->and(TenantRequestType::Billing->requiresDecision())->toBeFalse();
});

it('refuses a decision value outside the set', function () {
    $request = decidableRequest();

    expect(fn () => app(TenantRequestService::class)->transition($request, 'resolved', [
        'resolution_notes' => 'x',
        'decision' => 'maybe',
    ]))->toThrow(InvalidArgumentException::class);
});

it('ships the decision and the permit window to the mobile app', function () {
    $tenant = makeTenant();
    $lease = makeLease(makeUnit(makeAsset()), $tenant);

    $request = makeTenantRequest([
        'tenant_id' => $tenant->id,
        'lease_id' => $lease->id,
        'unit_id' => $lease->unit_id,
        'request_type' => 'permit',
        'category' => 'fit_out',
        'status' => 'resolved',
        'decision' => 'rejected',
        'decision_reason' => 'Blocks a fire exit.',
        'decided_at' => now(),
        // These two columns have existed since 2026-07-18 and were never on the wire, so the app
        // derived a validity from what the TENANT typed while the mall's answer sat unread.
        'valid_from' => '2026-09-01',
        'valid_to' => '2026-09-07',
    ]);

    $this->getJson("/api/v1/me/requests/{$request->id}", apiHeaders($tenant))
        ->assertOk()
        ->assertJsonPath('data.requiresDecision', true)
        ->assertJsonPath('data.decision', 'rejected')
        ->assertJsonPath('data.decisionReason', 'Blocks a fire exit.')
        ->assertJsonPath('data.validFrom', '2026-09-01')
        ->assertJsonPath('data.validTo', '2026-09-07');
});

it('tells the app when a null decision means "never a question"', function () {
    $tenant = makeTenant();
    $lease = makeLease(makeUnit(makeAsset()), $tenant);

    $request = makeTenantRequest([
        'tenant_id' => $tenant->id,
        'lease_id' => $lease->id,
        'unit_id' => $lease->unit_id,
        'request_type' => 'maintenance',
        'category' => 'plumbing',
        'status' => 'resolved',
    ]);

    // `requiresDecision: false` + `decision: null` is how the client distinguishes this from a
    // legacy permit whose answer nobody recorded. Neither may render as approved.
    $this->getJson("/api/v1/me/requests/{$request->id}", apiHeaders($tenant))
        ->assertOk()
        ->assertJsonPath('data.requiresDecision', false)
        ->assertJsonPath('data.decision', null);
});
