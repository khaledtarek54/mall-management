<?php

/**
 * Plan 2 (QA) — every model factory must build a fully-valid, persistable
 * record on its own (required FKs satisfied via sub-factories). This is the
 * guard that keeps the factories honest as the schema evolves.
 */
dataset('factoried_models', [
    App\Models\Asset::class,
    App\Models\Unit::class,
    App\Models\Tenant::class,
    App\Models\TenantUser::class,
    App\Models\Department::class,
    App\Models\Vendor::class,
    App\Models\Lease::class,
    App\Models\Invoice::class,
    App\Models\Payment::class,
    App\Models\CreditNote::class,
    App\Models\TenantRequest::class,
    App\Models\TenantSalesDeclaration::class,
    App\Models\OwnerRequest::class,
    App\Models\MeterReading::class,
    App\Models\UtilityMeter::class,
    App\Models\MarketingBudget::class,
]);

it('builds a valid persistable record from its factory', function (string $modelClass) {
    seedRoles();

    $model = $modelClass::factory()->create();

    expect($model->exists)->toBeTrue()
        ->and($modelClass::whereKey($model->getKey())->exists())->toBeTrue();
})->with('factoried_models');
