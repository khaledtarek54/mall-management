<?php

namespace Tests\Support;

use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\Grammars\SQLiteGrammar;
use Illuminate\Support\Facades\DB;

/**
 * Makes `lockForUpdate()` observable on SQLite, so a concurrency guard can be tested instead of
 * merely declared.
 *
 * The problem: `SQLiteGrammar::compileLock()` returns `''`
 * (vendor/laravel/framework/…/Query/Grammars/SQLiteGrammar.php:31-34), and the suite runs on sqlite
 * `:memory:`. So **every one of the ~110 `lockForUpdate()` call sites is inert in every test**, and
 * deleting one turns nothing red. Production is fine — MySQL honours the locks — but concurrency
 * became the one invariant class in CLAUDE.md with no way to prove a guard is still there. It is
 * also the class this project has already been bitten by twice: the unit double-booking race and
 * the Paymob double-charge race.
 *
 * The trick: a lock clause is appended to the compiled SELECT, and **a SQL comment is valid SQLite**.
 * So the grammar emits an inline `lock:for-update` comment where MySQL would emit `for update`. The
 * statement still executes exactly as before — same rows, same speed, no schema or driver change — while
 * `DB::listen()` can now see which tables a code path locked, on the real call path, through the
 * real service.
 *
 * That converts the gate from "the source contains the string lockForUpdate" into "this service
 * took a row lock on this table". Removing the lock makes the test fail, which is the whole point.
 *
 * Only a spy: it does not make SQLite actually lock anything, and it cannot prove two concurrent
 * transactions serialise correctly. That needs MySQL and two connections, and is stated as
 * out-of-scope in `App\Support\ConcurrencyPolicy` rather than pretended.
 */
class LockSpy
{
    /** @var array<int, array{table: string, mode: string, sql: string}> */
    private array $locks = [];

    private bool $listening = false;

    /**
     * Run $callback with lock capture enabled and return the spy for assertions.
     */
    public static function watch(callable $callback): self
    {
        $spy = new self;
        $spy->start();

        try {
            $callback();
        } finally {
            $spy->stop();
        }

        return $spy;
    }

    private function start(): void
    {
        $connection = DB::connection();

        if (! $connection->getQueryGrammar() instanceof SQLiteGrammar) {
            // On MySQL the real clause is emitted and the same listener reads it — nothing to swap.
            $this->listen();

            return;
        }

        $connection->setQueryGrammar(new class($connection) extends SQLiteGrammar
        {
            /**
             * `$value` is `true` for lockForUpdate(), `false` for sharedLock(), or a raw string.
             * A comment keeps the statement executable on SQLite while making the intent visible.
             */
            protected function compileLock(Builder $query, $value): string
            {
                if (is_string($value)) {
                    return ' /* lock:raw */';
                }

                return $value ? ' /* lock:for-update */' : ' /* lock:shared */';
            }
        });

        $this->listen();
    }

    private function listen(): void
    {
        if ($this->listening) {
            return;
        }

        $this->listening = true;

        DB::listen(function ($query) {
            $sql = $query->sql;

            $mode = match (true) {
                str_contains($sql, 'lock:for-update'), str_contains(strtolower($sql), 'for update') => 'for-update',
                str_contains($sql, 'lock:shared'), str_contains(strtolower($sql), 'lock in share mode') => 'shared',
                str_contains($sql, 'lock:raw') => 'raw',
                default => null,
            };

            if ($mode === null) {
                return;
            }

            $this->locks[] = ['table' => $this->tableIn($sql), 'mode' => $mode, 'sql' => $sql];
        });
    }

    private function stop(): void
    {
        // The grammar stays swapped for the rest of the test: it is behaviour-neutral (a comment),
        // and restoring it would need the connection's original instance, which nothing else needs.
        // RefreshDatabase rebuilds the connection between tests.
        $this->listening = false;
    }

    private function tableIn(string $sql): string
    {
        return preg_match('/\bfrom\s+["`]?([a-z0-9_]+)["`]?/i', $sql, $m) ? $m[1] : '?';
    }

    /** Did this code path take a row lock on $table? */
    public function locked(string $table, string $mode = 'for-update'): bool
    {
        return collect($this->locks)
            ->contains(fn (array $l): bool => $l['table'] === $table && $l['mode'] === $mode);
    }

    /** @return array<int, string> tables locked, in order, for a readable failure message */
    public function lockedTables(): array
    {
        return collect($this->locks)->pluck('table')->unique()->values()->all();
    }

    public function count(): int
    {
        return count($this->locks);
    }
}
