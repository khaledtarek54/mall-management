<?php

namespace App\Services\Accounting;

use App\Models\JournalEntry;
use App\Models\LedgerAccount;
use Carbon\CarbonImmutable;
use DomainException;

/**
 * Load an operator's opening trial balance at go-live, from the spreadsheet their accountant
 * already has.
 *
 * **What was missing, precisely.** Opening AR arrives through `OpeningInvoiceImporter` and opening
 * fixed assets through `FixedAssetImporter` — so the two SUB-ledgers were covered and the general
 * ledger was not. Cash, bank, AP, accruals, capital and retained earnings had to be typed into the
 * manual journal screen one line at a time, from a trial balance that is routinely forty rows.
 * Nobody types forty balanced rows without a mistake, and a mistake in an opening balance is the
 * one that follows every report forever.
 *
 * ## It produces a DRAFT, deliberately
 *
 * The obvious build posts straight to the ledger. This does not, for two reasons that matter more
 * than the extra click:
 *
 *  - **An import run twice would double the entire balance sheet.** As a draft, a second run is a
 *    second draft sitting next to the first — visible, comparable, deletable. As a posted entry it
 *    is silent, and the correction is a reversing entry against a book that was never right.
 *  - **An opening balance is the accountant's assertion, not the software's.** Review-then-post is
 *    how every ERP loads one, and this project already has that workflow: the journal-entry screen
 *    posts a draft through `JournalPostingService::postDraft()`, which is where the closed-period
 *    guard lives. Reusing it means the import cannot invent a second way into the ledger.
 *
 * Drafts also sidestep the period question honestly: `post()` does not enforce an open period for a
 * draft, so an opening balance can be PREPARED before the fiscal year is opened and posted after —
 * which is the real sequence at go-live.
 *
 * ## What it does NOT re-implement
 *
 * Almost everything. `JournalPostingService::post()` already refuses an unknown account, a summary
 * (non-postable) account, an inactive account, a negative amount, a line with both debit and
 * credit, a line with neither, and an unbalanced entry. This class parses text into that shape and
 * gets out of the way — a second copy of those rules would be a second answer to the same question.
 */
class ImportOpeningBalancesService
{
    public function __construct(private JournalPostingService $journals) {}

    /**
     * Parse the pasted trial balance WITHOUT writing anything.
     *
     * Errors are collected per row rather than thrown, because the operator needs to see every bad
     * line at once — fixing a forty-row paste one exception at a time is forty round trips.
     *
     * @return array{
     *     rows: array<int, array{line:int, code:string, name:?string, debit:float, credit:float, error:?string}>,
     *     debit: float, credit: float, balanced: bool, errors: array<int, string>
     * }
     */
    public function preview(string $raw): array
    {
        $rows = [];
        $errors = [];
        $debit = 0.0;
        $credit = 0.0;

        foreach ($this->parse($raw) as $i => $parsed) {
            $lineNo = $i + 1;
            [$code, $d, $c] = $parsed;

            $account = LedgerAccount::query()->where('code', $code)->first();

            $error = match (true) {
                $account === null => __('admin.opening_balances.errors.unknown_account', ['code' => $code]),
                ! $account->is_postable => __('admin.opening_balances.errors.summary_account', ['code' => $code]),
                ! $account->is_active => __('admin.opening_balances.errors.inactive_account', ['code' => $code]),
                $d > 0 && $c > 0 => __('admin.opening_balances.errors.both_sides', ['code' => $code]),
                $d <= 0 && $c <= 0 => __('admin.opening_balances.errors.no_amount', ['code' => $code]),
                $d < 0 || $c < 0 => __('admin.opening_balances.errors.negative', ['code' => $code]),
                default => null,
            };

            if ($error !== null) {
                $errors[] = __('admin.opening_balances.errors.at_line', ['line' => $lineNo, 'error' => $error]);
            } else {
                $debit += $d;
                $credit += $c;
            }

            $rows[] = [
                'line' => $lineNo,
                'code' => $code,
                // `LedgerAccount` has NO `name` column: the chart carries `name_en` and `name_ar`
                // (2026_06_30_000001_create_ledger_accounts_table), so `$account?->name` resolved
                // to null for EVERY row — Eloquent returns null for an attribute that is not there
                // rather than failing. Measured at HEAD (2026-09-04) against the seeded chart:
                // every parsed row came back `name => null`.
                //
                // It was silent twice over. The preview's `@if ($row['name'])` guard printed the
                // bare account code, so the operator checking a forty-row paste never saw WHICH
                // account each code is — the one thing the preview exists to confirm before an
                // opening balance is committed. And `import()` copies this onto every line of the
                // draft entry, so all forty landed with `description => null`: on a general ledger
                // a missing description is indistinguishable from an entry nobody described.
                //
                // `displayName()` is the ONE locale-aware reading of an account's name (the picker,
                // the report filters and the posting map all take it) and it falls back to the other
                // language rather than returning null, which a half-translated imported chart needs.
                // The line description is a snapshot in the importing operator's language, exactly
                // like the one they would have typed on the manual journal screen this replaces.
                'name' => $account?->displayName(),
                'debit' => $d,
                'credit' => $c,
                'error' => $error,
            ];
        }

        $debit = round($debit, 2);
        $credit = round($credit, 2);

        return [
            'rows' => $rows,
            'debit' => $debit,
            'credit' => $credit,
            // Only meaningful once every row parses; an unbalanced total caused by a rejected row
            // would otherwise read as a balancing error the operator cannot find.
            'balanced' => $errors === [] && $rows !== [] && abs($debit - $credit) < 0.005,
            'errors' => $errors,
        ];
    }

