<?php

use App\Models\Charge;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Payment;
use App\Models\TenantSalesDeclaration;
use App\Services\MonthlyBillingService;
use App\Services\PercentageRentCalculationService;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesPermissionsSeeder;

/**
 * REGRESSION — percentage-rent billing gap.
 *
 * The old lock() created an active one_time Charge dated to the (past) sales month and
 * relied on the monthly billing run to pick it up. But the monthly engine only bills
 * charges whose period OVERLAPS the run month, so a charge dated to a bygone month was
 * NEVER billed — locked overage silently vanished (revenue leak).
 *
 * The fix (mirroring the CAM positive true-up) bills the overage IMMEDIATELY as its own
 * issued invoice; the Charge is kept as an INACTIVE traceability anchor. Void/re-lock
 * reverses it by cancelling that invoice (so the GL entry is voided by the sweep). A PAID
 * overage invoice cannot be voided — the operator must refund it first.
 */
beforeEach(function () {
    $this->seed(RolesPermissionsSeeder::class);
    ensureAllPropertiesAsset();
    $this->asset = makeAsset();
    $this->tenant = makeTenant();
    $this->unit = makeUnit($this->asset);
    $this->operator = makeUser('manager', [$this->asset->id]);
    $this->svc = app(PercentageRentCalculationService::class);
});

function billableDeclaration($ctx, float $sales = 100000): TenantSalesDeclaration
{
    $lease = makeLease($ctx->unit, $ctx->tenant, [
        'status' => 'active',
        'has_percentage_rent' => true,
        'percentage_rent_calculation_type' => 'artificial',
        'percentage_rent_threshold' => 50000, // (100000 - 50000) * 5% = 2500 owed
        'percentage_rent_rate' => 5,
    ]);

    return TenantSalesDeclaration::create([
        'lease_id' => $lease->id,
        'period_start' => '2026-03-01',
        'period_end' => '2026-03-31',
        'declared_sales' => $sales,
        'calculated_percentage_rent' => 0,
        'status' => 'submitted',
        'declared_at' => now(),
        'declared_by_type' => $ctx->tenant::class,
        'declared_by_id' => $ctx->tenant->id,
    ]);
}

it('lock bills the overage as an immediate issued invoice (not a stranded monthly charge)', function () {
    $decl = billableDeclaration($this);

    $this->svc->lock($decl, $this->operator);

    // The anchor Charge exists but is INACTIVE — the monthly engine must never re-bill it.
    $charge = Charge::where('lease_id', $decl->lease_id)->where('type', 'percentage_rent')->first();
    expect($charge)->not->toBeNull()
        ->and((bool) $charge->is_active)->toBeFalse()
        ->and((float) $charge->amount)->toBe(2500.0);

    // The money lives on an immediately-issued invoice, dated to the sales period.
    $item = InvoiceItem::where('charge_id', $charge->id)->where('type', 'percentage_rent')->first();
    expect($item)->not->toBeNull();
    $invoice = $item->invoice;
    expect($invoice->status)->toBe('issued')
        ->and((float) $invoice->total)->toBe(2500.0)
        ->and((float) $invoice->balance)->toBe(2500.0)
        ->and($invoice->period_start->toDateString())->toBe('2026-03-01');
});

it('lock bills nothing when the overage is zero (under threshold)', function () {
    $decl = billableDeclaration($this, 40000); // below 50000 threshold → owes 0

    $this->svc->lock($decl, $this->operator);

    expect(Charge::where('lease_id', $decl->lease_id)->where('type', 'percentage_rent')->count())->toBe(0)
        ->and(Invoice::where('tenant_id', $this->tenant->id)->count())->toBe(0);
});

it('voidLocked cancels the immediate overage invoice', function () {
    $decl = billableDeclaration($this);
    $this->svc->lock($decl, $this->operator);

    $invoice = Invoice::whereHas('items', fn ($q) => $q->where('type', 'percentage_rent'))
        ->where('tenant_id', $this->tenant->id)->first();
    expect($invoice->status)->toBe('issued');

    $this->svc->voidLocked($decl->fresh(), $this->operator, 'tenant disputed the figure');

    expect($decl->fresh()->status)->toBe('disputed')
        ->and($invoice->fresh()->status)->toBe('cancelled')
        ->and((float) $invoice->fresh()->balance)->toBe(0.0);
});

