<?php

use App\Models\DepositTransaction;
use App\Models\Lease;
use App\Services\BillSecurityDepositService;
use App\Services\SettleMoveOutService;
use Carbon\CarbonImmutable;
use Database\Seeders\AccountMappingSeeder;
use Database\Seeders\ChartOfAccountsSeeder;
use Tests\Support\LockSpy;

/**
 * A move-out settlement locks the deposit pot before it disburses anything.
 *
 * `SettleMoveOutService::settle()` took **no lock of any kind** — and unlike the unit
 * double-booking no UNIQUE index can turn the race into a duplicate-key error. `deposit_transactions`
 * does carry one — on `number` — but `AllocatesDocumentNumber` hands the two writers DIFFERENT numbers
 * under its own cache lock, so the index that caught the unit race by accident cannot fire here.
 * Nothing constrains THE POT, which is the quantity being raced.
 * Two settlements on one move-out each read the whole pot and each wrote a refund for it: the
 * deposit disbursed twice, `depositHeld()` negative by its full value, and `deposits_held` in the
 * GL saying a tenant who has left owes the landlord money.
 *
 * **The LEASE is the contended row**, because the pot is one per lease and spans three tables —
 * recorded movements, deposit applications, and the settled part of any billed deposit. Locking any
 * one of them leaves the other two free to move, which is why `ApplyDepositToInvoiceService` locking
 * the INVOICE was not a guard for this at all: two applications against two different invoices of
 * one lease lock two different rows and are not serialised.
 *
 * **What this file can and cannot prove.** SQLite compiles `lockForUpdate()` to nothing, so no test
 * on the ordinary suite can demonstrate two transactions serialising. `LockSpy` swaps in a grammar
 * that compiles the lock to a SQL comment, so `DB::listen()` can see WHICH TABLE was locked on the
 * real path — that is what is asserted here. The serialisation itself belongs to `tests/Mysql/`.
 *
 * A lock on the right table is also not proof that THIS service took it: another service in the same
 * request can lock the same table. Hence `settle_arrears => false` below, which keeps
 * `ApplyDepositToInvoiceService` out of the call entirely.
 */
beforeEach(function () {
    $this->seed(ChartOfAccountsSeeder::class);
    $this->seed(AccountMappingSeeder::class);

    $this->asset = makeAsset();
    $this->lease = makeLease(makeUnit($this->asset), null, [
        'status' => 'terminated',
        'expiry_date' => CarbonImmutable::now()->subMonth()->toDateString(),
    ]);

    depositMovement($this->lease, 'receipt', 100000);
});

it('locks the lease and every table the deposit pot spans', function () {
    $spy = LockSpy::watch(function () {
        app(SettleMoveOutService::class)->settle($this->lease->fresh(), [
            'settlement_date' => CarbonImmutable::now()->toDateString(),
            'settle_arrears' => false,
            'deductions' => [],
        ]);
    });

    // **Asserted as an ORDER, not as four independent facts**, and that is not stylistic. Writing
    // the refund fires `LedgerRealtimeSync` → `LedgerPoster::sync()`, which opens by locking the
    // SOURCE ROW — `deposit_transactions` — and the queue is `sync` in the test environment, so it
    // runs inside this very `watch()`. A bare `locked('deposit_transactions')` is therefore
    // satisfied by the ledger poster whether or not the pot read locks anything at all: it passed
    // with the fix removed. `lockedTables()` keeps each table's FIRST appearance, and the poster
    // cannot run before the read it is triggered by, so the ORDER can only be produced by the guard.
    //
    // It is also the global lock order itself, which is the property that keeps a settlement from
    // deadlocking against an ordinary receipt — so one assertion pins both.
    expect($spy->lockedTables())
        ->toBe(['leases', 'invoices', 'deposit_transactions', 'deposit_applications']);
});

it('refunds the deposit exactly once', function () {
    $lease = $this->lease->fresh();

    expect($lease->depositHeld())->toEqual(100000.0);   // the premise

    app(SettleMoveOutService::class)->settle($lease, [
        'settlement_date' => CarbonImmutable::now()->toDateString(),
        'settle_arrears' => false,
        'deductions' => [],
    ]);

    $after = $this->lease->fresh();

    expect($after->depositHeld())->toEqual(0.0)
        ->and((float) $after->deposits()->where('type', 'refund')->where('status', 'recorded')->sum('amount'))
        ->toEqual(100000.0);
});

