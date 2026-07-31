<?php

use App\Models\Violation;
use App\Services\SendViolationNoticeAction;
use Illuminate\Support\Facades\Notification;

/**
 * FR-REQ-17 — sending a violation notice is idempotent. A repeat click (or a crafted re-dispatch)
 * on an already-notified violation is a no-op that reports success, rather than re-notifying the
 * tenant and re-stamping `notified_at`. A partial failure leaves `notified_at` null, so a genuine
 * retry after a failed send still runs (that path is documented, not re-tested here).
 */
it('sends a violation notice once, then is idempotent on repeat', function () {
    Notification::fake();
    $violation = Violation::create([
        'asset_id' => makeAsset()->id,
        'tenant_id' => makeTenant()->id,
        'description' => 'Blocked fire exit',
        'violation_date' => now()->toDateString(),
    ]);
    $svc = app(SendViolationNoticeAction::class);

    // First send: delivered + stamped.
    expect($svc->handle($violation))->toBeTrue();
    $stampedAt = $violation->fresh()->notified_at;
    expect($stampedAt)->not->toBeNull();

    // Second call: idempotent success — the guard returns BEFORE the send/stamp, so notified_at is
    // unchanged (a re-send would re-stamp it with a fresh now()).
    expect($svc->handle($violation->fresh()))->toBeTrue()
        ->and($violation->fresh()->notified_at->equalTo($stampedAt))->toBeTrue();
});

it('treats a missing tenant as a safe no-op (not a crash, nothing stamped)', function () {
    Notification::fake();
    $violation = Violation::create([
        'asset_id' => makeAsset()->id,
        'tenant_id' => makeTenant()->id,
        'description' => 'x',
        'violation_date' => now()->toDateString(),
    ]);
    trashBypassingDeletionPolicy($violation->tenant); // tenant gone

    expect(app(SendViolationNoticeAction::class)->handle($violation->fresh()))->toBeFalse()
        ->and($violation->fresh()->notified_at)->toBeNull();
});
