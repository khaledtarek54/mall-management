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
 *
 * **A DELIVERY WRAPPER IS DERIVED, NOT LISTED (SW-248, 2026-09-10).** `->notify(` was not the only
 * spelling of a send either: `Tenant::notifyPortal()` fans one notification out to the company and
 * every portal login, and three sites called IT inside a transaction, under `lockForUpdate()`,
 * before their stamp — two dunning commands and the late-fee service — while this gate reported a
 * clean sweep. The wrappers are read off `app/Models`: any method declared on a model whose own body
 * delivers directly is one, and a call to it inside a transaction is a hit exactly as `->notify(` is.
 * Models only, deliberately — a service method that notifies is the call-graph question the
 * paragraph above leaves to `ConcurrencyPolicy`, and the wrappers that exist are all on models.
 */
/**
 * Every method declared under `app/Models` whose own body delivers a notification directly — the
 * names a call site can use INSTEAD of `->notify(` and mean the same thing.
 *
 * @return list<string>
 */
$deliveryWrappers = function (): array {
    $names = [];

    foreach (Finder::create()->files()->name('*.php')->in(app_path('Models')) as $file) {
        $source = $file->getContents();

        if (! preg_match('/Notification::(send|sendNow|route)\(|->notify(Now)?\(/', $source)) {
            continue;
        }

        // Comments are in the token stream — a method whose body merely MENTIONS `->notify(` in
        // a `//` line would otherwise be derived (the prose false-positive CLAUDE.md records three
        // times). Strings too: nothing a wrapper does lives inside one.
        $tokens = array_values(array_filter(token_get_all($source), fn ($t) => ! is_array($t)
            || ! in_array($t[0], [T_COMMENT, T_DOC_COMMENT, T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE], true)));
        $count = count($tokens);

        for ($i = 0; $i < $count; $i++) {
            if (! is_array($tokens[$i]) || $tokens[$i][0] !== T_FUNCTION) {
                continue;
            }

            // A wrapper is something ANOTHER class can call, so it is public — `booted()` calls a
            // service's `->notify()` and is a lifecycle hook, not a spelling of a send; a private
            // static helper is reachable only from its own model's events.
            $public = false;

            for ($b = $i - 1; $b > 0 && $b > $i - 6; $b--) {
                if (! is_array($tokens[$b])) {
                    break;
                }
                if ($tokens[$b][0] === T_PUBLIC) {
                    $public = true;
                    break;
                }
                if (! in_array($tokens[$b][0], [T_WHITESPACE, T_STATIC, T_FINAL, T_ABSTRACT], true)) {
                    break;
                }
            }

            if (! $public) {
                continue;
            }

            $name = null;

            for ($n = $i + 1; $n < $count && $tokens[$n] !== '('; $n++) {
                if (is_array($tokens[$n]) && $tokens[$n][0] === T_STRING) {
                    $name = $tokens[$n][1];
                }
            }

            // A closure has no name; `notify` itself is Laravel's, not a wrapper of it.
            if ($name === null || in_array($name, ['notify', 'notifyNow'], true)) {
                continue;
            }

            $open = $n;

            while ($open < $count && $tokens[$open] !== '{') {
                // An abstract or interface method ends at `;` before any body.
                if ($tokens[$open] === ';') {
                    continue 2;
                }
                $open++;
            }

            $depth = 0;
            $body = '';

            for ($k = $open; $k < $count; $k++) {
                $body .= is_array($tokens[$k]) ? $tokens[$k][1] : $tokens[$k];

                if ($tokens[$k] === '{' || (is_array($tokens[$k]) && in_array($tokens[$k][0], [T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES], true))) {
                    $depth++;
                } elseif ($tokens[$k] === '}') {
                    $depth--;

                    if ($depth === 0) {
                        break;
                    }
                }
            }

            if (preg_match('/Notification::(send|sendNow|route)\(|->notify(Now)?\(/', $body)) {
                $names[] = $name;
            }

            $i = $k;
        }
    }

    return array_values(array_unique($names));
};

$scan = function (string $source, array $wrappers = []): array {
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
                && in_array($tokens[$k][1], ['notify', 'notifyNow', ...$wrappers], true)
                && is_array($tokens[$k - 1] ?? null)
                && in_array($tokens[$k - 1][0], [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON], true)) {
                $hits[] = $tokens[$k][2].'  ->'.$tokens[$k][1].'()';
            }
        }

        $i = $end;
    }

    return ['transactions' => $transactions, 'hits' => $hits];
};

it('never delivers a notification inside an open transaction', function () use ($scan, $deliveryWrappers) {
    $wrappers = $deliveryWrappers();

    // The premise for the wrapper half: the derivation must find the one wrapper this gate was
    // blind to, or it is matching `->notify(` alone again and reporting a clean sweep over the
    // three sites SW-248 found — and it must NOT be picking up lifecycle hooks: `TenantRequest::
    // booted()` calls a SERVICE's `->notify()`, and a derivation that counted it would flag every
    // transaction body naming `booted`, which is how a gate that fires about nothing gets
    // switched off.
    expect($wrappers)->toContain('notifyPortal')
        ->and($wrappers)->not->toContain('booted');

    $offenders = [];
    $transactions = 0;

    foreach (Finder::create()->files()->name('*.php')->in(app_path()) as $file) {
        $result = $scan($file->getContents(), $wrappers);
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
        ."Wrap the send (and its own try/catch, if it has one) in DB::afterCommit(fn () => …). A queued\n"
        ."notification is NOT exempt: the push is transactional on the database driver only.\n  "
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
