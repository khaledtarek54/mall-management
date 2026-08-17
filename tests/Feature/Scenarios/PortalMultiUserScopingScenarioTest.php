<?php

/*
|--------------------------------------------------------------------------
| Feature #9 — Multi-user tenant portal: AUTH context + cross-tenant SCOPING
|--------------------------------------------------------------------------
| Drives the REAL portal Resource::getEloquentQuery() under actingAs(.., 'portal').
| A TenantUser of company A — whether ADMIN or NON-ADMIN — must see ONLY company
| A's records through every portal resource, never company B's. Also asserts the
| App\Support\Portal session helpers (tenantId/tenant/isAdmin) resolve for both
| roles, that two users of the SAME tenant resolve the SAME tenantId, and that a
| logged-out portal resolves null.
|
| Read first: tests/Pest.php, PanelResourcesTest.php, PortalCamAllocationScopingTest.php.
| This file is NET-NEW: it exercises the non-admin scoping path + the Portal
| context helpers (logged-out / two-users-same-tenant) which existing tests skip.
*/

use App\Filament\Portal\Resources\CamAllocations\CamAllocationResource;
use App\Filament\Portal\Resources\Invoices\InvoiceResource;
use App\Filament\Portal\Resources\Payments\PaymentResource;
use App\Filament\Portal\Resources\TenantRequests\TenantRequestResource;
use App\Filament\Portal\Resources\TenantSalesDeclarations\TenantSalesDeclarationResource;
use App\Models\CamAllocation;
use App\Models\CamExpensePool;
use App\Models\Payment;
use App\Models\TenantRequest;
use App\Models\TenantSalesDeclaration;
use App\Support\Portal;

beforeEach(function () {
    ensureAllPropertiesAsset();

    $this->asset = makeAsset();

    // Two distinct companies, each with its own unit + active lease.
    $this->tenantA = makeTenant(['name' => 'Aroma Coffee Co.']);
    $this->tenantB = makeTenant(['name' => 'Borealis Books']);

    $this->unitA = makeUnit($this->asset);
    $this->unitB = makeUnit($this->asset);

    $this->leaseA = makeLease($this->unitA, $this->tenantA, ['status' => 'active']);
    $this->leaseB = makeLease($this->unitB, $this->tenantB, ['status' => 'active']);

    /* ── Invoices ── */
    $this->invoiceA = makeInvoice($this->leaseA);
    $this->invoiceB = makeInvoice($this->leaseB);

    /* ── Payments (scoped by tenant_id) ── */
    $this->paymentA = Payment::create([
        'tenant_id' => $this->tenantA->id,
        'amount' => 500, 'method' => 'cash', 'status' => 'captured',
        'currency' => 'EGP', 'payment_date' => now(),
    ]);
    $this->paymentB = Payment::create([
        'tenant_id' => $this->tenantB->id,
        'amount' => 200, 'method' => 'cash', 'status' => 'captured',
        'currency' => 'EGP', 'payment_date' => now(),
    ]);

    /* ── CAM allocations (scoped via lease.tenant_id) ── */
    $this->pool = CamExpensePool::create([
        'asset_id' => $this->asset->id,
        'period_year' => 2025,
        'total_actual_expense' => 100000,
        'total_estimated_collected' => 90000,
        'status' => 'reconciled',
    ]);
    $this->allocA = CamAllocation::create([
        'cam_expense_pool_id' => $this->pool->id,
        'lease_id' => $this->leaseA->id,
        'pro_rata_share_pct' => 60, 'allocated_amount' => 60000,
        'estimated_paid' => 54000, 'true_up_amount' => 6000, 'status' => 'billed',
    ]);
    $this->allocB = CamAllocation::create([
        'cam_expense_pool_id' => $this->pool->id,
        'lease_id' => $this->leaseB->id,
        'pro_rata_share_pct' => 40, 'allocated_amount' => 40000,
        'estimated_paid' => 36000, 'true_up_amount' => 4000, 'status' => 'billed',
    ]);

    /* ── Maintenance requests (scoped by tenant_id) ── */
    $this->mrA = TenantRequest::create([
        'reference' => 'MR-'.uniqid(),
        'unit_id' => $this->unitA->id, 'tenant_id' => $this->tenantA->id,
        'title' => 'A leak', 'description' => 'd',
        'status' => 'submitted', 'priority' => 'medium', 'category' => 'hvac',
        'submitted_at' => now(),
    ]);
    $this->mrB = TenantRequest::create([
        'reference' => 'MR-'.uniqid(),
        'unit_id' => $this->unitB->id, 'tenant_id' => $this->tenantB->id,
        'title' => 'B leak', 'description' => 'd',
        'status' => 'submitted', 'priority' => 'medium', 'category' => 'hvac',
        'submitted_at' => now(),
    ]);

    /* ── Sales declarations (scoped via lease.tenant_id) ── */
    $this->tsdA = TenantSalesDeclaration::create([
        'lease_id' => $this->leaseA->id,
        'period_start' => now()->startOfMonth(), 'period_end' => now()->endOfMonth(),
        'declared_sales' => 10000, 'declared_at' => now(), 'status' => 'submitted',
    ]);
    $this->tsdB = TenantSalesDeclaration::create([
        'lease_id' => $this->leaseB->id,
        'period_start' => now()->startOfMonth(), 'period_end' => now()->endOfMonth(),
        'declared_sales' => 20000, 'declared_at' => now(), 'status' => 'submitted',
    ]);
});

