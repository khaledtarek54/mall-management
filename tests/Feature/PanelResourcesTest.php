<?php

use App\Filament\Owner\Resources\Invoices\InvoiceResource as OwnerInvoiceResource;
use App\Filament\Owner\Resources\TenantRequests\TenantRequestResource as OwnerMRResource;
use App\Filament\Owner\Resources\Properties\PropertyResource as OwnerPropertyResource;
use App\Filament\Portal\Resources\Invoices\InvoiceResource as PortalInvoiceResource;
use App\Filament\Portal\Resources\TenantRequests\TenantRequestResource as PortalMRResource;
use App\Filament\Portal\Resources\Payments\PaymentResource as PortalPaymentResource;
use App\Filament\Portal\Resources\TenantSalesDeclarations\TenantSalesDeclarationResource as PortalTSDResource;
use App\Models\Asset;
use App\Models\TenantRequest;
use App\Models\Payment;
use App\Models\TenantSalesDeclaration;
use App\Models\User;

beforeEach(function () {
    ensureAllPropertiesAsset();
    $this->asset = makeAsset();
    $this->unit = makeUnit($this->asset, ['status' => 'occupied']);
    $this->tenant = makeTenant();
    $this->lease = makeLease($this->unit, $this->tenant);
});

/* ─────────────── Owner panel ─────────────── */

it('Owner InvoiceResource is scoped to the owner via asset.owners pivot', function () {
    $owner = User::create(['name' => 'Owner A', 'email' => 'owner@a.test', 'password' => bcrypt('x')]);
    $strangerOwner = User::create(['name' => 'Owner B', 'email' => 'owner@b.test', 'password' => bcrypt('x')]);

    $this->asset->owners()->attach($owner->id, ['ownership_percentage' => 100, 'started_at' => now()]);

    $mine = makeInvoice($this->lease);

    // Different asset, different owner — must not be visible.
    $otherAsset = makeAsset();
    $otherUnit = makeUnit($otherAsset);
    $otherLease = makeLease($otherUnit);
    $otherAsset->owners()->attach($strangerOwner->id, ['ownership_percentage' => 100, 'started_at' => now()]);
    $strangerInvoice = makeInvoice($otherLease);

    $this->actingAs($owner);

    $ids = OwnerInvoiceResource::getEloquentQuery()->pluck('id')->all();
    expect($ids)->toContain($mine->id);
    expect($ids)->not->toContain($strangerInvoice->id);
});

it('Owner PropertyResource excludes All Properties + filters to owned assets', function () {
    $owner = User::create(['name' => 'Owner', 'email' => 'o@p.test', 'password' => bcrypt('x')]);
    $this->asset->owners()->attach($owner->id, ['ownership_percentage' => 100, 'started_at' => now()]);

    // Make a stranger asset (not owned).
    $other = makeAsset();

    $this->actingAs($owner);

    $codes = OwnerPropertyResource::getEloquentQuery()->pluck('code')->all();
    expect($codes)->toContain($this->asset->code);
    expect($codes)->not->toContain($other->code);
    expect($codes)->not->toContain(Asset::ALL_PROPERTIES_CODE);
});

it('Owner TenantRequestResource scoped via asset.owners', function () {
    $owner = User::create(['name' => 'Owner', 'email' => 'mo@p.test', 'password' => bcrypt('x')]);
    $this->asset->owners()->attach($owner->id, ['ownership_percentage' => 100, 'started_at' => now()]);

    $mr = TenantRequest::create([
        'reference' => 'MR-' . uniqid(),
        'unit_id' => $this->unit->id, 'tenant_id' => $this->tenant->id,
        'title' => 't', 'description' => 'd',
        'status' => 'submitted', 'priority' => 'medium', 'category' => 'hvac',
        'submitted_at' => now(),
    ]);

    $this->actingAs($owner);

    $ids = OwnerMRResource::getEloquentQuery()->pluck('id')->all();
    expect($ids)->toContain($mr->id);
});

it('Owner resources disable create/edit/delete', function () {
    expect(OwnerInvoiceResource::canCreate())->toBeFalse();
    expect(OwnerInvoiceResource::canEdit(null))->toBeFalse();
    expect(OwnerInvoiceResource::canDelete(null))->toBeFalse();

    expect(OwnerPropertyResource::canCreate())->toBeFalse();
    expect(OwnerPropertyResource::canEdit(null))->toBeFalse();
    expect(OwnerPropertyResource::canDelete(null))->toBeFalse();
});

/* ─────────────── Portal panel (auth guard: portal) ─────────────── */

it('Portal InvoiceResource filters to invoices the authenticated tenant owns', function () {
    $mine = makeInvoice($this->lease);
    $otherTenant = makeTenant();
    $otherLease = makeLease($this->unit, $otherTenant);
    $strangerInvoice = makeInvoice($otherLease);

    auth('portal')->login(makeTenantUser($this->tenant));

    $ids = PortalInvoiceResource::getEloquentQuery()->pluck('id')->all();
    expect($ids)->toContain($mine->id);
    expect($ids)->not->toContain($strangerInvoice->id);
});

it('Portal PaymentResource scoped via Auth::guard(portal)', function () {
    $payment = Payment::create([
        'tenant_id' => $this->tenant->id,
        'reference' => 'P-' . uniqid(),
        'amount' => 500,
        'method' => 'cash',
        'status' => 'captured',
        'currency' => 'EGP',
        'payment_date' => now(),
    ]);
    $strangerTenant = makeTenant();
    $strangerPay = Payment::create([
        'tenant_id' => $strangerTenant->id,
        'reference' => 'P-' . uniqid(),
        'amount' => 200, 'method' => 'cash', 'status' => 'captured',
        'currency' => 'EGP', 'payment_date' => now(),
    ]);

    auth('portal')->login(makeTenantUser($this->tenant));

    $ids = PortalPaymentResource::getEloquentQuery()->pluck('id')->all();
    expect($ids)->toContain($payment->id);
    expect($ids)->not->toContain($strangerPay->id);
});

it('Portal TenantRequestResource scoped via Auth::guard(portal); canCreate=true', function () {
    $mine = TenantRequest::create([
        'reference' => 'MR-' . uniqid(),
        'unit_id' => $this->unit->id, 'tenant_id' => $this->tenant->id,
        'title' => 't', 'description' => 'd',
        'status' => 'submitted', 'priority' => 'medium', 'category' => 'hvac',
        'submitted_at' => now(),
    ]);

    auth('portal')->login(makeTenantUser($this->tenant));

    $ids = PortalMRResource::getEloquentQuery()->pluck('id')->all();
    expect($ids)->toContain($mine->id);
    expect(PortalMRResource::canCreate())->toBeTrue();
    expect(PortalMRResource::canEdit(null))->toBeFalse();
    expect(PortalMRResource::canDelete(null))->toBeFalse();
});

it('Portal TenantSalesDeclarationResource scoped via lease.tenant_id', function () {
    $tsd = TenantSalesDeclaration::create([
        'lease_id' => $this->lease->id,
        'period_start' => now()->startOfMonth(),
        'period_end' => now()->endOfMonth(),
        'declared_sales' => 10000, 'declared_at' => now(),
        'status' => 'submitted',
    ]);

    auth('portal')->login(makeTenantUser($this->tenant));

    $ids = PortalTSDResource::getEloquentQuery()->pluck('id')->all();
    expect($ids)->toContain($tsd->id);
    expect(PortalTSDResource::canCreate())->toBeTrue();
    expect(PortalTSDResource::canEdit(null))->toBeFalse();
    expect(PortalTSDResource::canDelete(null))->toBeFalse();
});
