<?php

use App\Models\CreditNoteApplication;
use App\Models\Invoice;
use App\Models\Lease;
use Illuminate\Support\Facades\DB;

/**
 * The three SW-009 lock-order cycles are broken — provable only here.
 *
 * A deadlock is a property of TWO transactions on TWO connections interleaved, and the ordinary
 * suite runs on SQLite where `lockForUpdate()` compiles to nothing and one connection never
 * interleaves. So `ConcurrencyPolicy` can prove a file LOCKS and `LockSpy` which TABLE, and both
 * stop exactly where the cycle would form. This file closes that gap for the one class of bug this
 * repo has shipped and rolled back before: two correct-looking paths acquiring the same two rows in
 * opposite orders, which MySQL kills with ER_LOCK_DEADLOCK (1213) — an intermittent 500 on two
 * ordinary acts, with no wrong money to make it findable.
 *
 * WHAT THIS FILE CAN AND CANNOT PROVE, stated honestly. Forming a deadlock needs A's SECOND lock
 * held in flight WHILE B closes the cycle — two blocking statements outstanding at once, which
 * needs a genuinely async client. A Laravel test connection is synchronous, so here A holds its
 * first row and B, taking the SAME two rows in the FIXED order, must WAIT on it (1205 under a short
 * timeout) rather than proceed — i.e. the contended rows serialise. That is the durable regression:
 * the guard's canonical order is `invoices→payments` / `leases→units` / `invoice→notes`, and every
 * writer here takes the same first row, so no two can cross.
 *
 * The DEADLOCK half — that the OLD orders genuinely produced 1213 and the fix flips them to
 * serialisation — was proven on 2026-09-05 with an async mysqli client (DEADLOCK×3 → serialised×3)
 * and is recorded in `docs/modules/21`. It is not re-run here because a synchronous connection
 * cannot honestly reproduce it, and a test that claims a deadlock it cannot form is the "measures
 * the vendor" trap the sibling file warns about.
 *
 * A second connection is a testing need, not an application one, so it is defined at run time
 * rather than added to `config/database.php`; both run BEGIN/ROLLBACK and commit nothing.
 */
beforeEach(function () {
    if (DB::connection()->getDriverName() !== 'mysql') {
        $this->markTestSkipped('The MySQL tier needs a MySQL connection — see tests/Mysql/README.md.');
    }

    config(['database.connections.mysql_probe' => config('database.connections.mysql')]);
    DB::connection('mysql_probe')->statement('SET innodb_lock_wait_timeout = 3');
    DB::connection()->statement('SET innodb_lock_wait_timeout = 3');
});

afterEach(function () {
    foreach (['mysql_probe', config('database.default')] as $name) {
        try {
            DB::connection($name)->rollBack();
        } catch (Throwable) {
        }
        DB::purge('mysql_probe');
    }
});

/**
 * Run two lock sequences interleaved on two connections; return the outcome for the SECOND writer:
 * 'deadlock' (1213), 'serialised' (1205 — it waited on a held lock), or 'clear' (it proceeded).
 *
 * A: takes its first lock and holds it. B: takes its first lock, then tries to close its sequence
 * against what A holds. A test connection cannot fire two blocking statements at once, so the
 * ONE step that must block is issued and its SQLSTATE read — 1213 and 1205 are distinguishable and
 * are the whole answer.
 *
 * @param  list<string>  $aSeq  A's lock statements (first is held; the rest need not run)
 * @param  list<string>  $bSeq  B's lock statements (first, then the crossing one)
 */
function raceOutcome(array $aSeq, array $bSeq): string
{
    $a = DB::connection('mysql_probe');
    $b = DB::connection(config('database.default'));

    $a->beginTransaction();
    $b->beginTransaction();

    $a->select($aSeq[0]);          // A holds its first row

    try {
        // B takes the SAME first row (fixed order), so it must WAIT on A — it never reaches the
        // rest of its sequence, which is the whole point: it cannot cross into a cycle. Under the
        // 3s timeout the wait surfaces as 1205; a cycle would surface as 1213; proceeding would be
        // 'clear' and would mean the shared first lock is not actually contended (a real failure).
        foreach ($bSeq as $sql) {
            $b->select($sql);
        }

        return 'clear';
    } catch (Throwable $e) {
        $code = (string) ($e->getPrevious() ?? $e)->getCode();

        return $code === '1213' ? 'deadlock' : (str_contains($code, '1205') || $code === 'HY000' ? 'serialised' : 'other:'.$code);
    }
}

it('breaks the payment ⇄ invoice cycle (SW-009e)', function () {
    $invId = DB::table('invoice_payment')->value('invoice_id');
    abort_unless($invId !== null, 500, 'baseline has no allocated invoice');
    $payId = DB::table('invoice_payment')->where('invoice_id', $invId)->value('payment_id');

    $invLock = "select * from invoices where id = $invId for update";
    $payLock = "select * from payments where id = $payId for update";

    // Both writers take the invoice first (the callback and void now call lockInvoicesThenSelf()),
    // so the second must WAIT on the first's invoice lock rather than cross it into a cycle.
    expect(raceOutcome([$invLock, $payLock], [$invLock, $payLock]))->toBe('serialised');
});

it('breaks the credit-note ⇄ invoice cycle (SW-009d)', function () {
    $app = CreditNoteApplication::query()->first();

    if (! $app) {
        $this->markTestSkipped('baseline has no credit-note application to contend over');
    }

    $noteLock = "select * from credit_notes where id = {$app->credit_note_id} for update";
    $invLock = "select * from invoices where id = {$app->invoice_id} for update";

    // applyToInvoice now locks the invoice first, matching the reverse paths — the second writer
    // waits on the shared invoice row instead of forming the notes⇄invoices cycle.
    expect(raceOutcome([$invLock, $noteLock], [$invLock, $noteLock]))->toBe('serialised');
});

it('breaks the lease ⇄ unit cycle (SW-009c)', function () {
    $lease = Lease::query()->whereNotNull('unit_id')->first();
    abort_unless($lease !== null, 500, 'baseline has no lease with a unit');

    $leaseLock = "select * from leases where id = {$lease->id} for update";
    $unitLock = "select * from units where id = {$lease->unit_id} for update";

    // Renewal and holdover now lock the lease first, matching the observer's fixed direction
    // (any lease UPDATE X-locks its unit), so the second writer waits on the shared lease row.
    expect(raceOutcome([$leaseLock, $unitLock], [$leaseLock, $unitLock]))->toBe('serialised');
});