/*
|--------------------------------------------------------------------------
| SCOPING — every portal resource filters to the logged-in user's company.
| Parameterised across BOTH the admin and the non-admin tenant user: scoping
| is identical regardless of write-role (read access is shared).
|--------------------------------------------------------------------------
*/

dataset('portalRole', [
    'admin user' => [true],
    'non-admin user' => [false],
]);

it('Portal InvoiceResource shows company A its invoice and never company B\'s', function (bool $isAdmin) {
    $this->actingAs(makeTenantUser($this->tenantA, isAdmin: $isAdmin), 'portal');

    $ids = InvoiceResource::getEloquentQuery()->pluck('id')->all();

    expect($ids)->toContain($this->invoiceA->id)
        ->and($ids)->not->toContain($this->invoiceB->id);
})->with('portalRole');

it('Portal PaymentResource shows company A its payment and never company B\'s', function (bool $isAdmin) {
    $this->actingAs(makeTenantUser($this->tenantA, isAdmin: $isAdmin), 'portal');

    $ids = PaymentResource::getEloquentQuery()->pluck('id')->all();

    expect($ids)->toContain($this->paymentA->id)
        ->and($ids)->not->toContain($this->paymentB->id);
})->with('portalRole');

it('Portal CamAllocationResource shows company A its allocation and never company B\'s', function (bool $isAdmin) {
    $this->actingAs(makeTenantUser($this->tenantA, isAdmin: $isAdmin), 'portal');

    $ids = CamAllocationResource::getEloquentQuery()->pluck('id')->all();

    expect($ids)->toContain($this->allocA->id)
        ->and($ids)->not->toContain($this->allocB->id);
})->with('portalRole');

it('Portal TenantRequestResource shows company A its request and never company B\'s', function (bool $isAdmin) {
    $this->actingAs(makeTenantUser($this->tenantA, isAdmin: $isAdmin), 'portal');

    $ids = TenantRequestResource::getEloquentQuery()->pluck('id')->all();

    expect($ids)->toContain($this->mrA->id)
        ->and($ids)->not->toContain($this->mrB->id);
})->with('portalRole');

it('Portal TenantSalesDeclarationResource shows company A its declaration and never company B\'s', function (bool $isAdmin) {
    $this->actingAs(makeTenantUser($this->tenantA, isAdmin: $isAdmin), 'portal');

    $ids = TenantSalesDeclarationResource::getEloquentQuery()->pluck('id')->all();

    expect($ids)->toContain($this->tsdA->id)
        ->and($ids)->not->toContain($this->tsdB->id);
})->with('portalRole');

