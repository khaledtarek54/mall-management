<?php

use App\Models\Invoice;
use App\Services\LateFeeService;
use Carbon\CarbonImmutable;
use Database\Seeders\AccountMappingSeeder;
use Database\Seeders\ChartOfAccountsSeeder;

/**
 * The nightly late-fee sweep hydrated the entire arrears backlog, and could be run twice at once.
 *
 * `ApplyLateFees` declared `$timeout = 600` with no overlap guard, against a queue `retry_after` of
 * **90** — so any run over 90 seconds became reclaimable and a second worker started the same sweep
 * while the first was still going. Correctness survived (each invoice is row-locked and its full
 * precondition re-checked inside the transaction); it was double the load and double the memory
 * against AR at 04:00, nightly, on the one dataset that never shrinks. The sibling
 * `RunMonthlyBilling` — identical timeout — had exactly that guard.
 *
 * The guard, the `retry_after` arithmetic and the classification of every job live in
 * `QueueJobSafetyConformanceTest`. What is here is the sweep's own shape: it takes a **snapshot of
 * ids** and walks it in chunks, which bounds the memory AND keeps a property the old `->get()` had
 * by accident — the loop must not walk into the invoices it is creating.
 */
beforeEach(function () {
    $this->seed(ChartOfAccountsSeeder::class);
    $this->seed(AccountMappingSeeder::class);
    $this->svc = app(LateFeeService::class);
    $this->today = CarbonImmutable::parse('2026-06-30');
});

/**
 * The sweep with a page size of one, so the paging is actually exercised.
 *
 * At the production 250 the hazard below is unreachable in a fixture: `chunkById()` only re-queries
 * when a page comes back FULL, so a handful of rows never triggers a second look and the bug hides.
 * The arrears backlog this runs against fills pages every night.
 */
class OnePerPageLateFeeService extends LateFeeService
{
    protected const CHUNK = 1;
}

it('does not walk into the late-fee invoices it is creating', function () {
    // The reason this is an id SNAPSHOT rather than `chunkById()`. A late fee is its own invoice
    // now, issued today and due `today + payment_terms_days` — which on zero-day terms is due
    // TODAY, so it matches the sweep's own filter (`due_date <= today`, balance > 0, issued).
    // `chunkById()` pages forward on ascending id, so once a page fills it walks straight into the
    // fees it just raised and considers charging a fee on a fee, in the same run. Snapshotting the
    // ids up front cannot — and this runs one-per-page so the paging is real.
    $lease = makeLease(makeUnit(makeAsset(['code' => 'MALL'])), makeTenant(), [
        'payment_terms_days' => 0,
        'late_fee_percent' => 10,
        'late_fee_grace_days' => 0,
        'late_fee_minimum' => 0,
    ]);

    foreach (['2026-05-31', '2026-06-01'] as $due) {
        makeInvoice($lease, [
            'status' => 'issued',
            'issue_date' => '2026-05-01',
            'due_date' => $due,
            'subtotal' => 10000, 'vat_amount' => 0, 'total' => 10000,
            'paid_amount' => 0, 'balance' => 10000,
        ]);
    }

    $stats = app(OnePerPageLateFeeService::class)->runForToday($this->today);

    // Two arrears invoices in, two fees out. A third consideration means the sweep ate its own
    // output — and the fee invoices ARE past due today, so it would have charged them.
    expect($stats['considered'])->toBe(2)
        ->and($stats['applied'])->toBe(2)
        ->and(Invoice::whereNotNull('late_fee_invoice_id')->count())->toBe(2)
        ->and(Invoice::count())->toBe(4);
});

it('counts every past-due invoice exactly once across the chunk boundary', function () {
    // The sweep walks a snapshot in chunks; the count must be the whole snapshot, not one chunk of
    // it. A paging bug here silently stops charging the tail of the backlog — the oldest arrears,
    // which is the part that matters most.
    $lease = makeLease(makeUnit(makeAsset(['code' => 'MALL'])), makeTenant(), [
        'payment_terms_days' => 14,
        'late_fee_percent' => 5,
        'late_fee_grace_days' => 0,
        'late_fee_minimum' => 0,
    ]);

    foreach (range(1, 6) as $n) {
        makeInvoice($lease, [
            'status' => 'issued',
            'issue_date' => '2026-0'.$n.'-01',
            'due_date' => '2026-0'.$n.'-10',
            'subtotal' => 1000, 'vat_amount' => 0, 'total' => 1000,
            'paid_amount' => 0, 'balance' => 1000,
        ]);
    }

    $stats = $this->svc->runForToday($this->today);

    expect($stats['considered'])->toBe(6)
        ->and($stats['applied'])->toBe(6)
        ->and($stats['failed'])->toBe(0)
        ->and(Invoice::whereNotNull('late_fee_invoice_id')->count())->toBe(6);
});

it('is idempotent across two runs of the same day — the re-entrancy control', function () {
    // The overlap guard stops a second worker starting; this is why a second run was never WRONG,
    // only wasteful. Both facts have to stay true: if the per-invoice lock and re-check ever stop
    // holding, the guard alone is not enough, because it is a cache lock and a cache can be flushed.
    $lease = makeLease(makeUnit(makeAsset(['code' => 'MALL'])), makeTenant(), [
        'payment_terms_days' => 14,
        'late_fee_percent' => 5,
        'late_fee_grace_days' => 0,
        'late_fee_minimum' => 0,
    ]);

    makeInvoice($lease, [
        'status' => 'issued',
        'issue_date' => '2026-05-01',
        'due_date' => '2026-05-31',
        'subtotal' => 10000, 'vat_amount' => 0, 'total' => 10000,
        'paid_amount' => 0, 'balance' => 10000,
    ]);

    $this->svc->runForToday($this->today);
    $second = $this->svc->runForToday($this->today);

    expect($second['applied'])->toBe(0)
        ->and(Invoice::whereNotNull('late_fee_invoice_id')->count())->toBe(1);
});
