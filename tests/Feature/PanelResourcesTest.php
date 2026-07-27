<?php

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
