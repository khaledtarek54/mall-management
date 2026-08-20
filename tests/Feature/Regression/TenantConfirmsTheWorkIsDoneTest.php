<?php

/*
|--------------------------------------------------------------------------
| The job was closed by the party paid to close it (2026-08-20)
|--------------------------------------------------------------------------
| Close-out step 4. ServiceChannel §6: *"A tenant confirming completion is a control, not a
| courtesy. It is what stops a job being closed by the person who was paid to do it."* Scenario S7
| is the shape of the failure — a drain partially cleared, marked done, and the shop floods two days
| later during trading hours.
|
| **What already existed, checked before building anything:** `resolved → closed` and
| `resolved → in_progress` were both legal transitions, `requests:auto-close` already closed a
| resolved request after the configured window, and a tenant could already RATE one. The gap was
| narrower than "no confirmation concept" — the tenant could give feedback after the fact and could
| not ACCEPT OR DISPUTE the resolution itself.
*/

use App\Models\TenantRequest;
use App\Models\TenantUser;
use App\Notifications\TenantRequestStatusChangedNotification;
use App\Services\TenantRequestService;
use Database\Seeders\RolesPermissionsSeeder;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    $this->seed(RolesPermissionsSeeder::class);
    ensureAllPropertiesAsset();
    $this->asset = makeAsset();
    $this->unit = makeUnit($this->asset);
    $this->tenant = makeTenant();
    makeLease($this->unit, $this->tenant);

    $this->tenantUser = TenantUser::create([
        'tenant_id' => $this->tenant->id, 'name' => 'Ahmed Hassan',
        'email' => 'ahmed'.uniqid().'@shop.test', 'password' => bcrypt('password'), 'is_admin' => true,
    ]);

    $this->svc = app(TenantRequestService::class);
});

function resolvedRequest($ctx, array $attrs = []): TenantRequest
{
    return TenantRequest::create(array_merge([
        'reference' => 'MR-'.uniqid(),
        'tenant_id' => $ctx->tenant->id,
        'unit_id' => $ctx->unit->id,
        'title' => 'Blocked drain',
        'description' => 'Water backing up in the kitchen.',
        'category' => 'plumbing',
        'status' => 'resolved',
        'priority' => 'high',
        'resolution_notes' => 'Drain rodded and flushed.',
        'submitted_at' => now()->subDays(2),
    ], $attrs));
}

/* ---- accepting ---------------------------------------------------------- */

it('closes the request when the tenant accepts it, and records who did', function () {
    $request = resolvedRequest($this);

    $out = $this->svc->confirmResolution($request, $this->tenantUser);

    expect($out->status)->toBe('closed')
        ->and($out->confirmed_at)->not->toBeNull()
        ->and($out->confirmed_by_tenant_user_id)->toBe($this->tenantUser->id)
        // A confirmation nobody signed is the same evidence as no confirmation at all.
        ->and($out->confirmedBy->name)->toBe('Ahmed Hassan')
        ->and($out->confirmedByTenant())->toBeTrue();
});

/* ---- disputing ---------------------------------------------------------- */

/** The control the benchmark is actually about: the tenant can send it back. */
it('puts a disputed request back with the operator', function () {
    $request = resolvedRequest($this);

    $out = $this->svc->disputeResolution($request, $this->tenantUser, 'It flooded again the next morning.');

    expect($out->status)->toBe('in_progress')
        ->and($out->confirmedByTenant())->toBeFalse();
});

/**
 * The reason reaches the engineer, on the thread where they read everything else about the job.
 * A column would be overwritten by a second dispute; the thread keeps both.
 */
it('puts the tenant\'s own words on the comment thread', function () {
    $request = resolvedRequest($this);

    $this->svc->disputeResolution($request, $this->tenantUser, 'It flooded again the next morning.');

    expect($request->comments()->latest('id')->value('body'))
        ->toContain('It flooded again the next morning');
});

/** "Not fixed" alone sends an engineer back knowing no more than the first time. */
it('refuses a dispute with no reason', function () {
    $request = resolvedRequest($this);

    expect(fn () => $this->svc->disputeResolution($request, $this->tenantUser, '   '))
        ->toThrow(ValidationException::class);

    expect($request->fresh()->status)->toBe('resolved');
});

