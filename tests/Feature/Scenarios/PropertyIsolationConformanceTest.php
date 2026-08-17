<?php

use App\Filament\Admin\Resources\Announcements\AnnouncementResource;
use App\Filament\Admin\Resources\Areas\AreaResource;
use App\Filament\Admin\Resources\BankAccounts\BankAccountResource;
use App\Filament\Admin\Resources\CamExpensePools\CamExpensePoolResource;
use App\Filament\Admin\Resources\Concerns\BypassesFilamentTenantAutoScope;
use App\Filament\Admin\Resources\Concerns\BypassesScopingOnAll;
use App\Filament\Admin\Resources\Concerns\ScopesViaProperty;
use App\Filament\Admin\Resources\CreditNotes\CreditNoteResource;
use App\Filament\Admin\Resources\Custodies\CustodyResource;
use App\Filament\Admin\Resources\Departments\DepartmentResource;
use App\Filament\Admin\Resources\DepositTransactions\DepositTransactionResource;
use App\Filament\Admin\Resources\Employees\EmployeeResource;
use App\Filament\Admin\Resources\Equipment\EquipmentResource;
use App\Filament\Admin\Resources\Expenses\ExpenseResource;
use App\Filament\Admin\Resources\FacilityWorkOrders\FacilityWorkOrderResource;
use App\Filament\Admin\Resources\FixedAssets\FixedAssetResource;
use App\Filament\Admin\Resources\Invoices\InvoiceResource;
use App\Filament\Admin\Resources\JournalEntries\JournalEntryResource;
use App\Filament\Admin\Resources\Leases\LeaseResource;
use App\Filament\Admin\Resources\MarketingPosts\MarketingPostResource;
use App\Filament\Admin\Resources\OwnerRequests\OwnerRequestResource;
use App\Filament\Admin\Resources\Payments\PaymentResource;
use App\Filament\Admin\Resources\Payrolls\PayrollResource;
use App\Filament\Admin\Resources\PostDatedCheques\PostDatedChequeResource;
use App\Filament\Admin\Resources\PurchaseRequests\PurchaseRequestResource;
use App\Filament\Admin\Resources\RentableItems\RentableItemResource;
use App\Filament\Admin\Resources\ServicePlans\ServicePlanResource;
use App\Filament\Admin\Resources\SlaPolicies\SlaPolicyResource;
use App\Filament\Admin\Resources\TenantRequests\TenantRequestResource;
use App\Filament\Admin\Resources\TenantSalesDeclarations\TenantSalesDeclarationResource;
use App\Filament\Admin\Resources\UnitOwnerships\UnitOwnershipResource;
use App\Filament\Admin\Resources\Units\UnitResource;
use App\Filament\Admin\Resources\UtilityMeters\UtilityMeterResource;
use App\Filament\Admin\Resources\VendorBills\VendorBillResource;
use App\Filament\Admin\Resources\Violations\ViolationResource;
use App\Filament\Admin\Resources\Warehouses\WarehouseResource;
use App\Models\AccountMapping;
use App\Models\Area;
use App\Models\Asset;
use App\Models\Department;
use App\Models\DepositTransaction;
use App\Models\Expense;
use App\Models\JournalEntry;
use App\Models\Payroll;
use App\Models\VendorBill;
use App\Support\PropertyIsolation;
use Database\Seeders\RolesPermissionsSeeder;
use Filament\Facades\Filament;
use Filament\Resources\Resource;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;

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
                && is_subclass_of($c, Resource::class))
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
            // Direct-asset_id resources whose form exposes an editable asset_id Select (enabled +
            // client-supplied in All-Properties mode). All opt OUT of Filament auto-tenancy
            // (BypassesFilamentTenantAutoScope) so the operator's picked mall is not clobbered to
            // the ALL pseudo-asset on create, then re-validate it via assertAssetInScope on
            // create + edit (see Test D + the AllPropertiesCreatePinsAsset regression).
            'Unit' => UnitResource::class,
            'CamExpensePool' => CamExpensePoolResource::class,
            'UtilityMeter' => UtilityMeterResource::class,
            'Equipment' => EquipmentResource::class,
            'Area' => AreaResource::class,
            // A unit sale is recorded against a mall the operator picks, and the unit Select reads
            // that FORM value rather than the panel's current property — so both the asset and the
            // unit under it are client-supplied and re-validated on create + edit.
            'UnitOwnership' => UnitOwnershipResource::class,
            'RentableItem' => RentableItemResource::class,
            'Violation' => ViolationResource::class,
            'SlaPolicy' => SlaPolicyResource::class,
            'Employee' => EmployeeResource::class,
            'FixedAsset' => FixedAssetResource::class,
            'Warehouse' => WarehouseResource::class,
            'BankAccount' => BankAccountResource::class,
            'FacilityWorkOrder' => FacilityWorkOrderResource::class,
            'ServicePlan' => ServicePlanResource::class,
            'PurchaseRequest' => PurchaseRequestResource::class,
            // Still auto-stamped (isScopedToTenant=true): no asset_id form field — Custody derives
            // its property from the chosen employee, so the create page guards employee->asset_id.
            'Custody' => CustodyResource::class,
            // Create-only (immutable after broadcast); the property Select is
            // client-supplied in All-Properties mode, so the create page guards it.
            'Announcement' => AnnouncementResource::class,
            // Same shape as Announcement — the operator PICKS which mall a shopper-facing post
            // runs in, so asset_id is client-supplied and auto-tenancy is off. Unlike
            // Announcement it is editable after creation, so the guard is on create AND save
            // (Filament stamps asset_id on create only).
            'MarketingPost' => MarketingPostResource::class,
            // Not auto-stamped (isScopedToTenant=false): asset_id / lease is client-supplied.
            'Expense' => ExpenseResource::class,
            'VendorBill' => VendorBillResource::class,
            'PostDatedCheque' => PostDatedChequeResource::class,
            'Payroll' => PayrollResource::class,
            'JournalEntry' => JournalEntryResource::class,
            'OwnerRequest' => OwnerRequestResource::class,
            // Asset derived from a client-supplied lease (guard validates the lease's property).
            'DepositTransaction' => DepositTransactionResource::class,
            'CreditNote' => CreditNoteResource::class,
            // Chain-derived: the property comes from a client-supplied lease/unit/invoice FK.
            'Invoice' => InvoiceResource::class,
            'Lease' => LeaseResource::class,
            'TenantSalesDeclaration' => TenantSalesDeclarationResource::class,
            'TenantRequest' => TenantRequestResource::class,
            'Payment' => PaymentResource::class,
            // Hybrid (nullable asset_id): global rows visible to all; property-scoped ones guarded.
            'Department' => DepartmentResource::class,
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
    $acknowledged = [AccountMapping::class];

    foreach (PropertyIsolation::sharedModels() as $model) {
        if (in_array($model, $acknowledged, true)) {
            continue;
        }
        $table = (new $model)->getTable();
        expect(Schema::hasColumn($table, 'asset_id'))
            ->toBeFalse("{$model} ({$table}) is classified SHARED but has an asset_id column — "
                .'it is likely per-property and belongs in OWNED (else its resource escapes the scoping gate)');
    }
});

