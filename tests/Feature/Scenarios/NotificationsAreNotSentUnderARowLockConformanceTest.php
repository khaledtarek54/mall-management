<?php

use Illuminate\Support\Str;
use Symfony\Component\Finder\Finder;

/**
 * Conformance — nothing under `app/` may deliver a notification while a transaction is open
 * (SW-213).
 *
 * A scheduled scan's shape here is: lock the row, re-check its stamp under the lock, alert, stamp.
 * The alert in the middle is a SYNCHRONOUS MailerSend round-trip per recipient — measured on the
 * shipped classes, 3 of the 38 notifications in `app/Notifications` implement `ShouldQueue` and 14
 * carry `AlsoSendsByMail` — so the X lock is held for the length of somebody else's SMTP. Nine
 * sites had it (both work-order SLA alerts, the tenant-request SLA alert, the overdue-invoice owner
 * alert, the lease-option window alert, the vendor-contract renewal alert, both document-expiry
 * scans and the low-stock alert), and `ScanOverdueInvoicesCommand` locks `invoices` — the table
 * every capture, credit-note application, deposit netting and write-off contends for.
 *
 * **The escape is `DB::afterCommit(fn () => Notification::send(...))`**, which this gate skips
 * whole: the callback runs inside `Connection::commit()`, after the level has been decremented, so
 * the lock is released first and a delivery failure still reaches the caller's own containment.
 * The idiom was already in the repo — `CreatePayment`/`EditPayment` use it for exactly this reason.
 *
 * WHY A GATE RATHER THAN A SHARED HELPER: there is no rule written out nine times to extract —
 * `DB::afterCommit()` is one framework call. What was written nine times was the MISTAKE, and a
 * gate is the only thing that can be its single home.
 *
 * Two things this deliberately does NOT do. It does not exempt a notification whose `via()` is
 * `['database']` today: `AlsoSendsByMail` was added to fourteen notifications after they were
 * written, so which channels one uses is not a property to build a lock's duration on. And it does
 * not try to follow a call graph — a service called from inside a transaction that notifies is out
 * of reach here, and `ConcurrencyPolicy` is where that question belongs.
 *
 * `DB::transaction(` is the only spelling of a transaction in `app/`: measured 2026-09-04, 158
 * occurrences across 105 files, zero `\DB::transaction`, zero `->transaction(` on anything else,
 * and exactly one `DB::beginTransaction()` — which the second test below covers separately, because
 * a gate that reads only the shape it already knows cannot see the shape it does not.
 */
$scan = function (string $source): array {
    if (! str_contains($source, 'DB::transaction')) {
        return ['transactions' => 0, 'hits' => []];
    }

    $tokens = token_get_all($source);
    $count = count($tokens);
    $transactions = 0;
    $hits = [];

    $closes = function (int $open) use ($tokens, $count): int {
        $depth = 0;

        for ($k = $open; $k < $count; $k++) {
            if ($tokens[$k] === '(') {
                $depth++;
            } elseif ($tokens[$k] === ')') {
                $depth--;

                if ($depth === 0) {
                    return $k;
                }
            }
        }

        return $count - 1;
    };

    /** @param list<string> $methods */
    $staticCall = function (int $k, string $class, array $methods) use ($tokens): bool {
        return is_array($tokens[$k]) && $tokens[$k][0] === T_STRING && $tokens[$k][1] === $class
            && is_array($tokens[$k + 1] ?? null) && $tokens[$k + 1][0] === T_DOUBLE_COLON
            && is_array($tokens[$k + 2] ?? null) && in_array($tokens[$k + 2][1] ?? '', $methods, true);
    };

    for ($i = 0; $i < $count; $i++) {
        if (! $staticCall($i, 'DB', ['transaction'])) {
            continue;
        }

        $open = $i;

        while ($open < $count && $tokens[$open] !== '(') {
            $open++;
        }

        $end = $closes($open);
        $transactions++;

        for ($k = $open; $k <= $end; $k++) {
            // Anything handed to DB::afterCommit() runs after the lock is released — skip it whole,
            // or the fix reads as the bug (the deferred send is still lexically inside the closure).
            if ($staticCall($k, 'DB', ['afterCommit'])) {
                $inner = $k;

                while ($inner <= $end && $tokens[$inner] !== '(') {
                    $inner++;
                }

                $k = $closes($inner);

                continue;
            }

            if (! is_array($tokens[$k])) {
                continue;
            }

            if ($staticCall($k, 'Notification', ['send', 'sendNow', 'route'])) {
                $hits[] = $tokens[$k][2].'  Notification::'.$tokens[$k + 2][1].'()';
            } elseif ($tokens[$k][0] === T_STRING
                && in_array($tokens[$k][1], ['notify', 'notifyNow'], true)
                && is_array($tokens[$k - 1] ?? null)
                && in_array($tokens[$k - 1][0], [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR], true)) {
                $hits[] = $tokens[$k][2].'  ->'.$tokens[$k][1].'()';
            }
        }

        $i = $end;
    }

    return ['transactions' => $transactions, 'hits' => $hits];
};

it('never delivers a notification inside an open transaction', function () use ($scan) {
    $offenders = [];
    $transactions = 0;

    foreach (Finder::create()->files()->name('*.php')->in(app_path()) as $file) {
        $result = $scan($file->getContents());
        $transactions += $result['transactions'];

        foreach ($result['hits'] as $hit) {
            $offenders[] = Str::after($file->getRealPath(), base_path().'/').':'.$hit;
        }
    }

    // The premise. A tokeniser that silently stopped matching would otherwise report a clean sweep
    // over nothing — the failure this project has already had three times.
    expect($transactions)->toBeGreaterThan(100,
        'the sweep found almost no transactions — it is not reading the code (158 at 2026-09-04)');

    expect($offenders)->toBe([], 'these send a notification while a row lock is still held — the '
        ."mail goes out inside the transaction and every writer of that row waits for it.\n"
        ."Wrap the send (and its own try/catch, if it has one) in DB::afterCommit(fn () => …):\n  "
        .implode("\n  ", $offenders));
});

it('does not let a hand-rolled transaction take the same shape', function () {
    // `DB::transaction()` is the only spelling the sweep above understands, so the one place that
    // opens a transaction by hand is checked separately rather than assumed harmless.
    $manual = [];

    foreach (Finder::create()->files()->name('*.php')->in(app_path()) as $file) {
        $source = $file->getContents();

        if (! str_contains($source, 'DB::beginTransaction')) {
            continue;
        }

        $path = Str::after($file->getRealPath(), base_path().'/');

        if (preg_match('/Notification::(send|sendNow|route)|->notifyNow?\(/', $source)) {
            $manual[] = $path;
        }
    }

    expect($manual)->toBe([], 'these open a transaction by hand AND deliver a notification — read '
        ."them and move the delivery after the commit:\n  ".implode("\n  ", $manual));
});
