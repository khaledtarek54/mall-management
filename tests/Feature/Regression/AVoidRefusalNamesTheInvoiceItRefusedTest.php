<?php

use App\Models\Invoice;
use App\Models\Payment;
use App\Models\TenantSalesDeclaration;
use App\Services\PercentageRentCalculationService;
use App\Services\VoidInvoiceService;
use App\Services\WriteOffInvoiceService;
use Database\Seeders\RolesPermissionsSeeder;
use Illuminate\Support\Facades\App;

/**
 * SW-231 — **a void refusal that does not name the invoice is unreadable on the screen it is
 * usually read from.**
 *
 * `VoidInvoiceService::void()` is dispatched from the invoice's own Edit page — where the operator
 * can see which document they are looking at — but that is not where it is mostly called from:
 *
 *   * `PercentageRentCalculationService::reverseOverage()` voids the overage invoice a locked sales
 *     declaration raised, driven from the **sales-declaration** screen;
 *   * `CamReconciliationService::voidAllocation()` voids up to TWO invoices plus a credit note in
 *     one loop, driven from the **CAM allocation** tab.
 *
 * Both of those callers' own docblocks name a refusal from `void()` as the expected outcome
 * (*"A PAID overage invoice can't be voided — VoidInvoiceService throws"*), so it is the common case
 * there rather than an edge. A `DomainException` renders as a toast (`bootstrap/app.php`), and the
 * toast read *"This invoice carries a write-off"* / *"Cannot void an invoice with captured
 * payments"* — with nothing to say WHICH invoice, and on the CAM path no way to tell which of the
 * two. The sibling write-off refusals raised one file away (`write_off_not_live`,
 * `write_off_already_full`, `write_off_exceeds_remaining`, …) have all named `Invoice :number`
 * since they were written.
 *
 * The escape each message names — *reverse the write-off first*, *void the receipt first* — is only
 * actionable once the operator can find the document it is about.
 *
 * Measured at HEAD before the fix, on the cascade below: `voidLocked()` on a locked declaration
 * whose 2,500 overage invoice carried a 100 write-off refused with *"This invoice carries a
 * write-off. Reverse the write-off first — …"*, and the invoice it meant appears nowhere on the
 * sales-declaration screen the operator is standing on. `PercentageRentImmediateBillingTest` has
 * driven that exact cascade since it was written and asserts `toThrow(DomainException::class)` with
 * no message, so nothing in the suite had ever read what the toast says.
 *
 * `admin.refusals.invoice_void_eta_filed` is deliberately untouched: module 16 is
 * `Modules::FROZEN`, so nothing on a current install sets `eta_status` to `valid`.
 *
 * The in-transaction re-check of the captured-cash guard is changed for consistency and is NOT
 * covered here: it fires only when the state moves between the pre-check and the lock, which a
 * single-threaded test cannot reach.
 */
beforeEach(function () {
    $this->seed(RolesPermissionsSeeder::class);
    ensureAllPropertiesAsset();

    $this->asset = makeAsset(['code' => 'VRN']);
    $this->tenant = makeTenant();
    $this->operator = makeUser('manager', [$this->asset->id]);
    $this->lease = makeLease(makeUnit($this->asset), $this->tenant);

    $this->invoice = makeInvoice($this->lease, [
        'status' => 'issued',
        'issue_date' => now()->toDateString(),
        'due_date' => now()->addDays(7)->toDateString(),
    ]);
    $this->invoice->items()->create([
        'type' => 'base_rent', 'description' => 'Rent',
        'amount' => 10000, 'vat_rate' => 0, 'vat_amount' => 0, 'total' => 10000,
    ]);
    $this->invoice = $this->invoice->fresh();
});

/**
 * A LOCKED percentage-rent declaration and the overage invoice `lock()` raised for it — the real
 * cascade, built through the real service rather than by writing the rows.
 *
 * @return array{0: TenantSalesDeclaration, 1: Invoice}
 */
function sw231LockedOverage($ctx): array
{
    $lease = makeLease(makeUnit($ctx->asset), $ctx->tenant, [
        'status' => 'active',
        'has_percentage_rent' => true,
        'percentage_rent_calculation_type' => 'artificial',
        'percentage_rent_threshold' => 50000, // (100,000 − 50,000) × 5% = 2,500 of overage
        'percentage_rent_rate' => 5,
    ]);

    $declaration = TenantSalesDeclaration::create([
        'lease_id' => $lease->id,
        'period_start' => '2026-03-01',
        'period_end' => '2026-03-31',
        'declared_sales' => 100000,
        'calculated_percentage_rent' => 0,
        'status' => 'submitted',
        'declared_at' => now(),
        'declared_by_type' => $ctx->tenant::class,
        'declared_by_id' => $ctx->tenant->id,
    ]);

    app(PercentageRentCalculationService::class)->lock($declaration, $ctx->operator);

    $overage = Invoice::query()
        ->where('lease_id', $lease->id)
        ->whereHas('items', fn ($q) => $q->where('type', 'percentage_rent'))
        ->firstOrFail();

    return [$declaration->fresh(), $overage];
}

