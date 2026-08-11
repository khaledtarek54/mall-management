<?php

/*
|--------------------------------------------------------------------------
| RBAC Authorization Matrix
|--------------------------------------------------------------------------
| The canonical role × resource × action matrix for the admin panel. Every
| assertion drives the REAL Filament gates — Resource::canViewAny()/canCreate()
| under actingAs(), and canEdit($record)/canDelete($record) with a persisted
| record — so the test exercises the same code path the panel does.
|
| Design intent (RolesPermissionsSeeder + RoleGatedActions):
|   super_admin  everything, incl. single delete
|   manager      view/create/edit on all modules, NO delete
|   viewer       read-only (.view) on all
|   owner        read-only (.view) on all
|   leasing      Properties(view) / Units / Tenants / Leases / TenantSales(view)
|   operations   Maintenance / Vendors / UtilityMeters
|   accounting   Invoices / Payments / CreditNotes / CAM / Reports
|   marketing    MarketingBudgets
|   hr           Users / Roles / Departments
|
| Delete is reserved for super_admin project-wide; bulk delete is OFF by
| default (canDeleteAny() === false everywhere).
*/

use App\Filament\Admin\Resources\Assets\AssetResource;
use App\Filament\Admin\Resources\CamExpensePools\CamExpensePoolResource;
use App\Filament\Admin\Resources\CreditNotes\CreditNoteResource;
use App\Filament\Admin\Resources\Departments\DepartmentResource;
use App\Filament\Admin\Resources\Invoices\InvoiceResource;
use App\Filament\Admin\Resources\Leases\LeaseResource;
use App\Filament\Admin\Resources\TenantRequests\TenantRequestResource;
use App\Filament\Admin\Resources\MarketingBudgets\MarketingBudgetResource;
use App\Filament\Admin\Resources\Payments\PaymentResource;
use App\Filament\Admin\Resources\Roles\RoleResource;
use App\Filament\Admin\Resources\Tenants\TenantResource;
use App\Filament\Admin\Resources\TenantSalesDeclarations\TenantSalesDeclarationResource;
use App\Filament\Admin\Resources\Units\UnitResource;
use App\Filament\Admin\Resources\Users\UserResource;
use App\Filament\Admin\Resources\UtilityMeters\UtilityMeterResource;
use App\Filament\Admin\Resources\Vendors\VendorResource;
use App\Models\Department;
use App\Models\MarketingBudget;
use App\Settings\ModulesSettings;
use Spatie\Permission\Models\Role as SpatieRole;
use Database\Seeders\RolesPermissionsSeeder;

beforeEach(function () {
    // Real permission sets — makeUser()'s seedRoles() only creates bare roles.
    $this->seed(RolesPermissionsSeeder::class);
});

/**
 * The representative set of permission-gated (RoleGatedActions) resources.
 * UserResource is intentionally excluded — it bypasses the trait with bespoke
 * super_admin-only gates and is covered in its own block below.
 */
function matrixResources(): array
{
    return [
        'Asset'             => AssetResource::class,
        'Unit'              => UnitResource::class,
        'Tenant'            => TenantResource::class,
        'Lease'             => LeaseResource::class,
        'TenantSales'       => TenantSalesDeclarationResource::class,
        'Invoice'           => InvoiceResource::class,
        'Payment'           => PaymentResource::class,
        'CreditNote'        => CreditNoteResource::class,
        'Cam'               => CamExpensePoolResource::class,
        'Maintenance'       => TenantRequestResource::class,
        'Vendor'            => VendorResource::class,
        'UtilityMeter'      => UtilityMeterResource::class,
        'MarketingBudget'   => MarketingBudgetResource::class,
        'Role'              => RoleResource::class,
        'Department'        => DepartmentResource::class,
    ];
}

/** Assert the view/create gates for $role across the named resources. */
function assertViewCreate(object $test, string $role, array $expected): void
{
    $test->actingAs(makeUser($role));

    $resources = matrixResources();
    foreach ($expected as $key => [$canView, $canCreate]) {
        $resource = $resources[$key];

        expect($resource::canViewAny())
            ->toBe($canView, "{$role} canViewAny({$key})");
        expect($resource::canCreate())
            ->toBe($canCreate, "{$role} canCreate({$key})");
    }
}

/*
|--------------------------------------------------------------------------
| super_admin — everything, plus single delete
|--------------------------------------------------------------------------
*/

