<?php

use App\Filament\Admin\Resources\Concerns\BypassesFilamentTenantAutoScope;
use App\Filament\Admin\Resources\Concerns\BypassesScopingOnAll;
use App\Filament\Admin\Resources\Concerns\ScopesViaProperty;
use App\Support\PropertyIsolation;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;

/**
 * The self-enforcing property-isolation gate. These are STRUCTURAL tests: they
 * reflect over the codebase so that a future model or resource which ships
 * unclassified, unscoped, or unguarded FAILS CI instead of silently leaking one
 * property's data into another. See docs/PROPERTY-ISOLATION-PLAN.md §6 (Layer B).
 */

// ---- helpers ---------------------------------------------------------------

if (! function_exists('propertyIsolationModels')) {
    /** @return list<class-string> every concrete Eloquent model in app/Models */
    function propertyIsolationModels(): array
    {
        return collect(glob(app_path('Models/*.php')))
            ->map(fn ($f) => 'App\\Models\\'.basename($f, '.php'))
            ->filter(fn ($c) => class_exists($c)
                && is_subclass_of($c, Model::class)
                && ! (new ReflectionClass($c))->isAbstract())
            ->values()
            ->all();
    }
}

if (! function_exists('propertyIsolationAdminResources')) {
    /** @return list<class-string> every Filament admin Resource */
    function propertyIsolationAdminResources(): array
    {
        $base = app_path('Filament/Admin/Resources');

        return collect(glob($base.'/*/*Resource.php'))
            ->map(function ($f) use ($base) {
                $rel = str_replace([$base.'/', '.php'], '', $f);

                return 'App\\Filament\\Admin\\Resources\\'.str_replace('/', '\\', $rel);
            })
            ->filter(fn ($c) => class_exists($c)
                && is_subclass_of($c, \Filament\Resources\Resource::class))
            ->values()
            ->all();
    }
}

if (! function_exists('propertyIsolationResourceIsScoped')) {
    /** True when a resource applies an EXPLICIT property scope to its table reads. */
    function propertyIsolationResourceIsScoped(string $resource): bool
    {
        $uses = class_uses_recursive($resource);
        if (array_intersect(
            [ScopesViaProperty::class, BypassesScopingOnAll::class, BypassesFilamentTenantAutoScope::class],
            $uses,
        )) {
            return true;
        }

        // A getEloquentQuery() override declared on the resource itself.
        if ((new ReflectionMethod($resource, 'getEloquentQuery'))->getDeclaringClass()->getName() === $resource) {
            return true;
        }

        // An explicit Filament tenant-ownership relationship (direct-FK auto tenancy).
        $prop = (new ReflectionClass($resource))->getProperty('tenantOwnershipRelationshipName');
        $prop->setAccessible(true);

        return $prop->getValue() !== null;
    }
}