/**
 * The message names THAT invoice and carries no leftover placeholder.
 *
 * Both halves are load-bearing. `__()` leaves `:number` standing when the replacement is not
 * passed, so "contains the number" alone would pass on a message that had never been given one —
 * and "contains no `:number`" alone passes on the pre-fix string, which mentions neither.
 */
function sw231ExpectNames(Closure $act, string $number): void
{
    expect($act)->toThrow(function (DomainException $e) use ($number) {
        expect($e->getMessage())
            ->toContain($number)
            ->not->toContain(':number');
    });
}

it('names the invoice when a standing write-off blocks the void', function () {
    app(WriteOffInvoiceService::class)->write($this->invoice, ['amount' => 4000, 'reason' => 'uneconomic_to_pursue']);

    sw231ExpectNames(
        fn () => app(VoidInvoiceService::class)->void($this->invoice->fresh(), 'keyed in error'),
        $this->invoice->number,
    );
});

it('names the invoice when captured cash blocks the void', function () {
    $payment = Payment::create([
        'tenant_id' => $this->tenant->id,
        'amount' => 2500,
        'method' => 'cash',
        'status' => 'captured',
        'payment_date' => now()->toDateString(),
        'reference' => 'SW231-CASH',
    ]);
    $payment->invoices()->attach($this->invoice->id, ['allocated_amount' => 2500]);
    $this->invoice->recomputeTotals();

    // The premise: it is CASH that refuses, not a reversible credit channel.
    expect($this->invoice->fresh()->capturedCashPaid())->toBe(2500.0);

    sw231ExpectNames(
        fn () => app(VoidInvoiceService::class)->void($this->invoice->fresh(), 'keyed in error'),
        $this->invoice->number,
    );
});

it('names the invoice on the model backstop a write-off blocks', function () {
    app(WriteOffInvoiceService::class)->write($this->invoice, ['amount' => 4000, 'reason' => 'uneconomic_to_pursue']);

    // `Invoice::updating` — the guard `LeaseTerminationService`'s direct cancel goes through, which
    // never touches the service and is the most common cancel in the system.
    sw231ExpectNames(
        fn () => $this->invoice->fresh()->update(['status' => 'cancelled', 'balance' => 0]),
        $this->invoice->number,
    );
});

it('names the invoice on the model backstop captured cash blocks', function () {
    $payment = Payment::create([
        'tenant_id' => $this->tenant->id,
        'amount' => 2500,
        'method' => 'cash',
        'status' => 'captured',
        'payment_date' => now()->toDateString(),
        'reference' => 'SW231-CASH-2',
    ]);
    $payment->invoices()->attach($this->invoice->id, ['allocated_amount' => 2500]);
    $this->invoice->recomputeTotals();

    sw231ExpectNames(
        fn () => $this->invoice->fresh()->update(['status' => 'cancelled']),
        $this->invoice->number,
    );
});

it('names the overage invoice a cascading declaration void could not reverse', function () {
    // THE ROW'S OWN CASE. The operator is on the sales-declaration screen; the document that
    // refuses is one they never picked and cannot see from there.
    [$declaration, $overage] = sw231LockedOverage($this);

    app(WriteOffInvoiceService::class)->write($overage, ['amount' => 100, 'reason' => 'settled_short']);

    sw231ExpectNames(
        fn () => app(PercentageRentCalculationService::class)
            ->voidLocked($declaration->fresh(), $this->operator, 'keyed in error'),
        $overage->number,
    );

    // A refusal must not half-apply: `reverseOverage()` deactivates the anchor charge before it
    // reaches the void, and the whole transaction rolls back.
    expect($declaration->fresh()->status)->toBe('locked')
        ->and($overage->fresh()->status)->not->toBe('cancelled');
});

it('names the invoice in Arabic too, which is the half a one-language edit misses', function () {
    app(WriteOffInvoiceService::class)->write($this->invoice, ['amount' => 4000, 'reason' => 'uneconomic_to_pursue']);

    App::setLocale('ar');

    expect(fn () => app(VoidInvoiceService::class)->void($this->invoice->fresh(), 'keyed in error'))
        ->toThrow(function (DomainException $e) {
            expect($e->getMessage())
                ->toContain($this->invoice->number)
                ->not->toContain(':number')
                // …and it really is the Arabic string, not English through the fallback locale.
                ->and(preg_match('/[\x{0600}-\x{06FF}]/u', $e->getMessage()))->toBe(1);
        });
});

it('still voids what it is allowed to void, on both paths', function () {
    // (a) the direct path — a clean invoice voids.
    $clean = makeInvoice($this->lease, ['status' => 'issued']);
    app(VoidInvoiceService::class)->void($clean, 'keyed in error');

    expect($clean->fresh()->status)->toBe('cancelled');

    // (b) the cascade — a clean declaration's void still reverses its overage rather than refusing.
    [$declaration, $overage] = sw231LockedOverage($this);
    app(PercentageRentCalculationService::class)->voidLocked($declaration->fresh(), $this->operator, 'restated');

    expect($declaration->fresh()->status)->toBe('disputed')
        ->and($overage->fresh()->status)->toBe('cancelled');
});