it('super_admin can view + create every resource', function () {
    $this->actingAs(makeUser('super_admin'));

    foreach (matrixResources() as $key => $resource) {
        // Department + Role are a fixed/locked set: super_admin still views,
        // but DepartmentResource forbids create by design.
        expect($resource::canViewAny())->toBeTrue("super_admin canViewAny({$key})");
    }

    // Create is allowed everywhere except the locked Department set and the
    // auto-provisioned MarketingBudget (one per property/year — never hand-created).
    foreach (matrixResources() as $key => $resource) {
        if (in_array($key, ['Department', 'MarketingBudget'], true)) {
            expect($resource::canCreate())->toBeFalse("{$key} is auto-managed/fixed — no create");

            continue;
        }
        expect($resource::canCreate())->toBeTrue("super_admin canCreate({$key})");
    }
});

it('super_admin single-deletes a deletable record; money records are never deletable', function () {
    $lease = makeLease(makeUnit(makeAsset()));
    $invoice = makeInvoice($lease);

    $this->actingAs(makeUser('super_admin'));
    // super_admin single-deletes DELETABLE records (delete is super_admin-only, project-wide)…
    expect(LeaseResource::canDelete($lease))->toBeTrue()
        ->and(TenantResource::canDelete($lease->tenant))->toBeTrue()
        // …but a money / audit record is NEVER deletable, not even by super_admin — it is corrected
        // (cancel / void / credit note), not deleted. See App\Support\DeletionPolicy.
        ->and(InvoiceResource::canDelete($invoice))->toBeFalse();
});

/*
|--------------------------------------------------------------------------
| manager — view/create/edit on all, NEVER delete
|--------------------------------------------------------------------------
*/

it('manager can view + create across modules but cannot delete', function () {
    $lease = makeLease(makeUnit(makeAsset()));
    $invoice = makeInvoice($lease);

    $this->actingAs(makeUser('manager'));

    // Representative spread across departments.
    foreach (['Asset', 'Unit', 'Tenant', 'Lease', 'Invoice', 'Payment', 'Cam', 'Maintenance', 'Vendor'] as $key) {
        $resource = matrixResources()[$key];
        expect($resource::canViewAny())->toBeTrue("manager view {$key}")
            ->and($resource::canCreate())->toBeTrue("manager create {$key}");
    }

    // MarketingBudget is auto-provisioned — manager views + manages spends, but no create.
    expect(matrixResources()['MarketingBudget']::canViewAny())->toBeTrue('manager view MarketingBudget')
        ->and(matrixResources()['MarketingBudget']::canCreate())->toBeFalse('budgets are auto-provisioned');

    expect(InvoiceResource::canEdit($invoice))->toBeTrue()
        ->and(InvoiceResource::canDelete($invoice))->toBeFalse('manager must never delete')
        ->and(LeaseResource::canDelete($lease))->toBeFalse()
        ->and(InvoiceResource::canDeleteAny())->toBeFalse('bulk delete is off');
});

/*
|--------------------------------------------------------------------------
| viewer + owner — read-only everywhere
|--------------------------------------------------------------------------
*/

it('viewer can view every module but create/edit/delete nothing', function () {
    $lease = makeLease(makeUnit(makeAsset()));
    $invoice = makeInvoice($lease);

    $this->actingAs(makeUser('viewer'));

    foreach (matrixResources() as $key => $resource) {
        expect($resource::canViewAny())->toBeTrue("viewer view {$key}")
            ->and($resource::canCreate())->toBeFalse("viewer must not create {$key}");
    }

    expect(InvoiceResource::canEdit($invoice))->toBeFalse()
        ->and(InvoiceResource::canDelete($invoice))->toBeFalse()
        ->and(LeaseResource::canEdit($lease))->toBeFalse();
});

/*
 * The owner is NOT a viewer, and this test used to say it was.
 *
 * `viewer` is an internal auditor: an Eltizam role that may read Eltizam's own business. `owner` is
 * the counterparty on the other side of the management contract. Granting it every `.view` in the
 * catalogue handed Jawad the SHARED vendor register (every mall Eltizam runs, including a competing
 * owner's), every staff account, the payroll and the operator's own bank accounts — none of which
 * property isolation touches, because those models carry no `asset_id`.
 *
 * Which modules an owner may read is recorded in `App\Support\OwnerVisibility` and gated by
 * `OwnerVisibilityConformanceTest`. This case checks the resource layer honours it.
 */
