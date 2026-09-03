<?php

use App\Models\Invoice;
use App\Models\Payment;
use App\Services\LateFeeService;
use App\Services\Reports\ReportService;
use App\Support\TenantBalances;
use App\Support\ValueSets;
use Carbon\CarbonImmutable;

/**
 * **Disputing an invoice made a tenant disappear from the mall's own receivables.**
 *
 * *"Which invoices are still owed"* was answered by a hand-kept `['issued','partially_paid','overdue']`
 * written out **fourteen times** across the app, and every copy omitted **`disputed`** — which
 * `InvoiceSettlement::LIVE` has classified as owed since the day that register was written, and
 * which `InvoiceForm` still offers as one of the two statuses an operator may set.
 *
 * Measured before: a tenant whose only open invoice was disputed read **0.00 outstanding** and
 * **not delinquent**, and the amount was invisible to AR aging, the collections worklist, the CSV,
 * the tenant list, the lease summary and the tenant's own mobile balance.
 *
 * **Two questions, two scopes, one register.** `Invoice::stillOwed()` is the selection twin of
 * `collectableBalance()` — what the mall is owed — and `chaseable()` is the twin of
 * `chargeableBalance()`: still owed, less anything formally under dispute. That distinction was
 * already in the codebase for the AMOUNT and had never been applied to the SELECTION, so the
 * overdue scan and the dunning sweep excluded disputed only as a side effect of which three
 * statuses somebody happened to copy. A disputed amount is still CLAIMED and only not CHARGEABLE.
 */
beforeEach(function () {
    $this->asset = makeAsset(['code' => 'DSP']);
    $this->lease = makeLease(makeUnit($this->asset), makeTenant());
    $this->tenant = $this->lease->tenant;

    $this->disputed = makeInvoice($this->lease, [
        'asset_id' => $this->asset->id,
        'status' => 'disputed', 'issue_date' => '2026-01-01', 'due_date' => '2026-01-10',
        'subtotal' => 50000, 'vat_amount' => 0, 'total' => 50000, 'paid_amount' => 0, 'balance' => 50000,
    ]);
});

it('counts a disputed invoice in what the tenant owes', function () {
    expect($this->tenant->outstandingBalance())->toBe(50000.0);
});

it('leaves a tenant delinquent when their only open invoice is disputed', function () {
    // Before: disputing everything made a tenant read as up to date.
    expect($this->tenant->isDelinquent())->toBeTrue();
});

it('agrees with the batched figure the tenant list renders', function () {
    // `TenantBalances` exists so the list computes these once per page instead of per row, and it
    // carried its own copy of the status list — so the two could disagree about one tenant.
    $row = app(TenantBalances::class)->for([$this->tenant->id])[$this->tenant->id];

    expect($row['outstanding'])->toBe($this->tenant->outstandingBalance())
        ->and($row['delinquent'])->toBe($this->tenant->isDelinquent());
});

it('ages a disputed invoice into the arrears report', function () {
    $rows = app(ReportService::class)->arCollectionsByTenant(CarbonImmutable::parse('2026-03-01'));

    expect((float) $rows->sole()['total'])->toBe(50000.0);
});

it('does not CHASE it, and does not penalise it', function () {
    // The other half, and it must not move: the overdue scan and the dunning sweep leave a disputed
    // invoice alone. That was already the behaviour and it was an accident of the copied list; it is
    // a decision now, and `LateFeeService` reads `chargeableBalance()` for the amount to match.
    expect(Invoice::query()->chaseable()->pluck('id'))->not->toContain($this->disputed->id)
        ->and(Invoice::query()->chaseable()->count())->toBe(0);

    $ordinary = makeInvoice($this->lease, [
        'asset_id' => $this->asset->id,
        'status' => 'overdue', 'issue_date' => '2026-01-01', 'due_date' => '2026-01-10',
        'subtotal' => 9000, 'vat_amount' => 0, 'total' => 9000, 'paid_amount' => 0, 'balance' => 9000,
    ]);

    // The control, and it is what stops a scope that selects NOTHING passing the refusal above.
    expect(Invoice::query()->chaseable()->pluck('id'))->toContain($ordinary->id)
        ->and(Invoice::query()->stillOwed()->count())->toBe(2);
});

