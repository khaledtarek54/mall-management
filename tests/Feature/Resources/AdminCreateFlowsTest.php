<?php

use App\Filament\Admin\Resources\CreditNotes\CreditNoteResource;
use App\Filament\Admin\Resources\Invoices\InvoiceResource;
use App\Filament\Admin\Resources\Tenants\TenantResource;
use App\Models\CreditNote;
use App\Models\Invoice;
use App\Models\Tenant;
use Filament\Facades\Filament;

/**
 * Regression coverage for the admin create flows that 500'd when a property
 * (Filament tenant = Asset) was active.
 *
 * Filament registers a model `creating` hook for every tenant-scoped resource.
 * The hook resolves the tenant-ownership relationship (default name: `asset`).
 * Tenant / Invoice / CreditNote have no `asset()` relationship, so the hook
 * threw a LogicException → 500. The bypass traits no-op'd the read scope but
 * left isScopedToTenant() = true, so the hook stayed registered.
 *
 * These tests exercise the exact production path: a model save while the admin
 * panel is current and a property tenant is selected.
 */
beforeEach(function () {
    seedRoles();
    ensureAllPropertiesAsset();

    $this->hw = makeAsset(['code' => 'HW']);
    $this->unit = makeUnit($this->hw, ['code' => 'HW-01']);
    $this->lease = makeLease($this->unit);
    $this->tenant = $this->lease->tenant;

    $this->actingAs(makeUser('super_admin'));

    $panel = Filament::getPanel('admin');
    Filament::setCurrentPanel($panel);
    Filament::setTenant($this->hw);

    // Replicate what Panel::boot() does for a tenancy panel: wire the
    // model `creating` hook that associates the record with the tenant. If a
    // resource is (incorrectly) still tenant-scoped, this registers a hook
    // that resolves a non-existent asset() relationship and throws on save.
    TenantResource::observeTenancyModelCreation($panel);
    InvoiceResource::observeTenancyModelCreation($panel);
    CreditNoteResource::observeTenancyModelCreation($panel);
});

afterEach(function () {
    Filament::setTenant(null, isQuiet: true);
});

it('creates a tenant inside an active property context', function () {
    $tenant = Tenant::create([
        'name' => 'Repro Brand',
        'type' => 'company',
        'status' => 'active',
    ]);

    expect($tenant->exists)->toBeTrue();
});

it('creates an invoice for an existing tenant inside an active property context', function () {
    $invoice = Invoice::create([
        'lease_id' => $this->lease->id,
        'tenant_id' => $this->tenant->id,
        'status' => 'issued',
        'issue_date' => '2026-02-01',
        'due_date' => '2026-02-10',
        'period_start' => '2026-02-01',
        'period_end' => '2026-02-28',
        'subtotal' => 10000,
        'vat_amount' => 1400,
        'total' => 11400,
        'paid_amount' => 0,
        'balance' => 11400,
        'currency' => 'EGP',
    ]);

    expect($invoice->exists)->toBeTrue();
});

it('creates a credit note inside an active property context', function () {
    $note = CreditNote::create([
        'tenant_id' => $this->tenant->id,
        'lease_id' => $this->lease->id,
        'reason' => 'adjustment',
        'status' => 'draft',
        'issue_date' => '2026-02-01',
        'subtotal' => 500,
        'vat_amount' => 0,
        'total' => 500,
        'applied_amount' => 0,
        'balance' => 500,
        'currency' => 'EGP',
    ]);

    expect($note->exists)->toBeTrue();
});