it('owner reads its property read-only, and cannot reach the operator\'s own business', function () {
    $lease = makeLease(makeUnit(makeAsset()));
    $invoice = makeInvoice($lease);

    $this->actingAs(makeUser('owner'));

    // Their property, their tenants, their money.
    foreach (['Asset', 'Unit', 'Tenant', 'Lease', 'TenantSales', 'Invoice', 'Payment', 'CreditNote',
        'Cam', 'Maintenance', 'UtilityMeter', 'MarketingBudget'] as $key) {
        $resource = matrixResources()[$key];
        expect($resource::canViewAny())->toBeTrue("owner view {$key}")
            ->and($resource::canCreate())->toBeFalse("owner must not create {$key}");
    }

    // Eltizam's own business. The paired refusal — without it, the loop above passes just as
    // happily on the blanket grant this replaced.
    foreach (['Vendor', 'Role', 'Department'] as $key) {
        $resource = matrixResources()[$key];
        expect($resource::canViewAny())->toBeFalse("owner must not view {$key} — operator-internal");
    }

    expect(InvoiceResource::canEdit($invoice))->toBeFalse('owner is read-only')
        ->and(InvoiceResource::canDelete($invoice))->toBeFalse();
});

/*
|--------------------------------------------------------------------------
| leasing department
|--------------------------------------------------------------------------
*/

it('leasing covers Properties(view) / Units / Tenants / Leases / TenantSales(view) and nothing else', function () {
    assertViewCreate($this, 'leasing', [
        // Owns: leasing group.
        'Asset'       => [true, false],  // properties are view-only for leasing
        'Unit'        => [true, true],
        'Tenant'      => [true, true],
        'Lease'       => [true, true],
        'TenantSales' => [true, false],  // leasing has tenant_sales.view, NOT .create
        // Forbidden: other departments.
        'Invoice'         => [false, false],
        'Payment'         => [false, false],
        'CreditNote'      => [false, false],
        'Cam'             => [false, false],
        'Maintenance'     => [false, false],
        'Vendor'          => [false, false],
        'UtilityMeter'    => [false, false],
        'MarketingBudget' => [false, false],
        'Role'            => [false, false],
        'Department'      => [false, false],
    ]);
});

it('leasing can edit a lease but cannot delete it', function () {
    $lease = makeLease(makeUnit(makeAsset()));

    $this->actingAs(makeUser('leasing'));
    expect(LeaseResource::canEdit($lease))->toBeTrue()
        ->and(LeaseResource::canDelete($lease))->toBeFalse('delete is super_admin only');
});

/*
|--------------------------------------------------------------------------
| operations department
|--------------------------------------------------------------------------
*/

it('operations covers Maintenance / Vendors / UtilityMeters and nothing else', function () {
    assertViewCreate($this, 'operations', [
        'Maintenance'   => [true, true],
        'Vendor'        => [true, true],
        'UtilityMeter'  => [true, true],
        // Forbidden.
        'Asset'           => [false, false],
        'Unit'            => [false, false],
        'Tenant'          => [false, false],
        'Lease'           => [false, false],
        'TenantSales'     => [false, false],
        'Invoice'         => [false, false],
        'Payment'         => [false, false],
        'CreditNote'      => [false, false],
        'Cam'             => [false, false],
        'MarketingBudget' => [false, false],
        'Role'            => [false, false],
        'Department'      => [false, false],
    ]);
});

/*
|--------------------------------------------------------------------------
| accounting department
|--------------------------------------------------------------------------
*/

it('accounting covers Invoices / Payments / CreditNotes / CAM and nothing else', function () {
    assertViewCreate($this, 'accounting', [
        'Invoice'    => [true, true],
        'Payment'    => [true, true],
        'CreditNote' => [true, true],
        'Cam'        => [true, true],
        // Forbidden.
        'Asset'           => [false, false],
        'Unit'            => [false, false],
        'Tenant'          => [false, false],
        'Lease'           => [false, false],
        'TenantSales'     => [false, false],
        'Maintenance'     => [false, false],
        'Vendor'          => [false, false],
        'UtilityMeter'    => [false, false],
        'MarketingBudget' => [false, false],
        'Role'            => [false, false],
        'Department'      => [false, false],
    ]);
});

it('accounting can edit an invoice but not delete it', function () {
    $invoice = makeInvoice(makeLease(makeUnit(makeAsset())));

    $this->actingAs(makeUser('accounting'));
    expect(InvoiceResource::canEdit($invoice))->toBeTrue()
        ->and(InvoiceResource::canDelete($invoice))->toBeFalse();
});

/*
|--------------------------------------------------------------------------
| marketing department
|--------------------------------------------------------------------------
*/