if (! function_exists('propertyIsolationMustGuardResources')) {
    /**
     * Resources whose form lets a client supply the property (an editable
     * asset_id Select in All-Properties mode, or a lease-derived asset). Each
     * MUST re-validate on write via assertAssetInScope. Adding a new such
     * resource here is the point of the gate.
     *
     * @return array<string, class-string>
     */
    function propertyIsolationMustGuardResources(): array
    {
        return [
            // Auto-stamped on create (isScopedToTenant=true) but asset_id is editable on
            // EDIT in All-Properties mode (Filament does NOT re-stamp on update).
            'Unit' => \App\Filament\Admin\Resources\Units\UnitResource::class,
            'CamExpensePool' => \App\Filament\Admin\Resources\CamExpensePools\CamExpensePoolResource::class,
            'UtilityMeter' => \App\Filament\Admin\Resources\UtilityMeters\UtilityMeterResource::class,
            'Employee' => \App\Filament\Admin\Resources\Employees\EmployeeResource::class,
            'FixedAsset' => \App\Filament\Admin\Resources\FixedAssets\FixedAssetResource::class,
            'Warehouse' => \App\Filament\Admin\Resources\Warehouses\WarehouseResource::class,
            'Custody' => \App\Filament\Admin\Resources\Custodies\CustodyResource::class,
            'MaintenanceWorkOrder' => \App\Filament\Admin\Resources\MaintenanceWorkOrders\MaintenanceWorkOrderResource::class,
            'MaintenancePlan' => \App\Filament\Admin\Resources\MaintenancePlans\MaintenancePlanResource::class,
            // Not auto-stamped (isScopedToTenant=false): asset_id / lease is client-supplied.
            'Expense' => \App\Filament\Admin\Resources\Expenses\ExpenseResource::class,
            'VendorBill' => \App\Filament\Admin\Resources\VendorBills\VendorBillResource::class,
            'Payroll' => \App\Filament\Admin\Resources\Payrolls\PayrollResource::class,
            'JournalEntry' => \App\Filament\Admin\Resources\JournalEntries\JournalEntryResource::class,
            'OwnerRequest' => \App\Filament\Admin\Resources\OwnerRequests\OwnerRequestResource::class,
            // Asset derived from a client-supplied lease (guard validates the lease's property).
            'DepositTransaction' => \App\Filament\Admin\Resources\DepositTransactions\DepositTransactionResource::class,
            'CreditNote' => \App\Filament\Admin\Resources\CreditNotes\CreditNoteResource::class,
            // Chain-derived: the property comes from a client-supplied lease/unit/invoice FK.
            'Invoice' => \App\Filament\Admin\Resources\Invoices\InvoiceResource::class,
            'Lease' => \App\Filament\Admin\Resources\Leases\LeaseResource::class,
            'TenantSalesDeclaration' => \App\Filament\Admin\Resources\TenantSalesDeclarations\TenantSalesDeclarationResource::class,
            'MaintenanceRequest' => \App\Filament\Admin\Resources\MaintenanceRequests\MaintenanceRequestResource::class,
            'Payment' => \App\Filament\Admin\Resources\Payments\PaymentResource::class,
            // Hybrid (nullable asset_id): global rows visible to all; property-scoped ones guarded.
            'Department' => \App\Filament\Admin\Resources\Departments\DepartmentResource::class,
        ];
    }
}

// ---- Test A: every model is classified ------------------------------------

it('classifies every Eloquent model as shared or property-owned', function () {
    $unclassified = collect(propertyIsolationModels())
        ->reject(fn ($m) => PropertyIsolation::isClassified($m))
        ->values()
        ->all();

    expect($unclassified)->toBe(
        [],
        'Unclassified models — add each to App\\Support\\PropertyIsolation (SHARED or OWNED): '
            .implode(', ', $unclassified),
    );
});

it('confirms every direct property-owned model has an asset_id column', function () {
    foreach (PropertyIsolation::ownedModels() as $model) {
        if (! PropertyIsolation::isDirect($model)) {
            continue;
        }
        $table = (new $model)->getTable();
        expect(Schema::hasColumn($table, 'asset_id'))
            ->toBeTrue("{$model} ({$table}) is registered as direct-FK but has no asset_id column");
    }
});

it('confirms every indirect property-owned model exposes the first hop of its chain', function () {
    foreach (PropertyIsolation::ownedModels() as $model) {
        $chain = PropertyIsolation::linkageFor($model);
        if ($chain === null) {
            continue; // direct-FK
        }
        $firstHop = explode('.', $chain)[0];
        expect(method_exists(new $model, $firstHop))
            ->toBeTrue("{$model} is missing relation '{$firstHop}' for its asset chain '{$chain}'");
    }
});

// ---- Test B: every property-owned resource scopes its table reads ----------

it('scopes the table query of every property-owned admin resource', function () {
    $failures = [];

    foreach (propertyIsolationAdminResources() as $resource) {
        $model = $resource::getModel();
        if (! PropertyIsolation::isOwned($model)) {
            continue; // shared/global resources are intentionally unscoped
        }
        if (! propertyIsolationResourceIsScoped($resource)) {
            $failures[] = $resource;
        }
    }

    expect($failures)->toBe(
        [],
        'Property-owned resources with UNSCOPED table reads (add a scoping trait or getEloquentQuery override): '
            .implode(', ', $failures),
    );
});