// A dashboard widget must scope by TenantScope::visibleAssetIds() (or applyTo), NOT
// currentAssetId() — the latter is null in "All Properties" mode, so a restricted
// multi-property user would see the whole portfolio's KPIs (the 2026-07 adversarial
// sweep found five widgets doing exactly this). Comments mentioning currentAssetId()
// are fine; a real call is not.
it('confirms no dashboard widget scopes by currentAssetId()', function () {
    $offenders = [];

    foreach (glob(app_path('Filament/Admin/Widgets/*.php')) as $file) {
        foreach (file($file) as $i => $line) {
            $trimmed = ltrim($line);
            if ($trimmed === '' || str_starts_with($trimmed, '//') || str_starts_with($trimmed, '*')) {
                continue; // skip blank + comment lines
            }
            if (str_contains($line, 'currentAssetId(')) {
                $offenders[] = basename($file).':'.($i + 1);
            }
        }
    }

    expect($offenders)->toBe(
        [],
        'Widgets scoping by currentAssetId() leak the whole portfolio to a restricted user in '
            .'All-Properties mode — use TenantScope::visibleAssetIds(): '.implode(', ', $offenders),
    );
});

// ---- Test D: no create form clobbers a picked asset_id in All-Properties mode --------------
//
// A resource that (a) exposes an editable, dehydrated `asset_id` on its CREATE form AND (b) still
// relies on Filament's auto-tenancy (`isScopedToTenant() === true`) has the "Announcements tenancy
// trap": Panel::boot() registers a model `creating` hook that force-associates asset_id with the
// current tenant, so in All-Properties mode (tenant = ALL pseudo-asset) the operator's PICKED mall is
// silently overwritten and the record vanishes from every real mall's scoped list.
// `BypassesScopingOnAll` does NOT save it — it neutralises only the read scope, not `isScopedToTenant()`.
//
// The fix is the AnnouncementResource pattern: BypassesFilamentTenantAutoScope (isScopedToTenant() ===
// false, so the hook never registers) + a manual getEloquentQuery() scoping reads. So: any owned
// create form whose asset_id is editable + dehydrated MUST have isScopedToTenant() === false.
// See docs/PROPERTY-ISOLATION.md and tests/Feature/Regression/AllPropertiesCreatePinsAssetTest.php.
it('opts every editable-asset_id create form out of Filament tenant auto-stamp (All-mode clobber)', function () {
    $this->seed(RolesPermissionsSeeder::class);
    ensureAllPropertiesAsset();
    $this->actingAs(makeUser('super_admin'));
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    // Inspect the forms as an operator sees them in All-Properties mode — where the clobber bites.
    Filament::setTenant(Asset::where('code', Asset::ALL_PROPERTIES_CODE)->firstOrFail());

    try {
        $offenders = [];

        foreach (propertyIsolationAdminResources() as $resource) {
            if (! PropertyIsolation::isOwned($resource::getModel())) {
                continue; // shared/global resources have no asset_id to clobber
            }

            // The clobber is create-only (Filament stamps asset_id on create, never on update), so
            // only resources with a Create page can trip it.
            $createRegistration = $resource::getPages()['create'] ?? null;
            if ($createRegistration === null) {
                continue;
            }

            // Render the real create form (record = null) and pull its asset_id component, evaluating
            // its disabled/dehydrated closures the way Filament would — the same live-component
            // introspection PaymentFormPropertyScopeTest / CreditNoteTenantScopeTest use.
            $assetField = Livewire::test($createRegistration->getPage())
                ->instance()
                ->form
                ->getComponent('asset_id');

            // No asset_id field on the form ⇒ the property isn't operator-picked here (e.g. Custody
            // derives it from the chosen employee). Not this gate's concern.
            if ($assetField === null) {
                continue;
            }

            // Editable = the operator can supply it (enabled) AND it is written back (dehydrated).
            $editable = ! $assetField->isDisabled() && $assetField->isDehydrated();

            if ($editable && $resource::isScopedToTenant()) {
                $offenders[] = class_basename($resource);
            }
        }
    } finally {
        Filament::setTenant(null, isQuiet: true);
    }

    expect($offenders)->toBe(
        [],
        'These create forms expose an editable asset_id while STILL using Filament tenant auto-stamp '
            .'(isScopedToTenant() === true), so they clobber the operator\'s chosen mall to the ALL '
            .'pseudo-asset in All-Properties mode (the "Announcements tenancy trap"). Switch each to '
            .'App\\Filament\\Admin\\Resources\\Concerns\\BypassesFilamentTenantAutoScope + a manual '
            .'getEloquentQuery(), drop $tenantOwnershipRelationshipName, and keep its *AssetInScope '
            .'write guard: '.implode(', ', $offenders),
    );
});