it('marketing covers MarketingBudgets and nothing else', function () {
    assertViewCreate($this, 'marketing', [
        // Marketing views its budgets (+ manages spends) but never creates them —
        // budgets are auto-provisioned per property/year.
        'MarketingBudget' => [true, false],
        // Forbidden.
        'Asset'        => [false, false],
        'Unit'         => [false, false],
        'Tenant'       => [false, false],
        'Lease'        => [false, false],
        'TenantSales'  => [false, false],
        'Invoice'      => [false, false],
        'Payment'      => [false, false],
        'CreditNote'   => [false, false],
        'Cam'          => [false, false],
        'Maintenance'  => [false, false],
        'Vendor'       => [false, false],
        'UtilityMeter' => [false, false],
        'Role'         => [false, false],
        'Department'   => [false, false],
    ]);
});

it('a marketing user is explicitly denied the finance modules (negative spot-check)', function () {
    $this->actingAs(makeUser('marketing'));

    expect(InvoiceResource::canViewAny())->toBeFalse('marketing cannot see invoices')
        ->and(PaymentResource::canViewAny())->toBeFalse()
        ->and(CreditNoteResource::canViewAny())->toBeFalse();
});

it('marketing can edit but not delete a marketing budget', function () {
    $budget = MarketingBudget::create([
        'asset_id' => makeAsset()->id,
        'period_year' => 2026,
        'accrued_amount' => 100000,
        'spent_amount' => 0,
        'status' => 'open',
    ]);

    $this->actingAs(makeUser('marketing'));
    expect(MarketingBudgetResource::canEdit($budget))->toBeTrue()
        ->and(MarketingBudgetResource::canDelete($budget))->toBeFalse();
});

/*
|--------------------------------------------------------------------------
| hr department
|--------------------------------------------------------------------------
*/

it('hr covers Roles + Departments (view) and is shut out of every operational module', function () {
    assertViewCreate($this, 'hr', [
        'Role'       => [true, false], // hr has roles.view only, not roles.create
        'Department' => [true, false], // fixed set + view-only for hr
        // Forbidden: all operational modules.
        'Asset'           => [false, false],
        'Unit'            => [false, false],
        'Tenant'          => [false, false],
        'Lease'           => [false, false],
        'TenantSales'     => [false, false],
        'Invoice'         => [false, false],
        'Payment'         => [false, false],
        'CreditNote'      => [false, false],
        'Cam'             => [false, false],
        'Maintenance'     => [false, false],
        'Vendor'          => [false, false],
        'UtilityMeter'    => [false, false],
        'MarketingBudget' => [false, false],
    ]);
});

/*
|--------------------------------------------------------------------------
| UserResource — HR-manageable Users (permission-gated); delete super_admin-only
|--------------------------------------------------------------------------
| Users is the HR department's resource: access/create/edit gate on
| users.view/create/edit, so hr (and the cross-cutting roles holding those
| perms) manage staff accounts. Delete stays super_admin-only + self-guard.
*/

it('gates Users access/create on users.* permissions (HR-manageable)', function () {
    // roles holding users.view reach the Users list
    foreach (['super_admin', 'manager', 'viewer', 'hr'] as $role) {
        $this->actingAs(makeUser($role));
        expect(UserResource::canAccess())->toBeTrue("{$role} should access Users");
    }

    // `owner` deliberately does NOT. UserResource is SHARED and unscoped, so this was every staff
    // account in the company, not the ones working at their mall (App\Support\OwnerVisibility).
    $this->actingAs(makeUser('owner'));
    expect(UserResource::canAccess())->toBeFalse('owner must not read the operator\'s staff accounts');

    // roles holding users.create can create
    foreach (['super_admin', 'manager', 'hr'] as $role) {
        $this->actingAs(makeUser($role));
        expect(UserResource::canCreate())->toBeTrue("{$role} should create Users");
    }

    // read-only roles can see but not create
    foreach (['viewer', 'owner'] as $role) {
        $this->actingAs(makeUser($role));
        expect(UserResource::canCreate())->toBeFalse("{$role} cannot create Users");
    }

    // department roles without users.* are shut out entirely
    foreach (['leasing', 'operations', 'accounting', 'marketing'] as $role) {
        $this->actingAs(makeUser($role));
        expect(UserResource::canAccess())->toBeFalse("{$role} must not access Users")
            ->and(UserResource::canCreate())->toBeFalse("{$role} must not create Users");
    }
});