it('will not settle the same move-out a second time', function () {
    $settle = fn () => app(SettleMoveOutService::class)->settle($this->lease->fresh(), [
        'settlement_date' => CarbonImmutable::now()->toDateString(),
        'settle_arrears' => false,
        'deductions' => [],
    ]);

    $settle();

    // The pot is empty and there are no deductions, so there is nothing left to settle. The refusal
    // is what stops a second refund; without it the second run would read the pot again and write
    // another 100,000 out.
    expect($settle)->toThrow(InvalidArgumentException::class);

    expect((float) $this->lease->fresh()->deposits()
        ->where('type', 'refund')->where('status', 'recorded')->sum('amount'))->toEqual(100000.0)
        ->and($this->lease->fresh()->depositHeld())->toEqual(0.0);
});

it('reads the same figure locked or not, so the two twins cannot drift', function () {
    $lease = $this->lease->fresh();

    // `depositHeldForUpdate()` re-derives the pot with its own queries rather than reusing the
    // display path — the price of having the locks visible in the method the gate reads. That makes
    // "do the two agree" a real question, so it is asserted rather than assumed.
    expect($lease->depositHeldForUpdate())->toEqual($lease->depositHeld());

    depositMovement($lease, 'forfeit', 25000);
    $lease = $this->lease->fresh();
    expect($lease->depositHeldForUpdate())->toEqual($lease->depositHeld())
        ->and($lease->depositHeldForUpdate())->toEqual(75000.0);
});

/**
 * The other door onto the same pot: asking the tenant for a deposit twice.
 *
 * `BillSecurityDepositService` locked the lease and then read `depositUnbilledShortfall()` — a
 * PLAIN read — under a comment asserting that the lock made it a check-then-act guard. It does not:
 * a row lock serialises the writers, and under MySQL's REPEATABLE READ the second transaction is
 * still answered from the snapshot it took before it waited. Both operators then read the same
 * unbilled shortfall and each raise an invoice for it, so the tenant is asked for twice the security
 * they agreed and `deposits_held` is credited twice the day they pay.
 *
 * Same class as the move-out above, one action away, and it was found by asking the code rather than
 * the diff: the sweep that fixed `SettleMoveOutService` looked for services that locked NOTHING, and
 * this one locks.
 */
it('reads the unbilled deposit shortfall under a lock before billing it', function () {
    $lease = makeLease(makeUnit($this->asset), null, [
        'status' => 'active',
        'security_deposit' => 60000,
    ]);

    $spy = LockSpy::watch(function () use ($lease) {
        app(BillSecurityDepositService::class)->bill($lease->fresh(), [
            'issue_date' => CarbonImmutable::now()->toDateString(),
        ]);
    });

    // The contended row, and then the sets its answer is derived from — `invoices` is the one that
    // was missing, because the decision is "how much have we already ASKED for", a question about
    // invoice rows a concurrent operator may just have written.
    //
    // ORDER again, and here it is the ONLY honest form: this service ISSUES an invoice, whose own
    // `saved` hook posts to the ledger and locks `invoices`. A bare `locked('invoices')` was
    // satisfied by that, i.e. by the write rather than by the guard, and passed with the fix
    // removed. `tenants` is the tail — `Invoice::saved` → `ApplyTenantCreditService` — and it is
    // asserted too, because a leaf that moved would mean the write path had changed underneath.
    expect($spy->lockedTables())
        ->toBe(['leases', 'invoices', 'deposit_transactions', 'deposit_applications', 'tenants']);
});

it('refuses a second deposit invoice for money already asked for', function () {
    $lease = makeLease(makeUnit($this->asset), null, [
        'status' => 'active',
        'security_deposit' => 60000,
    ]);

    $bill = fn () => app(BillSecurityDepositService::class)->bill($lease->fresh(), [
        'issue_date' => CarbonImmutable::now()->toDateString(),
    ]);

    $invoice = $bill();

    expect((float) $invoice->items()->where('type', 'security_deposit')->sum('amount'))->toEqual(60000.0);

    // The control that matters: the invoice is OPEN, so nothing has been received — the pot is
    // still empty and only the "already asked for" term stands between the tenant and a second ask.
    expect($lease->fresh()->depositHeldForUpdate())->toEqual(0.0);

    expect($bill)->toThrow(DomainException::class);
});

