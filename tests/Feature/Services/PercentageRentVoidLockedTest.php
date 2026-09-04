<?php

use App\Models\Charge;
use App\Models\InvoiceItem;
use App\Models\TenantSalesDeclaration;
use App\Services\PercentageRentCalculationService;
use Database\Seeders\RolesPermissionsSeeder;

beforeEach(function () {
    $this->seed(RolesPermissionsSeeder::class);
    ensureAllPropertiesAsset();
    $this->asset = makeAsset();
    $this->tenant = makeTenant();
    $this->unit = makeUnit($this->asset);
    $this->lease = makeLease($this->unit, $this->tenant, [
        'status' => 'active',
        'has_percentage_rent' => true,
        'percentage_rent_threshold' => 100000,
        'percentage_rent_rate' => 5.0,
        'percentage_rent_calculation_type' => 'artificial',
    ]);
    $this->operator = makeUser('manager', [$this->asset->id]);

    $this->declaration = TenantSalesDeclaration::create([
        'lease_id' => $this->lease->id,
        'period_start' => now()->startOfMonth()->subMonth(),
        'period_end' => now()->startOfMonth()->subDay(),
        'declared_sales' => 200000,
        'declared_at' => now()->subDays(5),
        'declared_by_type' => $this->tenant::class,
        'declared_by_id' => $this->tenant->id,
        'status' => 'submitted',
    ]);

    // Lock it so we can test voiding from the locked state.
    app(PercentageRentCalculationService::class)->lock($this->declaration, $this->operator, 'Initial lock');
    $this->declaration->refresh();
});

it('voidLocked cancels the immediate overage invoice and flips status to disputed', function () {
    // The overage is billed at lock time as its own immediate invoice; the Charge is
    // an INACTIVE traceability anchor, so the money signal lives on the invoice.
    $charge = Charge::where('lease_id', $this->lease->id)
        ->where('type', 'percentage_rent')
        ->first();
    expect($charge)->not->toBeNull();
    expect((bool) $charge->is_active)->toBeFalse();
    expect($this->declaration->status)->toBe('locked');

    $invoice = InvoiceItem::where('charge_id', $charge->id)->latest('id')->first()?->invoice;
    expect($invoice)->not->toBeNull();
    expect($invoice->status)->toBe('issued');

    app(PercentageRentCalculationService::class)
        ->voidLocked($this->declaration, $this->operator, 'Tenant disputed sales figure');

    expect($this->declaration->fresh()->status)->toBe('disputed');
    expect($charge->fresh()->end_date)->not->toBeNull();
    // The overage invoice is reversed (cancelled) so the GL entry is voided by the sweep.
    expect($invoice->fresh()->status)->toBe('cancelled');
});

it('voidLocked appends the reason to audit_notes behind the house [VOID] marker', function () {
    // REWRITTEN 2026-09-04 (SW-173). This asserted that the operator's NAME appears in the column,
    // which is what the frozen English sentence `"Voided on {date} by {name}: {reason}"` put there.
    // A row stores DATA, never PROSE: the column keeps the operator's own words behind a
    // language-neutral marker, and the WHO and the WHEN are in `activity_log` — see
    // `ADeclarationVoidIsRecordedAsDataNotProseTest`.
    app(PercentageRentCalculationService::class)
        ->voidLocked($this->declaration, $this->operator, 'Ledger error in Tenant accounting');

    $notes = $this->declaration->fresh()->audit_notes;
    expect($notes)->toContain('[VOID] Ledger error in Tenant accounting')
        // …and the note the LOCK wrote is still there — the append discipline is unchanged.
        ->and($notes)->toContain('Initial lock');
});

it('voidLocked is a no-op on a non-locked declaration (idempotency guard)', function () {
    $sibling = TenantSalesDeclaration::create([
        'lease_id' => $this->lease->id,
        'period_start' => now()->startOfMonth()->subMonths(2),
        'period_end' => now()->startOfMonth()->subMonth()->subDay(),
        'declared_sales' => 50000,
        'declared_at' => now(),
        'declared_by_type' => $this->tenant::class,
        'declared_by_id' => $this->tenant->id,
        'status' => 'submitted',
    ]);

    app(PercentageRentCalculationService::class)
        ->voidLocked($sibling, $this->operator, 'cannot void a submitted declaration');

    expect($sibling->fresh()->status)->toBe('submitted');
});

it('voidLocked only reverses the period-specific overage, leaving sibling-period invoices alone', function () {
    // Sibling period — its own locked declaration + anchor charge + immediate invoice.
    $siblingDeclaration = TenantSalesDeclaration::create([
        'lease_id' => $this->lease->id,
        'period_start' => now()->startOfMonth()->subMonths(2),
        'period_end' => now()->startOfMonth()->subMonth()->subDay(),
        'declared_sales' => 300000,
        'declared_at' => now()->subDays(40),
        'declared_by_type' => $this->tenant::class,
        'declared_by_id' => $this->tenant->id,
        'status' => 'submitted',
    ]);
    app(PercentageRentCalculationService::class)->lock($siblingDeclaration, $this->operator, 'sibling lock');
    $siblingCharge = Charge::where('lease_id', $this->lease->id)
        ->where('type', 'percentage_rent')
        ->whereDate('start_date', $siblingDeclaration->period_start)
        ->first();
    expect($siblingCharge)->not->toBeNull();
    $siblingInvoice = InvoiceItem::where('charge_id', $siblingCharge->id)->latest('id')->first()?->invoice;
    expect($siblingInvoice?->status)->toBe('issued');

    app(PercentageRentCalculationService::class)
        ->voidLocked($this->declaration, $this->operator, 'void only the original period');

    // The sibling period's overage invoice is untouched — only the target period was reversed.
    expect($siblingInvoice->fresh()->status)->toBe('issued');
});