it('refuses to void when the overage invoice has been PAID (must refund first)', function () {
    $decl = billableDeclaration($this);
    $this->svc->lock($decl, $this->operator);

    $invoice = Invoice::whereHas('items', fn ($q) => $q->where('type', 'percentage_rent'))
        ->where('tenant_id', $this->tenant->id)->first();

    // Capture a full cash payment allocated to the overage invoice (payments↔invoices pivot).
    $payment = Payment::create([
        'tenant_id' => $this->tenant->id,
        'amount' => 2500,
        'method' => 'cash',
        'status' => 'captured',
        'payment_date' => now()->toDateString(),
        'reference' => 'PCT-PAID',
    ]);
    $payment->invoices()->attach($invoice->id, ['allocated_amount' => 2500]);
    $invoice->recomputeTotals();
    expect((float) $invoice->fresh()->paid_amount)->toBe(2500.0);

    // Voiding must be refused — the whole transaction rolls back, nothing changes.
    expect(fn () => $this->svc->voidLocked($decl->fresh(), $this->operator, 'too late'))
        ->toThrow(DomainException::class);

    expect($decl->fresh()->status)->toBe('locked')            // declaration untouched
        ->and($invoice->fresh()->status)->toBe('paid');       // invoice untouched (fully paid)
});

it('the immediate overage invoice does not suppress that month\'s regular rent invoice', function () {
    // A lease with pct-rent terms AND a regular monthly base-rent charge.
    $lease = makeLease($this->unit, $this->tenant, [
        'status' => 'active',
        'commencement_date' => '2026-01-01',
        'has_percentage_rent' => true,
        'percentage_rent_calculation_type' => 'artificial',
        'percentage_rent_threshold' => 50000,
        'percentage_rent_rate' => 5,
    ]);
    Charge::create([
        'lease_id' => $lease->id, 'name' => 'Base Rent', 'type' => 'base_rent',
        'amount' => 10000, 'currency' => 'EGP', 'frequency' => 'monthly',
        'vat_applicable' => false, 'vat_rate' => 0, 'start_date' => '2026-01-01', 'is_active' => true,
    ]);

    $decl = TenantSalesDeclaration::create([
        'lease_id' => $lease->id,
        'period_start' => '2026-03-01', 'period_end' => '2026-03-31',
        'declared_sales' => 100000, 'calculated_percentage_rent' => 0,
        'status' => 'submitted', 'declared_at' => now(),
        'declared_by_type' => $this->tenant::class, 'declared_by_id' => $this->tenant->id,
    ]);

    // Lock bills the March overage immediately (invoice dated to the March sales period —
    // the exact window the monthly run's "already billed" guard checks).
    $this->svc->lock($decl, $this->operator);

    // The monthly run for March must STILL produce the regular base-rent invoice; the
    // past-period overage invoice must not be mistaken for "this lease is already billed".
    app(MonthlyBillingService::class)->runForPeriod(CarbonImmutable::parse('2026-03-01'));

    $rent = Invoice::whereHas('items', fn ($q) => $q->where('type', 'base_rent'))
        ->where('lease_id', $lease->id)->get();
    expect($rent)->toHaveCount(1)
        ->and((float) $rent->first()->total)->toBe(10000.0);

    // And the overage invoice is still its own separate, live invoice.
    $overage = Invoice::whereHas('items', fn ($q) => $q->where('type', 'percentage_rent'))
        ->where('lease_id', $lease->id)->whereNotIn('status', ['cancelled', 'credited'])->get();
    expect($overage)->toHaveCount(1)
        ->and((float) $overage->first()->total)->toBe(2500.0);
});

it('does not re-bill or churn the overage on a stale-snapshot double-lock (lock is row-safe)', function () {
    $decl = billableDeclaration($this);

    // A racing lock() grabs its own stale snapshot while the declaration is still 'submitted'.
    $staleSnapshot = TenantSalesDeclaration::find($decl->id);

    // First lock bills the overage.
    $this->svc->lock($decl, $this->operator);

    // Second lock, on the stale snapshot, must be a clean no-op: lock() re-reads the row
    // under lockForUpdate INSIDE the txn, sees 'locked', and bails BEFORE reverseOverage —
    // so it neither double-bills nor wastefully voids+rebills. Without the in-txn re-check
    // it would churn (2 anchor charges); with the true multi-connection race it would
    // double-bill outright.
    $this->svc->lock($staleSnapshot, $this->operator);

    // Exactly ONE anchor charge (2 would mean the guard let the stale call re-bill/churn)…
    expect(Charge::where('lease_id', $decl->lease_id)->where('type', 'percentage_rent')->count())->toBe(1);
    // …and exactly ONE live overage invoice.
    $live = Invoice::whereHas('items', fn ($q) => $q->where('type', 'percentage_rent'))
        ->where('tenant_id', $this->tenant->id)
        ->whereNotIn('status', ['cancelled', 'credited'])
        ->count();
    expect($live)->toBe(1);
});