/** A request disputed after an earlier confirmation must not still read as accepted. */
it('clears an earlier confirmation when the tenant disputes again', function () {
    $request = resolvedRequest($this);
    $this->svc->confirmResolution($request, $this->tenantUser);

    // The operator resolves it a second time after re-work.
    $request->fresh()->forceFill(['status' => 'resolved'])->save();

    $out = $this->svc->disputeResolution($request->fresh(), $this->tenantUser, 'Still leaking.');

    expect($out->confirmed_at)->toBeNull()
        ->and($out->confirmed_by_tenant_user_id)->toBeNull();
});

/* ---- when the question may be asked ------------------------------------- */

/**
 * Confirming is a control BEFORE closure. Once a request is shut there is nothing left to control —
 * which is why `CONFIRMABLE` is deliberately narrower than `RATEABLE`, where a tenant may still
 * leave feedback on a closed job.
 */
it('refuses to confirm a request that is not resolved', function () {
    foreach (['submitted', 'in_progress', 'closed', 'cancelled'] as $status) {
        $request = resolvedRequest($this, ['status' => $status]);

        expect(fn () => $this->svc->confirmResolution($request, $this->tenantUser))
            ->toThrow(ValidationException::class, null, "confirming a {$status} request");
    }

    // The control: the one status that IS open to a decision.
    expect($this->svc->confirmResolution(resolvedRequest($this), $this->tenantUser)->status)->toBe('closed');
});

/* ---- what the operator can tell afterwards ------------------------------ */

/**
 * `requests:auto-close` takes silence as consent, which is the right default — chasing a retailer
 * for a click is how a queue of "resolved" requests never closes. But a close nobody confirmed must
 * not LOOK like one somebody did, and that question was unanswerable before this shipped.
 */
it('distinguishes a close the tenant confirmed from one the timer made', function () {
    $confirmed = resolvedRequest($this);
    $this->svc->confirmResolution($confirmed, $this->tenantUser);

    $timedOut = resolvedRequest($this, ['submitted_at' => now()->subDays(60)]);
    $timedOut->forceFill(['resolved_at' => now()->subDays(30)])->save();

    $this->artisan('requests:auto-close', ['--days' => 7])->assertExitCode(0);

    expect($confirmed->fresh()->status)->toBe('closed')
        ->and($confirmed->fresh()->confirmedByTenant())->toBeTrue()
        // Closed by the timer — and visibly not by anybody.
        ->and($timedOut->fresh()->status)->toBe('closed')
        ->and($timedOut->fresh()->confirmedByTenant())->toBeFalse();
});

/*
|--------------------------------------------------------------------------
| Review pass (2026-08-20)
|--------------------------------------------------------------------------
*/

/**
 * **Do not tell somebody what they just did.** `transition()` notifies the requesting tenant on
 * every move, with a carve-out for `cancelled` because the tenant triggers that themselves.
 * Confirming and disputing are the same case, and without the same carve-out a tenant who clicks
 * "confirm" is immediately notified that their request was closed — which is how people learn to
 * ignore the bell.
 *
 * The operator IS still told about a dispute: it arrives as the tenant's comment on the thread.
 */
it('does not notify the tenant about their own confirmation or dispute', function () {
    Notification::fake();

    $this->svc->confirmResolution(resolvedRequest($this), $this->tenantUser);
    $this->svc->disputeResolution(resolvedRequest($this), $this->tenantUser, 'Still leaking.');

    Notification::assertNotSentTo(
        [$this->tenant],
        TenantRequestStatusChangedNotification::class,
    );
});

/**
 * The control: an OPERATOR-driven move still notifies the tenant, or the carve-out would have
 * silenced the thing it exists to protect.
 */
it('still notifies the tenant when the operator moves the request', function () {
    Notification::fake();

    // Any operator-driven move will do; `submitted → acknowledged` avoids the completion-evidence
    // guard that (correctly) refuses a resolve with no photo and no linked work order.
    $request = resolvedRequest($this, ['status' => 'submitted']);
    $this->svc->transition($request, 'acknowledged');

    Notification::assertSentTo(
        [$this->tenant],
        TenantRequestStatusChangedNotification::class,
    );
});
