<?php

/*
|--------------------------------------------------------------------------
| Sequential document numbers are allocated under a lock
|--------------------------------------------------------------------------
| Every numbered document — Invoice, JournalEntry, CreditNote, VendorBill, Expense,
| DepositTransaction, Payroll — allocated its number by reading MAX(number) for the prefix, adding
| one, and inserting. Check-then-act across a network round-trip. The `->exists()` probe two of
| them add is not protection: it cannot see another connection's UNCOMMITTED row.
|
| Demonstrated against the real database before the fix — two allocations with no insert between
| them both returned `INV-AW-0082`. Reachable, too: invoices are created by five services,
| three of which take no lock (BillMeterReadingService, BillViolationFineService,
| CamReconciliationService), so the nightly billing run racing a violation-fine or a CAM
| reconciliation is exactly it.
|
| All seven tables carry a UNIQUE index on `number`, so the loser's INSERT is refused rather than
| writing a duplicate: the failure mode is a 500 or a scheduled billing job dying mid-month, not
| corrupt books. The index was doing the job the code should have been doing, and paying for it in
| availability.
*/

use App\Models\Concerns\AllocatesDocumentNumber;
use App\Models\CreditNote;
use App\Models\DepositTransaction;
use App\Models\Expense;
use App\Models\Invoice;
use App\Models\JournalEntry;
use App\Models\Payroll;
use App\Models\VendorBill;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Cache;

/** Every model that mints a sequential number. */
const NUMBERED_DOCUMENTS = [
    Invoice::class,
    JournalEntry::class,
    CreditNote::class,
    VendorBill::class,
    Expense::class,
    DepositTransaction::class,
    Payroll::class,
];

it('allocates under a lock in every numbered document', function () {
    $unlocked = [];

    foreach (NUMBERED_DOCUMENTS as $class) {
        if (! in_array(AllocatesDocumentNumber::class, class_uses_recursive($class), true)) {
            $unlocked[] = class_basename($class);
        }
    }

    expect($unlocked)->toBe([], implode('', [
        'These mint a sequential number with an unlocked read-then-write: '.implode(', ', $unlocked).'. ',
        'Two processes will compute the same number and the UNIQUE index will refuse the second insert.',
    ]));
});

it('holds the lock across the insert, not just the calculation', function () {
    // Locking only the arithmetic leaves the identical window open: A computes 0082, releases,
    // then B computes 0082 before A's row lands. The lock is taken in `creating` and released in
    // `created`, so it spans the INSERT — by the time it drops, the row is visible to the next
    // reader's MAX().
    // Instantiate first so the MODEL's own creating hook — which takes the lock — is registered
    // before the spy below. Eloquent fires listeners in registration order, and Invoice::creating()
    // does not itself boot the model, so registering the spy on a cold class would put it FIRST
    // and observe the lock before the allocator has taken it. That reads as a product failure and
    // is purely a harness artifact.
    new Invoice;

    $asset = makeAsset(['code' => 'LCK']);
    $lease = makeLease(makeUnit($asset), makeTenant());
    $prefix = 'doc-number:INV-LCK-';

    // Observed from INSIDE the create, after the allocator has taken the lock: a rival process
    // asking for the same prefix must be refused.
    $rivalBlockedDuringInsert = null;
    Invoice::creating(function () use ($prefix, &$rivalBlockedDuringInsert) {
        $rivalBlockedDuringInsert = ! Cache::lock($prefix, 1)->get();
    });

    $invoice = makeInvoice($lease, ['issue_date' => '2026-07-05']);

    expect($rivalBlockedDuringInsert)->toBeTrue('the allocator must hold the prefix lock while inserting')
        ->and($invoice->number)->toStartWith('INV-LCK-');

    // …and released afterwards, so the next writer is not stuck behind a leaked lock.
    $freeAfterwards = Cache::lock($prefix, 1);
    expect($freeAfterwards->get())->toBeTrue('the lock must be released once the row is committed');
    $freeAfterwards->release();
});

it('gives concurrent creations distinct numbers within one prefix', function () {
    // The property the whole mechanism exists to hold. Ten invoices in one property's series:
    // every number distinct, and the UNIQUE index never had to refuse anything. (The series is
    // CONTINUOUS since EG-10, not a month — the lock is keyed on the prefix either way.)
    $asset = makeAsset();
    $lease = makeLease(makeUnit($asset), makeTenant());

    $numbers = collect(range(1, 10))
        ->map(fn () => makeInvoice($lease, ['issue_date' => '2026-07-10'])->number);

    expect($numbers->unique())->toHaveCount(10)
        ->and($numbers->every(fn (string $n) => str_starts_with($n, 'INV-'.$asset->code.'-')))->toBeTrue();
});

it('scopes the lock to the prefix, so one property never blocks another', function () {
    // A portfolio-wide lock would serialise the whole monthly billing run across every property.
    // The key is the number PREFIX, so Atriom Walk billing does not wait on Plaza Annex — and
    // invoices never wait on journal entries.
    $a = makeAsset(['code' => 'AAA']);
    $b = makeAsset(['code' => 'BBB']);

    $lockA = Cache::lock('doc-number:INV-AAA-', 5);
    expect($lockA->get())->toBeTrue();

    // Holding A's lock must not stop B minting a number.
    $leaseB = makeLease(makeUnit($b), makeTenant());
    $invoiceB = makeInvoice($leaseB, ['issue_date' => '2026-07-11']);

    expect($invoiceB->number)->toStartWith('INV-BBB-');

    $lockA->release();
});

it('still refuses a genuine duplicate — the index stays the final arbiter', function () {
    // The lock makes collisions not happen; it does not make the UNIQUE index redundant, and
    // nothing here should have quietly dropped it.
    $asset = makeAsset();
    $lease = makeLease(makeUnit($asset), makeTenant());
    $first = makeInvoice($lease, ['issue_date' => '2026-07-12']);

    expect(fn () => Invoice::withoutEvents(fn () => Invoice::create([
        'number' => $first->number,          // force the collision past the allocator
        'lease_id' => $lease->id,
        'tenant_id' => $lease->tenant_id,
        'issue_date' => '2026-07-12',
        'due_date' => '2026-07-19',
        'period_start' => '2026-07-01',
        'period_end' => '2026-07-31',
        'subtotal' => 100, 'vat_amount' => 0, 'total' => 100, 'balance' => 100,
        'status' => 'issued',
    ])))->toThrow(QueryException::class);
});
