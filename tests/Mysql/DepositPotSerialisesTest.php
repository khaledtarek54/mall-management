<?php

use App\Models\Lease;
use Illuminate\Support\Facades\DB;

/**
 * The deposit pot really does serialise two writers — provable only here, and only by running the
 * application's own code.
 *
 * `SettleMoveOutService` writes a refund from `Lease::depositHeldForUpdate()`. The ordinary suite
 * runs on SQLite, where `lockForUpdate()` compiles to nothing and one connection never interleaves,
 * so no test there can show two settlements queueing: `LockSpy` proves which TABLE was locked and
 * stops exactly where the interesting part begins.
 *
 * **The first version of this file proved nothing, and it is worth saying why**, because it looked
 * exactly like a test that did. It asserted that `DB::table(...)->lockForUpdate()->toSql()` contains
 * `for update` — a statement about `MySqlGrammar::compileLock()` — and then held a hand-written
 * `select … for update` on one connection against another hand-written one, which is a statement
 * about InnoDB. Both passed with the entire change reverted. **A concurrency test that never calls
 * the method under test is a test of the database vendor**, and the module doc was citing it as
 * proof that the pot serialises.
 *
 * Two questions, because neither answers the other and this file's second version got that wrong
 * too. **What the app ASKS FOR** is data-independent and is the first test: every one of the three
 * terms must go out as a locking read. **Whether that ASK SERIALISES** needs rows to contend over —
 * `select … for update` matching nothing takes no row locks, and the QA baseline holds no
 * `deposit_applications` at all, so a blocking assertion over that table reported "read straight
 * through a held lock" when the truth was "there was nothing there to hold". A concurrency test that
 * quietly measures an empty table is the same failure as one that measures the vendor.
 *
 * The pairing is the point throughout. The locking twin must WAIT; the display twin `depositHeld()`
 * must NOT — that is the whole difference between the two methods, it is why the leases list can
 * render a hundred of them without contending with anything, and a test that only showed the first
 * would pass just as happily if every read in the app had been made a locking one.
 */
beforeEach(function () {
    if (DB::connection()->getDriverName() !== 'mysql') {
        $this->markTestSkipped('The MySQL tier needs a MySQL connection — see tests/Mysql/README.md.');
    }

    $this->lease = Lease::query()->whereHas('deposits')->first();

    if (! $this->lease) {
        $this->markTestSkipped('The baseline database holds no lease with a deposit movement.');
    }

    // A genuinely separate PDO connection, defined at run time rather than added to
    // config/database.php — a second connection is a testing need, not something the application
    // should carry. It plays the CONCURRENT writer; the code under test stays on the default
    // connection, so what is measured is the app's own queries and not the probe's.
    config(['database.connections.mysql_probe' => config('database.connections.mysql')]);
});

/** Every query the app issued while $run ran, in order. */
function queriesDuring(callable $run): array
{
    $seen = [];
    DB::listen(function ($q) use (&$seen) {
        $seen[] = $q->sql;
    });

    $run();

    return $seen;
}

/**
 * Hold a `FOR UPDATE` on one of the pot's tables, then run $read on the app's own connection.
 * Returns true when the read was made to WAIT.
 */
function blockedWhileHolding(string $table, int $leaseId, callable $read): bool
{
    $probe = DB::connection('mysql_probe');
    $probe->beginTransaction();

    try {
        $probe->select("select * from {$table} where lease_id = ? for update", [$leaseId]);

        // One second, so a genuine wait fails fast instead of hanging the suite.
        DB::statement('SET SESSION innodb_lock_wait_timeout = 1');

        try {
            $read();

            return false;
        } catch (Throwable $e) {
            // 1205 = lock wait timeout. Being made to wait IS the property under test.
            return str_contains($e->getMessage(), '1205')
                || str_contains(strtolower($e->getMessage()), 'lock wait');
        }
    } finally {
        $probe->rollBack();
        DB::statement('SET SESSION innodb_lock_wait_timeout = DEFAULT');
    }
}

it('asks for every term of the pot as a locking read, and asks for none of them on the display path', function () {
    $tables = ['deposit_transactions', 'deposit_applications', 'invoices'];

    $locking = queriesDuring(fn () => DB::transaction(
        fn () => Lease::find($this->lease->id)->depositHeldForUpdate()
    ));

    foreach ($tables as $table) {
        $touching = array_values(array_filter($locking, fn (string $sql) => str_contains($sql, "`{$table}`")));

        // Premise first: a term the twin never queried cannot be reported on.
        expect($touching)->not->toBeEmpty("depositHeldForUpdate() never read {$table}");

        expect(array_filter($touching, fn (string $sql) => str_contains($sql, 'for update')))
            ->not->toBeEmpty("depositHeldForUpdate() read {$table} without a lock");
    }

    // The control. Without it this file would be satisfied by an app that locked everything, which
    // would make every leases list contend with every settlement in progress.
    $plain = queriesDuring(fn () => Lease::find($this->lease->id)->depositHeld());

    expect($plain)->not->toBeEmpty()
        ->and(array_filter($plain, fn (string $sql) => str_contains($sql, 'for update')))
        ->toBeEmpty('depositHeld() is the display twin and must never take a lock');
});

it('makes the locking twin WAIT wherever there is actually a row to contend over', function () {
    $contended = 0;

    foreach (['deposit_transactions', 'deposit_applications', 'invoices'] as $table) {
        // `select … for update` matching nothing takes no row locks, so a table with no rows for
        // this lease can neither block nor prove anything. Counted rather than skipped silently.
        if (DB::table($table)->where('lease_id', $this->lease->id)->count() === 0) {
            continue;
        }

        $contended++;

        expect(blockedWhileHolding(
            $table,
            $this->lease->id,
            fn () => DB::transaction(fn () => Lease::find($this->lease->id)->depositHeldForUpdate()),
        ))->toBeTrue("depositHeldForUpdate() read {$table} straight through a held lock");

        // The same lock must NOT stop the leases list rendering.
        expect(blockedWhileHolding(
            $table,
            $this->lease->id,
            fn () => Lease::find($this->lease->id)->depositHeld(),
        ))->toBeFalse("depositHeld() waited on {$table} — the display twin must never take a lock");
    }

    // The premise, asserted rather than assumed: on a baseline where all three terms were empty
    // this test would otherwise report a pass having contended over nothing.
    expect($contended)->toBeGreaterThan(0, 'no term of the pot had a row to contend over');
});

it('makes the deposit-billing guard wait too', function () {
    // `BillSecurityDepositService` decides whether to raise a SECOND deposit invoice from
    // `depositUnbilledShortfallForUpdate()`. Its own lock on the lease row is not the guard: the
    // question is about invoice rows a concurrent operator may just have written.
    expect(DB::table('invoices')->where('lease_id', $this->lease->id)->count())
        ->toBeGreaterThan(0, 'the fixture lease has no invoices to contend over');

    expect(blockedWhileHolding(
        'invoices',
        $this->lease->id,
        fn () => DB::transaction(fn () => Lease::find($this->lease->id)->depositUnbilledShortfallForUpdate()),
    ))->toBeTrue('the deposit-billing guard read the invoices straight through a held lock');

    expect(blockedWhileHolding(
        'invoices',
        $this->lease->id,
        fn () => Lease::find($this->lease->id)->depositUnbilledShortfall(),
    ))->toBeFalse('the display twin must not take a lock');
});