it('the mirror holds — company B sees only B\'s records across every portal resource', function (bool $isAdmin) {
    $this->actingAs(makeTenantUser($this->tenantB, isAdmin: $isAdmin), 'portal');

    expect(InvoiceResource::getEloquentQuery()->pluck('id')->all())
        ->toContain($this->invoiceB->id)->not->toContain($this->invoiceA->id);
    expect(PaymentResource::getEloquentQuery()->pluck('id')->all())
        ->toContain($this->paymentB->id)->not->toContain($this->paymentA->id);
    expect(CamAllocationResource::getEloquentQuery()->pluck('id')->all())
        ->toContain($this->allocB->id)->not->toContain($this->allocA->id);
    expect(TenantRequestResource::getEloquentQuery()->pluck('id')->all())
        ->toContain($this->mrB->id)->not->toContain($this->mrA->id);
    expect(TenantSalesDeclarationResource::getEloquentQuery()->pluck('id')->all())
        ->toContain($this->tsdB->id)->not->toContain($this->tsdA->id);
})->with('portalRole');

/*
|--------------------------------------------------------------------------
| Portal context helpers — tenantId / tenant / isAdmin resolve per session.
|--------------------------------------------------------------------------
*/

it('Portal::tenantId/tenant resolve to the logged-in user\'s company', function (bool $isAdmin) {
    $user = makeTenantUser($this->tenantA, isAdmin: $isAdmin);
    $this->actingAs($user, 'portal');

    expect(Portal::tenantId())->toBe($this->tenantA->id)
        ->and(Portal::tenant()->is($this->tenantA))->toBeTrue()
        ->and(Portal::user()->is($user))->toBeTrue();
})->with('portalRole');

it('Portal::isAdmin mirrors the user\'s is_admin flag', function () {
    $this->actingAs(makeTenantUser($this->tenantA, isAdmin: true), 'portal');
    expect(Portal::isAdmin())->toBeTrue();

    // Re-authenticating as a non-admin flips the answer for the new session.
    $this->actingAs(makeTenantUser($this->tenantA, isAdmin: false), 'portal');
    expect(Portal::isAdmin())->toBeFalse();
});

it('two users of the SAME tenant resolve the same tenantId and see the same records', function () {
    $admin = makeTenantUser($this->tenantA, isAdmin: true);
    $member = makeTenantUser($this->tenantA, isAdmin: false);

    $this->actingAs($admin, 'portal');
    $adminTenantId = Portal::tenantId();
    $adminInvoiceIds = InvoiceResource::getEloquentQuery()->pluck('id')->sort()->values()->all();

    $this->actingAs($member, 'portal');
    $memberTenantId = Portal::tenantId();
    $memberInvoiceIds = InvoiceResource::getEloquentQuery()->pluck('id')->sort()->values()->all();

    expect($adminTenantId)->toBe($this->tenantA->id)
        ->and($memberTenantId)->toBe($adminTenantId)
        // Same company => identical visible record set, regardless of write-role.
        ->and($memberInvoiceIds)->toEqual($adminInvoiceIds)
        ->and($memberInvoiceIds)->toContain($this->invoiceA->id)
        ->and($memberInvoiceIds)->not->toContain($this->invoiceB->id);
});

it('a logged-out portal resolves a null context', function () {
    // No actingAs — the portal guard has no user.
    expect(Portal::user())->toBeNull()
        ->and(Portal::tenantId())->toBeNull()
        ->and(Portal::tenant())->toBeNull()
        ->and(Portal::isAdmin())->toBeFalse();
});

it('a logged-out portal query (tenant_id = null) matches no tenant\'s records', function () {
    // With Portal::tenantId() === null the where('tenant_id', null) compiles to
    // `tenant_id = ?` bound to null, which matches nothing in SQL — no leakage.
    expect(InvoiceResource::getEloquentQuery()->pluck('id')->all())
        ->not->toContain($this->invoiceA->id)
        ->not->toContain($this->invoiceB->id);

    expect(PaymentResource::getEloquentQuery()->pluck('id')->all())
        ->not->toContain($this->paymentA->id)
        ->not->toContain($this->paymentB->id);
});
