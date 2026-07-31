<?php

use App\Services\TenantRequestService;

/**
 * Pre-go-live sweep (terminal-state) — a closed/cancelled tenant request is immutable off the form.
 *
 * The service matrix + isTerminal() refuse comment/assign/redirect on a terminal request, but the
 * generic admin Edit page bypassed the service, so a closed/cancelled request's descriptive +
 * routing fields (title, priority, assignment, dates, resolution_notes…) were still editable. The
 * money-free peer of the VendorBill immutability gap; now a model updating guard freezes them —
 * keyed on the ORIGINAL status so the transition INTO closed is allowed, and post-close CSAT stays
 * allowed (a closed request is still rateable).
 */
it('freezes a closed request\'s descriptive + routing fields off the form', function () {
    $req = makeTenantRequest(['status' => 'closed']);

    expect(fn () => $req->fresh()->update(['title' => 'edited']))->toThrow(DomainException::class);
    expect(fn () => $req->fresh()->update(['priority' => 'high']))->toThrow(DomainException::class);
    expect(fn () => $req->fresh()->update(['resolution_notes' => 'rewritten']))->toThrow(DomainException::class);

    expect($req->fresh()->title)->toBe('Test'); // unchanged after the refused edits
});

it('still lets a CLOSED request be rated — post-close CSAT stays allowed', function () {
    $req = makeTenantRequest(['status' => 'closed']);

    app(TenantRequestService::class)->rate($req, 5, 'great work');

    expect((int) $req->fresh()->csat_rating)->toBe(5)
        ->and($req->fresh()->csat_comment)->toBe('great work');
});

it('freely edits a non-terminal request', function () {
    $req = makeTenantRequest(['status' => 'in_progress']);

    $req->update(['title' => 'edited', 'priority' => 'high']);

    expect($req->fresh()->title)->toBe('edited')
        ->and($req->fresh()->priority)->toBe('high');
});

it('allows the transition INTO closed (keyed on the original status, not the new one)', function () {
    $req = makeTenantRequest(['status' => 'resolved']);

    app(TenantRequestService::class)->transition($req, 'closed');

    expect($req->fresh()->status)->toBe('closed');
});
