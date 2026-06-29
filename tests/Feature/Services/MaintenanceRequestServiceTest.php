<?php

use App\Services\TenantRequestService;

beforeEach(function () {
    $this->asset = makeAsset();
    $this->unit = makeUnit($this->asset);
    $this->tenant = makeTenant();
    $this->lease = makeLease($this->unit, $this->tenant);
});

it('creates a request with a reference, SLA target, and submitted status', function () {
    $request = app(TenantRequestService::class)->create([
        'title' => 'AC broken',
        'description' => 'No cooling on the ground floor',
        'priority' => 'high',
        'category' => 'hvac',
    ], $this->tenant);

    expect($request->status)->toBe('submitted');
    expect($request->reference)->toStartWith('MR-');
    expect($request->target_resolution_at)->not->toBeNull();
    expect($request->submitted_at)->not->toBeNull();
});

it('rejects illegal status transitions', function () {
    $request = app(TenantRequestService::class)->create([
        'title' => 'X', 'description' => 'Y', 'priority' => 'medium', 'category' => 'other',
    ], $this->tenant);

    app(TenantRequestService::class)->transition($request, 'closed');
})->throws(InvalidArgumentException::class, 'Illegal transition');

it('walks the happy path: submitted → acknowledged → in_progress → resolved → closed', function () {
    $svc = app(TenantRequestService::class);

    $request = $svc->create([
        'title' => 'X', 'description' => 'Y', 'priority' => 'medium', 'category' => 'other',
    ], $this->tenant);

    $svc->transition($request, 'acknowledged');
    expect($request->fresh()->acknowledged_at)->not->toBeNull();

    $svc->transition($request, 'in_progress');
    $svc->transition($request, 'resolved', ['resolution_notes' => 'Fixed.']);
    expect($request->fresh()->resolved_at)->not->toBeNull();
    expect($request->fresh()->resolution_notes)->toBe('Fixed.');

    $svc->transition($request, 'closed');
    expect($request->fresh()->closed_at)->not->toBeNull();
    expect($request->fresh()->status)->toBe('closed');
});

it('assigns a user and auto-acknowledges from submitted', function () {
    $svc = app(TenantRequestService::class);
    $assignee = makeUser('operations');

    $request = $svc->create([
        'title' => 'X', 'description' => 'Y', 'priority' => 'low', 'category' => 'other',
    ], $this->tenant);

    $svc->assign($request, $assignee->id);

    expect($request->fresh()->status)->toBe('acknowledged');
    expect($request->fresh()->assigned_to)->toBe($assignee->id);
});

it('logs internal vs tenant-visible comments', function () {
    $svc = app(TenantRequestService::class);
    $author = makeUser('operations');

    $request = $svc->create([
        'title' => 'X', 'description' => 'Y', 'priority' => 'low', 'category' => 'other',
    ], $this->tenant);

    $internal = $svc->comment($request, $author, 'Awaiting parts', isInternal: true);
    $public = $svc->comment($request, $author, 'We are on it', isInternal: false);

    expect($internal->is_internal)->toBeTrue();
    expect($public->is_internal)->toBeFalse();
});

it('derives target resolution time from SLA config by priority', function () {
    config([
        'maintenance.sla.urgent.resolve_hours' => 4,
        'maintenance.sla.low.resolve_hours' => 168,
    ]);
    $svc = app(TenantRequestService::class);

    $urgent = $svc->defaultTargetResolution('urgent');
    $low = $svc->defaultTargetResolution('low');

    expect(abs($urgent->diffInHours(now())))->toBeGreaterThanOrEqual(3.99)
        ->toBeLessThanOrEqual(4.01);
    expect(abs($low->diffInHours(now())))->toBeGreaterThanOrEqual(167.99)
        ->toBeLessThanOrEqual(168.01);
});