it('drops an invoice that really was settled', function () {
    // The control on the other side: `stillOwed()` is LIVE *and* collectable, so a receipted
    // invoice leaves both scopes. Settled through a real payment, because `recomputeTotals()`
    // derives the balance and a hand-typed `paid / 0` is a state no service produces.
    $payment = Payment::create([
        'tenant_id' => $this->tenant->id, 'amount' => 50000, 'method' => 'cash',
        'status' => 'captured', 'payment_date' => '2026-01-15',
    ]);
    $payment->invoices()->attach($this->disputed->id, ['allocated_amount' => 50000]);
    $this->disputed->fresh()->recomputeTotals();

    expect(Invoice::query()->stillOwed()->count())->toBe(0)
        ->and($this->tenant->fresh()->outstandingBalance())->toBe(0.0);
});

it('drops one that left the books', function () {
    $this->disputed->update(['status' => 'cancelled']);

    expect(Invoice::query()->stillOwed()->count())->toBe(0)
        ->and($this->tenant->fresh()->outstandingBalance())->toBe(0.0);
});

it('does not put a disputed invoice through the late-fee sweep', function () {
    // The refusal above asserts the SCOPE. This drives the service, which is where the money is —
    // and no test anywhere had put a header-disputed invoice through it.
    $this->disputed->update(['due_date' => '2026-01-01']);

    expect(app(LateFeeService::class)->applyTo($this->disputed->fresh()))->toBeFalse()
        ->and($this->disputed->fresh()->lateFeeInvoice()->exists())->toBeFalse();
});

it('never charges a late fee on an invoice that says PAID', function () {
    // `InvoiceSettlement::LIVE['paid']` says in writing that an invoice can carry `status = paid`
    // with a standing balance, and `Invoice::saving` recomputes `balance` on a header-dirty write
    // while leaving the status to the next `recomputeTotals()`. So raising the total of a receipted
    // invoice — a console or data-fix write; no shipped screen does it — produces exactly that.
    //
    // The old hand-kept allowlist refused it outright. `stillOwed()` alone would not, which is why
    // `paid` is in `NOT_CHASEABLE`: measured before that, a late fee applied to an invoice the
    // operator sees as PAID.
    $invoice = makeInvoice($this->lease, [
        'asset_id' => $this->asset->id,
        'status' => 'issued', 'issue_date' => '2026-01-01', 'due_date' => '2026-01-10',
        'subtotal' => 10000, 'vat_amount' => 0, 'total' => 10000, 'paid_amount' => 0, 'balance' => 10000,
    ]);

    $payment = Payment::create([
        'tenant_id' => $this->tenant->id, 'amount' => 10000, 'method' => 'cash',
        'status' => 'captured', 'payment_date' => '2026-01-05',
    ]);
    $payment->invoices()->attach($invoice->id, ['allocated_amount' => 10000]);
    $invoice->fresh()->recomputeTotals();

    expect($invoice->fresh()->status)->toBe('paid');

    // …then the total is raised behind it.
    $invoice->fresh()->update(['subtotal' => 20000, 'total' => 20000]);
    $reopened = $invoice->fresh();

    expect($reopened->status)->toBe('paid')
        ->and($reopened->collectableBalance())->toBe(10000.0)
        // It IS owed — that is the measurement question, and the tenant's balance says so…
        ->and($this->tenant->fresh()->outstandingBalance())->toBe(60000.0)
        // …and it is NOT chased or penalised.
        ->and($reopened->isChaseable())->toBeFalse()
        ->and(Invoice::query()->chaseable()->pluck('id'))->not->toContain($reopened->id)
        ->and(app(LateFeeService::class)->applyTo($reopened))->toBeFalse();
});

it('answers the same for a row as for a query, on every status', function () {
    // `isChaseable()` restates `chaseable()` in PHP for the post-lock re-check, and two spellings
    // of one rule is how they drift. Nothing pinned either term of it.
    foreach (ValueSets::allowed('invoices', 'status') as $status) {
        $invoice = makeInvoice($this->lease, [
            'asset_id' => $this->asset->id,
            'status' => $status, 'issue_date' => '2026-01-01', 'due_date' => '2026-01-10',
            'subtotal' => 7000, 'vat_amount' => 0, 'total' => 7000, 'paid_amount' => 0, 'balance' => 7000,
        ]);

        $inScope = Invoice::query()->chaseable()->whereKey($invoice->id)->exists();

        expect($invoice->fresh()->isChaseable())->toBe($inScope, "chaseable disagrees on `{$status}`");

        // Not deleted — a money record never is (`#[NeverDeletable]`). Retired to a status that
        // is in neither scope, so the next iteration starts clean.
        $invoice->forceFill(['status' => 'cancelled', 'balance' => 0])->saveQuietly();
    }
});
