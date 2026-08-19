<?php

/** QA harness — shared helpers. Runs inside `artisan tinker --execute` against mall_management_qa. */

use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\User;
use App\Services\Accounting\AccountResolver;
use App\Services\Accounting\LedgerPoster;
use Illuminate\Support\Facades\Auth;

if (! isset($GLOBALS['QA_BOOTED'])) {
    $GLOBALS['QA_BOOTED'] = true;
    $GLOBALS['QA_PASS'] = 0;
    $GLOBALS['QA_FAIL'] = 0;
    $GLOBALS['QA_FAILS'] = [];

    if (config('database.connections.mysql.database') !== 'mall_management_qa') {
        throw new RuntimeException('REFUSING TO RUN: not on the QA database ('.config('database.connections.mysql.database').')');
    }

    Auth::login(User::where('email', 'admin@mall.test')->firstOrFail());
}

function qa_section(string $n): void
{
    echo "\n\033[1;36m### $n\033[0m\n";
}

function qa_ok(string $label, bool $cond, ?string $detail = null): bool
{
    if ($cond) {
        $GLOBALS['QA_PASS']++;
        echo "  \033[32mPASS\033[0m  $label".($detail ? "  — $detail" : '')."\n";
    } else {
        $GLOBALS['QA_FAIL']++;
        $GLOBALS['QA_FAILS'][] = $label.($detail ? " — $detail" : '');
        echo "  \033[31mFAIL\033[0m  $label".($detail ? "  — $detail" : '')."\n";
    }

    return $cond;
}

function qa_eq(string $label, $expected, $actual, float $tol = 0.005): bool
{
    $cond = is_numeric($expected) && is_numeric($actual)
        ? abs((float) $expected - (float) $actual) <= $tol
        : $expected === $actual;

    return qa_ok($label, $cond, 'expected='.qa_s($expected).' actual='.qa_s($actual));
}

function qa_s($v): string
{
    if (is_bool($v)) {
        return $v ? 'true' : 'false';
    }
    if (is_null($v)) {
        return 'null';
    }
    if (is_array($v)) {
        return json_encode($v);
    }
    if (is_float($v) || (is_numeric($v) && str_contains((string) $v, '.'))) {
        return number_format((float) $v, 2, '.', '');
    }

    return (string) $v;
}

/** Assert a callable throws (refusal). $expect = substring of message, or null for any. */
function qa_refuses(string $label, callable $fn, ?string $expect = null, string $class = DomainException::class): bool
{
    try {
        $fn();
    } catch (Throwable $e) {
        if (! ($e instanceof $class)) {
            return qa_ok($label, false, 'wrong exception '.get_class($e).': '.$e->getMessage());
        }
        if ($expect !== null && ! str_contains(mb_strtolower($e->getMessage()), mb_strtolower($expect))) {
            return qa_ok($label, false, "message did not contain '$expect': ".$e->getMessage());
        }

        return qa_ok($label, true, 'refused: '.mb_substr($e->getMessage(), 0, 110));
    }

    return qa_ok($label, false, 'NO refusal — the call succeeded');
}

/** Assert a callable does NOT throw; returns its value. */
function qa_allows(string $label, callable $fn)
{
    try {
        $v = $fn();
        qa_ok($label, true);

        return $v;
    } catch (Throwable $e) {
        qa_ok($label, false, get_class($e).': '.$e->getMessage());

        return null;
    }
}

function qa_summary(): void
{
    $p = $GLOBALS['QA_PASS'];
    $f = $GLOBALS['QA_FAIL'];
    echo "\n".str_repeat('=', 78)."\n";
    echo "  \033[32m$p passed\033[0m   ".($f ? "\033[31m$f FAILED\033[0m" : '0 failed')."\n";
    foreach ($GLOBALS['QA_FAILS'] as $i => $m) {
        echo '  '.($i + 1).") $m\n";
    }
    echo str_repeat('=', 78)."\n";
}

/* ───────────────────────── accounting tie-outs ───────────────────────── */

/** Trial balance must balance across every posted/reportable journal entry. */
function qa_trial_balance(?int $assetId = null): array
{
    $q = JournalLine::query()
        ->join('journal_entries', 'journal_entries.id', '=', 'journal_lines.journal_entry_id')
        ->whereIn('journal_entries.status', JournalEntry::REPORTABLE_STATUSES);
    if ($assetId) {
        $q->where('journal_entries.asset_id', $assetId);
    }
    $r = $q->selectRaw('sum(debit) d, sum(credit) c')->first();

    return ['debit' => (float) $r->d, 'credit' => (float) $r->c];
}

function qa_assert_tb(string $label, ?int $assetId = null): void
{
    $tb = qa_trial_balance($assetId);
    qa_ok("TB balances: $label", abs($tb['debit'] - $tb['credit']) < 0.01,
        'Dr '.number_format($tb['debit'], 2).' / Cr '.number_format($tb['credit'], 2));
}

/** Sum of a role's GL balance (debit - credit) over reportable entries. */
function qa_role_balance(string $role, ?int $assetId = null): float
{
    $accountId = app(AccountResolver::class)->id($role);
    if (! $accountId) {
        return NAN;
    }
    $q = JournalLine::query()
        ->join('journal_entries', 'journal_entries.id', '=', 'journal_lines.journal_entry_id')
        ->whereIn('journal_entries.status', JournalEntry::REPORTABLE_STATUSES)
        ->where('journal_lines.ledger_account_id', $accountId);
    if ($assetId) {
        $q->where('journal_entries.asset_id', $assetId);
    }
    $r = $q->selectRaw('sum(debit) d, sum(credit) c')->first();

    return (float) $r->d - (float) $r->c;
}

/** Re-derive the GL for one model instance and return its posted entry. */
function qa_sync($model): ?JournalEntry
{
    app(LedgerPoster::class)->sync($model);

    return JournalEntry::where('source_type', $model->getMorphClass())
        ->where('source_id', $model->getKey())
        ->where('status', 'posted')->latest('id')->first();
}

/** Pretty print an entry's lines. */
function qa_dump_entry(?JournalEntry $e, string $label = ''): void
{
    if (! $e) {
        echo "    (no entry) $label\n";

        return;
    }
    echo "    ENTRY #{$e->id} {$e->entry_date} status={$e->status} asset={$e->asset_id} $label\n";
    foreach ($e->lines as $l) {
        printf("      %-34s Dr %12s  Cr %12s\n", $l->account?->code.' '.mb_substr((string) $l->account?->name, 0, 26),
            number_format((float) $l->debit, 2), number_format((float) $l->credit, 2));
    }
}
