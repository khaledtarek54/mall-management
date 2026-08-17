<?php

use App\Models\Asset;
use App\Models\CreditNote;
use App\Models\Department;
use App\Models\Invoice;
use App\Models\Lease;
use App\Models\MarketingBudget;
use App\Models\MeterReading;
use App\Models\OwnerRequest;
use App\Models\Payment;
use App\Models\Tenant;
use App\Models\TenantRequest;
use App\Models\TenantSalesDeclaration;
use App\Models\TenantUser;
use App\Models\Unit;
use App\Models\UtilityMeter;
use App\Models\Vendor;

/**
 * Plan 2 (QA) — every model factory must build a fully-valid, persistable
 * record on its own (required FKs satisfied via sub-factories). This is the
 * guard that keeps the factories honest as the schema evolves.
 */
dataset('factoried_models', [
    Asset::class,
    Unit::class,
    Tenant::class,
    TenantUser::class,
    Department::class,
    Vendor::class,
    Lease::class,
    Invoice::class,
    Payment::class,
    CreditNote::class,
    TenantRequest::class,
    TenantSalesDeclaration::class,
    OwnerRequest::class,
    MeterReading::class,
    UtilityMeter::class,
    MarketingBudget::class,
]);

it('builds a valid persistable record from its factory', function (string $modelClass) {
    seedRoles();

    $model = $modelClass::factory()->create();

    expect($model->exists)->toBeTrue()
        ->and($modelClass::whereKey($model->getKey())->exists())->toBeTrue();
})->with('factoried_models');