it('reads the same billed-outstanding figure locked or not', function () {
    $lease = makeLease(makeUnit($this->asset), null, [
        'status' => 'active',
        'security_deposit' => 60000,
    ]);

    expect($lease->depositBilledOutstandingForUpdate())->toEqual($lease->depositBilledOutstanding())
        ->and($lease->depositUnbilledShortfallForUpdate())->toEqual($lease->depositUnbilledShortfall());

    app(BillSecurityDepositService::class)->bill($lease->fresh(), [
        'issue_date' => CarbonImmutable::now()->toDateString(),
    ]);

    $lease = $lease->fresh();

    expect($lease->depositBilledOutstandingForUpdate())->toEqual($lease->depositBilledOutstanding())
        ->and($lease->depositBilledOutstandingForUpdate())->toEqual(60000.0)
        ->and($lease->depositUnbilledShortfallForUpdate())->toEqual($lease->depositUnbilledShortfall())
        ->and($lease->depositUnbilledShortfallForUpdate())->toEqual(0.0);
});

/**
 * The twins asserted DIRECTLY, because a service-level order assertion cannot see a redundant lock.
 *
 * `depositUnbilledShortfallForUpdate()` composes `depositShortfallForUpdate()` (which pins the
 * deposit-billing invoices through the pot) and `depositBilledOutstandingForUpdate()` (which pins
 * the same set again). PHP evaluates left to right, so by the time the second runs the rows are
 * already held — and deleting its lock leaves the service-level order IDENTICAL. Measured: the
 * mutation stayed green. The conformance gate catches it, because `AUTHORITATIVE_GUARDS` reads that
 * method's own body, and nothing behavioural did.
 *
 * A method called on its own is the case the redundancy is FOR: `BillSecurityDepositService` reads
 * `depositBilledOutstandingForUpdate()` by itself to word its refusal, and the next caller may not
 * come through the composer at all. So each twin is asked, alone, what it locks.
 */
it('locks the pot from the twin itself, whatever called it', function () {
    $lease = $this->lease->fresh();

    expect(LockSpy::watch(fn () => $lease->depositHeldForUpdate())->lockedTables())
        ->toBe(['invoices', 'deposit_transactions', 'deposit_applications']);

    // Alone: nothing has pinned the invoices for it, so its own lock is the only one there is.
    expect(LockSpy::watch(fn () => $lease->depositBilledOutstandingForUpdate())->lockedTables())
        ->toBe(['invoices']);

    // And the display twins take none at all — the property that lets a hundred of them render on
    // one list page without contending with a settlement in progress.
    expect(LockSpy::watch(fn () => $lease->depositHeld())->lockedTables())->toBe([])
        ->and(LockSpy::watch(fn () => $lease->depositBilledOutstanding())->lockedTables())->toBe([])
        ->and(LockSpy::watch(fn () => $lease->depositUnbilledShortfall())->lockedTables())->toBe([]);
});

/**
 * The FIFTH door — the deposit register's own Create and Edit pages, which had no cap at all.
 *
 * The sweep that produced this fix looked for services that took no lock. The register does not
 * take a lock either, and it never had even the check-then-act version: `DepositTransactionForm`
 * caps `amount` at `minValue(0.01)` and nothing else, `ListDepositTransactions` mounts a plain
 * `CreateAction`, and both pages are gated on `deposit_transactions.create` / `.edit` — the SAME
 * permissions the lease action gates on. So the operator refused a 500,000 refund on a 100,000 pot
 * from the lease page could go to the register and save it.
 *
 * The model's own freeze immediately above fires only for `type === 'receipt'`, so a refund row was
 * freely creatable AND freely editable afterwards. `depositHeld()` goes to −400,000, `deposits_held`
 * in the GL is debited money that never arrived, and the move-out statement offers a negative
 * deposit.
 *
 * Guarded on the MODEL rather than on the two forms, for the reason `ValueSets::guard()` is one
 * wildcard listener: a sixth door — the importer, a console command, a screen nobody has written —
 * is covered by existing rather than by being remembered.
 */