// ---- Test F: the nullable-asset rule is pinned, and its ONE reader is named ------------------

it('F: pins which models treat a null asset_id as portfolio-level', function () {
    // `#[PropertyOwned(portfolioRowsWhenNull: true)]` says a null `asset_id` is portfolio-level
    // overhead every property must still see — an operator-wide insurance bill is not hidden
    // because someone picked a mall.
    //
    // WHY THIS NEEDS A GATE OF ITS OWN. `PropertyIsolation::registers()` keeps only the `via`
    // chain, so `owned()` — and with it every other check in this file, `atriom:dump-registries`,
    // the census and the handbook's isolation.json — could not see this flag at all. A model could
    // gain or lose it and the whole build would stay green. Same shape as a register derived from
    // the source it is meant to check, one layer further in.
    //
    // Scoping a hybrid strictly HIDES real rows; scoping a strict model loosely SHOWS it rows
    // belonging to nobody. Neither throws.
    expect(PropertyIsolation::hybridModels())->toBe([
        // Not a money model, and it joined the list on 2026-08-16 rather than being missed: it had
        // declared STRICT ownership while `departments.asset_id` is nullable and DepartmentResource
        // already scoped it as portfolio-wide. The declaration described neither the column nor the
        // resource, so converting that resource on it would have hidden every operator-wide row.
        Department::class,
        DepositTransaction::class,
        Expense::class,
        JournalEntry::class,
        Payroll::class,
        VendorBill::class,
    ], 'The set of models whose null asset_id means "portfolio-level" changed. That is a money decision — confirm it deliberately, then update this list.');

    // A control, so the assertion above cannot pass by the flag being universally true.
    expect(PropertyIsolation::portfolioRowsWhenNull(Area::class))->toBeFalse();
})->group('conformance');

