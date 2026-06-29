<?php

use App\Enums\TenantRequestType;
use App\Models\TenantRequest;

/**
 * Plan 1, Phase 1 (additive): the request-type foundation. Every legacy
 * maintenance request stays typed 'maintenance'; the enum carries the per-type
 * intake config that later phases (form/routing/SLA) consume.
 */
it('exposes the seven request types with localized options', function () {
    expect(TenantRequestType::values())->toBe([
        'maintenance', 'complaint', 'inquiry', 'access', 'billing', 'document', 'other',
    ]);

    expect(TenantRequestType::options())
        ->toHaveKey('maintenance', 'Maintenance')
        ->toHaveKey('complaint', 'Complaint');

    expect(TenantRequestType::default())->toBe(TenantRequestType::Maintenance);
});

it('carries per-type intake config', function () {
    $maintenance = TenantRequestType::Maintenance;
    expect($maintenance->hasSla())->toBeTrue()
        ->and($maintenance->slaHours())->toBe(['urgent' => 4, 'high' => 24, 'medium' => 72, 'low' => 168])
        ->and($maintenance->subcategories())->toContain('electrical', 'plumbing')
        ->and($maintenance->allowsScheduling())->toBeTrue()
        ->and($maintenance->defaultDepartmentSlug())->toBe('operations')
        ->and($maintenance->referencePrefix())->toBe('MR');

    $inquiry = TenantRequestType::Inquiry;
    expect($inquiry->hasSla())->toBeFalse()
        ->and($inquiry->slaHours())->toBe([])
        ->and($inquiry->subcategories())->toBe([])
        ->and($inquiry->allowsScheduling())->toBeFalse();
});

it('defaults every existing maintenance request to the maintenance type', function () {
    $request = makeMaintenanceRequest();

    expect($request->fresh()->request_type)->toBe(TenantRequestType::Maintenance);
});

it('persists and casts a non-maintenance request type', function () {
    $request = makeMaintenanceRequest(['request_type' => 'complaint']);

    expect($request->fresh()->request_type)->toBe(TenantRequestType::Complaint);
});