    /**
     * Turn a validated trial balance into ONE draft journal entry.
     *
     * @throws DomainException when the paste is empty, malformed, or does not balance
     */
    public function import(string $raw, CarbonImmutable $on, ?int $assetId = null): JournalEntry
    {
        $preview = $this->preview($raw);

        if ($preview['rows'] === []) {
            throw new DomainException(__('admin.opening_balances.errors.empty'));
        }

        if ($preview['errors'] !== []) {
            throw new DomainException(implode(' · ', array_slice($preview['errors'], 0, 5)));
        }

        if (! $preview['balanced']) {
            throw new DomainException(__('admin.opening_balances.errors.unbalanced', [
                'debit' => number_format($preview['debit'], 2),
                'credit' => number_format($preview['credit'], 2),
            ]));
        }

        return $this->journals->post([
            'entry_date' => $on->toDateString(),
            'description_en' => 'Opening balances as at '.$on->toDateString(),
            'description_ar' => 'الأرصدة الافتتاحية كما في '.$on->toDateString(),
            'asset_id' => $assetId,
            'is_manual' => true,
            // The whole point — see the class docblock. Posting is the accountant's act, through
            // the journal-entry screen, which is also where the closed-period guard runs.
            'status' => 'draft',
            'lines' => array_map(fn (array $r) => [
                'account_code' => $r['code'],
                'debit' => $r['debit'],
                'credit' => $r['credit'],
                'asset_id' => $assetId,
                'description' => $r['name'],
            ], $preview['rows']),
        ]);
    }

    /**
     * `code, debit, credit` per line — tab, semicolon or comma separated.
     *
     * Tolerant on purpose: this is pasted out of Excel, so it accepts the separator Excel actually
     * produced, ignores blank lines, skips a header row, and strips the thousands separators and
     * currency symbols a spreadsheet leaves behind. Being strict here would just mean the operator
     * hand-cleans the paste, which is the error-prone step this exists to remove.
     *
     * @return array<int, array{0:string, 1:float, 2:float}>
     */
    private function parse(string $raw): array
    {
        $out = [];

        foreach (preg_split('/\r\n|\r|\n/', trim($raw)) ?: [] as $line) {
            $line = trim($line);

            if ($line === '') {
                continue;
            }

            $cells = $this->cells($line);

            if (count($cells) < 2) {
                continue;
            }

            $code = $cells[0];

            // A header row: the first cell is not an account code. Detected by shape rather than by
            // matching the word "code", because the sheet may be in Arabic.
            if (! preg_match('/^[0-9][0-9.\-]*$/', $code)) {
                continue;
            }

            $out[] = [
                $code,
                $this->amount($cells[1] ?? ''),
                $this->amount($cells[2] ?? ''),
            ];
        }

        return $out;
    }

    /**
     * Split ONE line into cells, choosing a single separator rather than accepting all of them.
     *
     * Splitting on `[\t;,]` at once looked tolerant and was wrong: a tab-separated line carrying
     * `1,250,000.50` — which is what Excel puts on the clipboard — was shredded into three cells,
     * and a 1.25m opening balance silently read as 1. The separator is a property of the LINE, so
     * it is decided once per line, most-specific first.
     *
     * Comma lines go through `str_getcsv`, which honours the quoting a real CSV export uses for a
     * value containing a comma. An UNQUOTED `1,250,000.50` in a comma-separated line stays
     * genuinely ambiguous — nothing can tell it from three fields — and is left to fail loudly in
     * validation rather than be guessed at.
     *
     * @return array<int, string>
     */
    private function cells(string $line): array
    {
        if (str_contains($line, "\t")) {
            return array_map('trim', explode("\t", $line));
        }

        if (str_contains($line, ';')) {
            return array_map('trim', explode(';', $line));
        }

        return array_map(fn ($c) => trim((string) $c), str_getcsv($line, ',', '"', '\\'));
    }

    /** "1,234.50 EGP" → 1234.50; anything unreadable → 0.0. */
    private function amount(string $cell): float
    {
        $clean = preg_replace('/[^0-9.\-]/', '', str_replace(',', '', $cell));

        return $clean === '' || $clean === '-' ? 0.0 : round((float) $clean, 2);
    }
}