it('refuses a refund larger than the pot, whichever door it comes through', function () {
    $lease = $this->lease->fresh();          // holds 100,000

    // The door that HAD a cap, and the one that never did — same refusal, same model guard.
    expect(fn () => DepositTransaction::create([
        'lease_id' => $lease->id,
        'tenant_id' => $lease->tenant_id,
        'asset_id' => $lease->unit?->asset_id,
        'type' => 'refund',
        'status' => 'recorded',
        'method' => 'bank',
        'amount' => 500000,
        'transaction_date' => CarbonImmutable::now()->toDateString(),
    ]))->toThrow(DomainException::class);

    expect(fn () => DepositTransaction::create([
        'lease_id' => $lease->id,
        'tenant_id' => $lease->tenant_id,
        'asset_id' => $lease->unit?->asset_id,
        'type' => 'forfeit',
        'status' => 'recorded',
        'amount' => 100000.01,
        'transaction_date' => CarbonImmutable::now()->toDateString(),
    ]))->toThrow(DomainException::class);

    // The controls, without which a guard that refused everything would read as a pass. Exactly the
    // pot is allowed — a landlord returning the whole deposit is the ORDINARY outcome, so an
    // off-by-a-rounding-error guard would block every clean move-out.
    $whole = DepositTransaction::create([
        'lease_id' => $lease->id,
        'tenant_id' => $lease->tenant_id,
        'asset_id' => $lease->unit?->asset_id,
        'type' => 'refund',
        'status' => 'recorded',
        'method' => 'bank',
        'amount' => 100000,
        'transaction_date' => CarbonImmutable::now()->toDateString(),
    ]);

    expect($whole->exists)->toBeTrue()
        ->and($lease->fresh()->depositHeld())->toEqual(0.0);
});

it('measures an EDIT against the pot without its own row in it', function () {
    $lease = $this->lease->fresh();          // holds 100,000

    $refund = DepositTransaction::create([
        'lease_id' => $lease->id,
        'tenant_id' => $lease->tenant_id,
        'asset_id' => $lease->unit?->asset_id,
        'type' => 'refund',
        'status' => 'recorded',
        'method' => 'bank',
        'amount' => 30000,
        'transaction_date' => CarbonImmutable::now()->toDateString(),
    ]);

    // The pot now reads 70,000 — but this row's own 30,000 is WHY, so correcting it upward must be
    // measured against the whole 100,000. A naive guard reading `depositHeld()` would cap the
    // correction at 70,000 and refuse a legitimate 90,000 restatement: the wrong direction, because
    // the row is lost and it reads as a bug rather than as a rule.
    expect($lease->fresh()->depositHeld())->toEqual(70000.0);

    $refund->update(['amount' => 90000]);
    expect($lease->fresh()->depositHeld())->toEqual(10000.0);

    // …and the cap still bites at the true ceiling.
    expect(fn () => $refund->update(['amount' => 100000.01]))->toThrow(DomainException::class);
});

it('lets the move-out settlement write the refund it just computed', function () {
    // The guard runs on every recorded refund INCLUDING the one `SettleMoveOutService` writes from
    // the locking twin, so a cap that were off by a rounding step would break the whole feature it
    // was added beside. The forfeit is written first and the refund takes the remainder, so each is
    // individually within the pot at the moment it is saved.
    app(SettleMoveOutService::class)->settle($this->lease->fresh(), [
        'settlement_date' => CarbonImmutable::now()->toDateString(),
        'settle_arrears' => false,
        'deductions' => [['description' => 'Repainting', 'amount' => 12500]],
    ]);

    $deposits = $this->lease->fresh()->deposits()->where('status', 'recorded')->get();

    expect((float) $deposits->where('type', 'forfeit')->sum('amount'))->toEqual(12500.0)
        ->and((float) $deposits->where('type', 'refund')->sum('amount'))->toEqual(87500.0)
        ->and($this->lease->fresh()->depositHeld())->toEqual(0.0);
});
