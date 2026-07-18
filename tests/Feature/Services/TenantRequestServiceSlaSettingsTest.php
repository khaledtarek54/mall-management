<?php

use App\Services\TenantRequestService;
use App\Settings\MaintenanceSettings;
use Illuminate\Support\Carbon;

beforeEach(fn () => Carbon::setTestNow('2026-06-15 12:00:00'));
afterEach(fn () => Carbon::setTestNow());

it('reads SLA hours from MaintenanceSettings when present', function () {
    $settings = app(MaintenanceSettings::class);
    $settings->sla_urgent_hours = 2;
    $settings->sla_high_hours = 12;
    $settings->sla_medium_hours = 48;
    $settings->sla_low_hours = 96;
    $settings->save();

    $service = app(TenantRequestService::class);

    expect($service->defaultTargetResolution('urgent')->diffInHours(now(), false))->toEqual(-2);
    expect($service->defaultTargetResolution('high')->diffInHours(now(), false))->toEqual(-12);
    expect($service->defaultTargetResolution('medium')->diffInHours(now(), false))->toEqual(-48);
    expect($service->defaultTargetResolution('low')->diffInHours(now(), false))->toEqual(-96);
});

it('falls back to config when given a priority not in the Settings shape', function () {
    config(['maintenance.sla.weird' => ['resolve_hours' => 999]]);

    $service = app(TenantRequestService::class);

    expect($service->defaultTargetResolution('weird')->diffInHours(now(), false))->toEqual(-999);
});

it('falls back to a final 168h default if neither Settings nor config defines the priority', function () {
    $service = app(TenantRequestService::class);

    expect($service->defaultTargetResolution('nonsense')->diffInHours(now(), false))->toEqual(-168);
});
