<?php

use App\Services\TenantRequestService;
use Illuminate\Validation\ValidationException;

/**
 * CSAT rating lives in the service so the portal action and the mobile API
 * share one rule: only resolved/closed requests are rateable, score 1–5.
 */
beforeEach(fn () => $this->svc = app(TenantRequestService::class));

it('records a rating + comment on a resolved request', function () {
    $request = makeTenantRequest(['status' => 'resolved']);

    $this->svc->rate($request, 5, '  Great work  ');

    expect($request->fresh()->csat_rating)->toBe(5)
        ->and($request->fresh()->csat_comment)->toBe('Great work'); // trimmed
});

it('refuses to rate a request that is not resolved/closed', function () {
    $request = makeTenantRequest(['status' => 'in_progress']);

    expect(fn () => $this->svc->rate($request, 4))->toThrow(ValidationException::class);
    expect($request->fresh()->csat_rating)->toBeNull();
});

it('clamps an out-of-range score into 1–5', function () {
    $request = makeTenantRequest(['status' => 'closed']);

    $this->svc->rate($request, 9);
    expect($request->fresh()->csat_rating)->toBe(5);

    $this->svc->rate($request, 0);
    expect($request->fresh()->csat_rating)->toBe(1);
});

it('stores an empty comment as null', function () {
    $request = makeTenantRequest(['status' => 'resolved']);

    $this->svc->rate($request, 3, '   ');

    expect($request->fresh()->csat_comment)->toBeNull();
});