it('HR can edit users; delete stays super_admin-only with a self-delete guard', function () {
    $admin = makeUser('super_admin');
    $other = makeUser('viewer');

    $this->actingAs($admin);
    expect(UserResource::canEdit($other))->toBeTrue()
        ->and(UserResource::canDelete($other))->toBeTrue()
        ->and(UserResource::canDelete($admin))->toBeFalse('cannot delete your own account');

    // hr edits users (users.edit) but cannot delete (super_admin-only)
    $this->actingAs(makeUser('hr'));
    expect(UserResource::canEdit($other))->toBeTrue('hr can edit users')
        ->and(UserResource::canDelete($other))->toBeFalse('hr cannot delete users');

    // viewer is read-only: no edit, no delete
    $this->actingAs(makeUser('viewer'));
    expect(UserResource::canEdit($other))->toBeFalse('viewer cannot edit users')
        ->and(UserResource::canDelete($other))->toBeFalse();
});

/*
|--------------------------------------------------------------------------
| Project-wide delete invariants
|--------------------------------------------------------------------------
*/

it('no role — not even super_admin — gets bulk delete (off by default)', function () {
    foreach (['super_admin', 'manager', 'viewer', 'owner', 'accounting', 'leasing', 'operations', 'marketing', 'hr'] as $role) {
        $this->actingAs(makeUser($role));
        expect(InvoiceResource::canDeleteAny())->toBeFalse("{$role} bulk delete must be off")
            ->and(LeaseResource::canDeleteAny())->toBeFalse()
            ->and(TenantResource::canDeleteAny())->toBeFalse();
    }
});

it('every non-super_admin role is denied single delete on a representative record', function () {
    $invoice = makeInvoice(makeLease(makeUnit(makeAsset())));

    foreach (['manager', 'viewer', 'owner', 'accounting', 'leasing', 'operations', 'marketing', 'hr'] as $role) {
        $this->actingAs(makeUser($role));
        expect(InvoiceResource::canDelete($invoice))->toBeFalse("{$role} must not delete an invoice");
    }
});

/*
|--------------------------------------------------------------------------
| Composite gate — module feature flag AND permission
|--------------------------------------------------------------------------
| RoleGatedActions::hasPermission() requires the module to be enabled too. A
| user with the permission is still blocked when the module is switched off.
*/

it('a disabled module overrides the permission grant (credit_notes off → accounting blocked)', function () {
    $this->actingAs(makeUser('accounting'));

    // Module on: accounting sees credit notes.
    expect(CreditNoteResource::canViewAny())->toBeTrue();

    // Turn the toggleable module off.
    $settings = app(ModulesSettings::class);
    $settings->credit_notes = false;
    $settings->save();

    expect(CreditNoteResource::canViewAny())->toBeFalse('module flag gates the resource even with the permission')
        ->and(CreditNoteResource::canCreate())->toBeFalse();
});

/*
|--------------------------------------------------------------------------
| Unauthenticated guard
|--------------------------------------------------------------------------
*/

it('a guest (no authenticated user) is denied everything', function () {
    expect(InvoiceResource::canViewAny())->toBeFalse()
        ->and(InvoiceResource::canCreate())->toBeFalse()
        ->and(AssetResource::canViewAny())->toBeFalse()
        ->and(UserResource::canAccess())->toBeFalse();
});

/*
|--------------------------------------------------------------------------
| Sanity — the locked HR-managed sets behave as designed
|--------------------------------------------------------------------------
*/

it('the Department set is fixed: even super_admin cannot create or delete a department', function () {
    $this->actingAs(makeUser('super_admin'));

    expect(DepartmentResource::canViewAny())->toBeTrue()
        ->and(DepartmentResource::canCreate())->toBeFalse()
        ->and(DepartmentResource::canDeleteAny())->toBeFalse()
        ->and(DepartmentResource::canDelete(new Department(['name' => 'x'])))->toBeFalse();
});

it('roles: super_admin manages, manager cannot edit roles, hr is view-only', function () {
    $role = SpatieRole::firstOrCreate(['name' => 'manager', 'guard_name' => 'web']);

    $this->actingAs(makeUser('super_admin'));
    expect(RoleResource::canViewAny())->toBeTrue()
        ->and(RoleResource::canCreate())->toBeTrue()
        ->and(RoleResource::canEdit($role))->toBeTrue();

    $this->actingAs(makeUser('manager'));
    expect(RoleResource::canViewAny())->toBeTrue('manager can view roles')
        ->and(RoleResource::canCreate())->toBeFalse('manager cannot create roles')
        ->and(RoleResource::canEdit($role))->toBeFalse('manager cannot edit roles');

    $this->actingAs(makeUser('hr'));
    expect(RoleResource::canViewAny())->toBeTrue('hr views roles')
        ->and(RoleResource::canCreate())->toBeFalse('hr cannot create roles')
        ->and(RoleResource::canEdit($role))->toBeFalse('hr cannot edit roles');
});