// ---- Test C: every must-guard resource wires the write guard ---------------

it('wires the asset-scope write guard on every must-guard resource', function (string $resource) {
    // The guard method must exist (from GuardsAssetInScope or a local copy)...
    expect(method_exists($resource, 'assertAssetInScope'))
        ->toBeTrue("{$resource} must expose assertAssetInScope()");

    // ...and be invoked by its create/edit pages (the write attack surface).
    $pagesDir = dirname((new ReflectionClass($resource))->getFileName()).'/Pages';
    $pages = array_merge(
        glob($pagesDir.'/Create*.php') ?: [],
        glob($pagesDir.'/Edit*.php') ?: [],
    );

    // 'AssetInScope' matches assertAssetInScope AND the FK-derived helpers
    // (assertLeaseAssetInScope / assertUnitAssetInScope / assertInvoiceAssetInScope).
    $guardedPages = array_filter(
        $pages,
        fn ($file) => str_contains((string) file_get_contents($file), 'AssetInScope'),
    );

    expect($guardedPages)->not->toBeEmpty(
        "{$resource} has create/edit pages but none call an *AssetInScope guard (property-isolation write guard)",
    );
})->with(propertyIsolationMustGuardResources());

// Self-enforcement: a NEW not-auto-stamped resource with a client-editable asset_id
// must be registered as must-guard (and thus wired). Filament only stamps asset_id on
// CREATE for isScopedToTenant=true resources, so isScopedToTenant=false + an asset_id
// field means the property is fully client-supplied — the highest-risk write gap.
it('registers every not-auto-stamped owned resource that exposes an editable asset_id', function () {
    $mustGuard = array_values(propertyIsolationMustGuardResources());
    $offenders = [];

    foreach (propertyIsolationAdminResources() as $resource) {
        $model = $resource::getModel();
        if (! PropertyIsolation::isOwned($model)) {
            continue;
        }
        if ($resource::isScopedToTenant()) {
            continue; // Filament force-stamps asset_id on create → not client-supplied there
        }

        // A client-supplied property = a direct asset_id field OR a property-determining
        // FK picker (lease/unit/invoice). Both must be write-guarded.
        $hasClientProperty = false;
        foreach (glob(dirname((new ReflectionClass($resource))->getFileName()).'/Schemas/*Form.php') ?: [] as $formFile) {
            $src = (string) file_get_contents($formFile);
            foreach (["make('asset_id')", "make('lease_id')", "make('unit_id')", "make('invoice_id')"] as $needle) {
                if (str_contains($src, $needle)) {
                    $hasClientProperty = true;
                    break 2;
                }
            }
        }

        if ($hasClientProperty && ! in_array($resource, $mustGuard, true)) {
            $offenders[] = $resource;
        }
    }

    expect($offenders)->toBe(
        [],
        'Not-auto-stamped resources exposing a client-supplied property (asset_id or a '
            .'lease/unit/invoice FK) that are missing from the must-guard set '
            .'(add to propertyIsolationMustGuardResources + wire an *AssetInScope guard): '
            .implode(', ', $offenders),
    );
});

// A per-property model wrongly bucketed as SHARED would be exempted from the table-scoping
// gate (Test B) — a silent read leak (this is exactly how the Department leak hid). Assert
// no SHARED model carries an asset_id column, except acknowledged programmatic config.
it('confirms no SHARED model carries an asset_id column (except acknowledged config)', function () {
    // AccountMapping legitimately has a nullable per-property override column but is
    // resolved programmatically with no list/write surface, so it stays SHARED.
    $acknowledged = [\App\Models\AccountMapping::class];

    foreach (PropertyIsolation::sharedModels() as $model) {
        if (in_array($model, $acknowledged, true)) {
            continue;
        }
        $table = (new $model)->getTable();
        expect(Schema::hasColumn($table, 'asset_id'))
            ->toBeFalse("{$model} ({$table}) is classified SHARED but has an asset_id column — "
                ."it is likely per-property and belongs in OWNED (else its resource escapes the scoping gate)");
    }
});
