<?php

namespace App\Services\Banking;

use App\Models\BankStatement;
use App\Models\BankStatementLine;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * Ingest a bank statement's rows — slice 2 of bank reconciliation.
 *
 * **Idempotent by construction, because a re-import is normal.** Operators export overlapping date
 * ranges and import them again; the same row must land on the same record rather than double the
 * statement. Identity is date + amount + reference + description + OCCURRENCE, hashed and uniquely
 * indexed, so the guarantee is the database's rather than this class's. The occurrence number is
 * what keeps a bank's genuine duplicate — two identical fees on one day — importable, instead of
 * collapsing it and losing money from the evidence.
 *
 * **It posts nothing.** A statement is the outside world's account of what moved; this stores it.
 * Matching it to the books is slice 3, and no part of importing may touch a balance.
 *
 * @see docs/accounting/BANK-RECONCILIATION-PLAN.md
 */
class ImportBankStatementService
{
    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @return array{imported: int, skipped: int}
     */
    public function import(BankStatement $statement, array $rows): array
    {
        $imported = 0;
        $skipped = 0;

        // Occurrence is counted per (date, amount, reference, description) WITHIN this file, so the
        // second identical row gets occurrence 2 — deterministically, every run.
        $seen = [];

        DB::transaction(function () use ($statement, $rows, &$imported, &$skipped, &$seen) {
            foreach ($rows as $i => $row) {
                $date = trim((string) ($row['value_date'] ?? ''));
                if ($date === '') {
                    throw new DomainException(__('admin.errors.bank_statement_row_no_date', ['row' => $i + 1]));
                }

                $valueDate = CarbonImmutable::parse($date)->toDateString();
                $amount = round((float) ($row['amount'] ?? 0), 2);

                if ($amount === 0.0) {
                    // Not money moving. Skipped rather than refused: real exports carry
                    // balance-brought-forward and header rows, and failing a whole import over one
                    // is how an operator gives up on the feature.
                    $skipped++;

                    continue;
                }

                $reference = $row['reference'] ?? null;
                $description = $row['description'] ?? null;

                $key = $valueDate.'|'.$amount.'|'.mb_strtolower(trim((string) $reference))
                    .'|'.mb_strtolower(trim((string) $description));
                $seen[$key] = ($seen[$key] ?? 0) + 1;

                $hash = BankStatementLine::hashFor($valueDate, $amount, $reference, $description, $seen[$key]);

                $exists = BankStatementLine::query()
                    ->where('bank_statement_id', $statement->id)
                    ->where('row_hash', $hash)
                    ->exists();

                if ($exists) {
                    $skipped++;

                    continue;
                }

                BankStatementLine::create([
                    'bank_statement_id' => $statement->id,
                    'value_date' => $valueDate,
                    'description' => $description,
                    'reference' => $reference,
                    'amount' => $amount,
                    'running_balance' => ($row['running_balance'] ?? '') !== ''
                        ? round((float) $row['running_balance'], 2)
                        : null,
                    'row_hash' => $hash,
                ]);

                $imported++;
            }
        });

        return ['imported' => $imported, 'skipped' => $skipped];
    }

    /**
     * Parse a CSV into the row shape {@see import()} wants.
     *
     * Deliberately forgiving about HEADINGS and strict about VALUES: Egyptian bank exports disagree
     * about what a column is called and agree about what a date and an amount look like. A
     * debit/credit pair is folded into one signed amount here, so nothing downstream ever sees two
     * columns that can contradict each other.
     *
     * @return array<int, array<string, mixed>>
     */
    public function parseCsv(string $contents): array
    {
        $lines = preg_split('/\r\n|\r|\n/', trim($contents)) ?: [];
        if (count($lines) < 2) {
            throw new DomainException(__('admin.errors.bank_statement_csv_empty'));
        }

        $header = array_map(
            fn ($h) => mb_strtolower(trim((string) $h, " \t\n\r\0\x0B\"'")),
            str_getcsv((string) array_shift($lines))
        );

        $find = function (array $names) use ($header): ?int {
            foreach ($names as $name) {
                $i = array_search($name, $header, true);
                if ($i !== false) {
                    return (int) $i;
                }
            }

            return null;
        };

        $dateAt = $find(['date', 'value date', 'value_date', 'transaction date', 'posting date', 'التاريخ']);
        $amountAt = $find(['amount', 'value', 'المبلغ']);
        $debitAt = $find(['debit', 'withdrawal', 'مدين']);
        $creditAt = $find(['credit', 'deposit', 'دائن']);
        $refAt = $find(['reference', 'ref', 'cheque', 'cheque no', 'المرجع']);
        $descAt = $find(['description', 'details', 'narrative', 'particulars', 'البيان']);
        $balAt = $find(['balance', 'running balance', 'الرصيد']);

        if ($dateAt === null || ($amountAt === null && $debitAt === null && $creditAt === null)) {
            throw new DomainException(__('admin.errors.bank_statement_csv_columns'));
        }

        $rows = [];
        foreach ($lines as $line) {
            if (trim($line) === '') {
                continue;
            }

            $cells = str_getcsv($line);
            $get = fn (?int $i) => $i === null ? null : trim((string) ($cells[$i] ?? ''));

            if ($amountAt !== null && $get($amountAt) !== '') {
                $amount = self::toFloat($get($amountAt));
            } else {
                // Debit/credit pair → one signed number. Money out is negative, so the statement's
                // own arithmetic (opening + Σ = closing) holds with no further sign handling.
                $amount = self::toFloat($get($creditAt)) - self::toFloat($get($debitAt));
            }

            $rows[] = [
                'value_date' => $get($dateAt),
                'amount' => $amount,
                'reference' => $get($refAt) ?: null,
                'description' => $get($descAt) ?: null,
                'running_balance' => $balAt !== null && $get($balAt) !== '' ? self::toFloat($get($balAt)) : null,
            ];
        }

        return $rows;
    }

    /** "1,234.56", "(1,234.56)" and "1 234,56" all mean a number to a bank export. */
    private static function toFloat(?string $value): float
    {
        $value = trim((string) $value);
        if ($value === '') {
            return 0.0;
        }

        $negative = str_starts_with($value, '(') && str_ends_with($value, ')');
        $value = trim($value, '()');
        $value = preg_replace('/[^\d,.\-]/u', '', $value) ?? '';

        // A comma is a thousands separator when a dot is present too, and a decimal point when it is
        // the only separator — the difference between 1,234.56 and 1234,56.
        if (str_contains($value, '.') && str_contains($value, ',')) {
            $value = str_replace(',', '', $value);
        } elseif (str_contains($value, ',') && ! str_contains($value, '.')) {
            $value = str_replace(',', '.', $value);
        }

        $number = (float) $value;

        return $negative ? -abs($number) : $number;
    }
}