it('F: records that only ONE of the two property-scoping paths can express the nullable rule', function () {
    // ScopesToProperty honours the flag. TenantScope::applyTo() — the other entry point, behind
    // every report and dashboard widget — emits a strict where('asset_id', X) with no way to
    // express the null branch.
    //
    // So an expense recorded against "Consolidated (all)" appears on /admin/expenses and is absent
    // from the weekly-spend report.
    //
    // RULED 2026-08-16 ON THE YARDI STANDARD, AND THE REPORTS ARE RIGHT. In Yardi a property's
    // income statement shows that property's own dimensioned entries; shared cost reaches a P&L by
    // ALLOCATION on a stated basis, never by each property absorbing an unallocated corporate bill.
    // Teaching applyTo() the null branch would put one insurance premium into every mall's fixed
    // cost at full value — the double-count the allocation step exists to prevent. So this test
    // PINS the strictness deliberately rather than tolerating it.
    //
    // The real gap against Yardi is elsewhere: Atriom has no allocation mechanism, so consolidated
    // overhead sits in a bucket no property's P&L ever absorbs. That is a missing feature, and the
    // fix is an allocation basis — not a looser where-clause here.
    $readers = [];

    foreach ([
        'app/Filament/Admin/Resources/Concerns/ScopesToProperty.php',
        'app/Support/TenantScope.php',
    ] as $candidate) {
        if (str_contains((string) file_get_contents(base_path($candidate)), 'portfolioRowsWhenNull')) {
            $readers[] = $candidate;
        }
    }

    expect($readers)->toBe(
        ['app/Filament/Admin/Resources/Concerns/ScopesToProperty.php'],
        'The set of scoping paths honouring portfolioRowsWhenNull changed. If TenantScope::applyTo() now reads it, every report and widget just changed what it counts — confirm that was intended, then update this test.'
    );
})->group('conformance');
