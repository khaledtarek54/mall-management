<?php

namespace App\Support;

/**
 * The ONE reading of a money figure typed into a spreadsheet cell.
 *
 * Three importers had their own, and two of them were byte-identical private methods — the shape
 * this codebase keeps recording as "a rule written twice is a rule that drifts". They drifted in
 * OPPOSITE directions, so a cell one importer read as a thousand the next read as a decimal.
 *
 * ## What was measured (2026-09-04, against HEAD before this change)
 *
 * `ImportBankStatementService::toFloat()` read a lone comma as a decimal point, always:
 *
 *     '1,234,567' → 1.234       a 1,234,567.00 transfer imported as one pound twenty-three
 *     '12,500'    → 12.50
 *     '(1,250)'   → -1.25
 *     '1.234,56'  → 1.23456     the European form its own docblock claimed to handle
 *
 * `ImportOpeningBalancesService::amount()` and `BudgetService::amount()` stripped every comma,
 * always — the same mistake through the other door. Both of those split a pasted sheet on `;` when
 * it contains one, i.e. they go out of their way to accept the European export whose numbers they
 * then destroyed:
 *
 *     '1.234,56'  → 1.23456
 *     '1 234,56'  → 123456      a hundred times the figure, silently, into an opening balance
 *
 * Neither import posts anything by itself. What each produces is a figure a person then signs off:
 * a statement line that can no longer be matched to the payment it IS, and the opening trial
 * balance every later statement is built on.
 *
 * ## The rule
 *
 * 1. Both separators present → the LAST one is the decimal point and the other groups thousands.
 *    Unambiguous: `1,234.56` and `1.234,56` are one number written two ways.
 * 2. One kind, appearing more than once → all thousands separators (`1,234,567`, `1.234.567`).
 *    Two decimal points is not a number anybody meant.
 * 3. A lone COMMA groups thousands only when it really groups a thousand — exactly three digits
 *    after it, and one to three digits before it that do not start with a zero (`12,500` → 12500).
 *    Everything else is a decimal comma (`1234,56` → 1234.56, `0,500` → 0.5).
 * 4. A lone DOT is ALWAYS the decimal point, and that is deliberately NOT symmetrical with rule 3.
 *    Egyptian bank and ledger exports are English-formatted to two decimal places, so reading
 *    `1.500` as 1500 would overstate the common form by a thousand to rescue the rare one.
 *
 * Parentheses are the accountant's minus sign: `(1,234.56)` → -1234.56. Currency words, spaces and
 * non-breaking spaces are noise and are dropped.
 *
 * Rounding is left to the caller, because the three of them do not agree about it and should not
 * have to: a bank line rounds when it is stored, a trial-balance line rounds as it is parsed.
 */
final class CsvAmount
{
    public static function parse(?string $value): float
    {
        $value = trim((string) $value);

        if ($value === '') {
            return 0.0;
        }

        $negative = str_starts_with($value, '(') && str_ends_with($value, ')');
        $value = trim($value, '()');

        // Digits, both separators and the sign survive; currency codes and spacing do not.
        $value = preg_replace('/[^\d,.\-]/u', '', $value) ?? '';
        $digits = ltrim($value, '-');

        if ($digits === '') {
            return 0.0;
        }

        $negative = $negative || str_starts_with($value, '-');

        $lastDot = strrpos($digits, '.');
        $lastComma = strrpos($digits, ',');

        if ($lastDot !== false && $lastComma !== false) {
            // Rule 1.
            $decimalAt = max($lastDot, $lastComma);
        } elseif ($lastComma !== false) {
            // Rules 2 and 3.
            $decimalAt = (substr_count($digits, ',') === 1 && ! self::groupsThousands($digits, $lastComma))
                ? $lastComma
                : null;
        } elseif ($lastDot !== false) {
            // Rules 2 and 4.
            $decimalAt = substr_count($digits, '.') === 1 ? $lastDot : null;
        } else {
            $decimalAt = null;
        }

        $whole = preg_replace('/\D/', '', $decimalAt === null ? $digits : substr($digits, 0, $decimalAt)) ?? '';
        $fraction = $decimalAt === null
            ? ''
            : (preg_replace('/\D/', '', substr($digits, $decimalAt + 1)) ?? '');

        $number = (float) ($whole.($fraction === '' ? '' : '.'.$fraction));

        return $negative ? -$number : $number;
    }

    /**
     * Does the separator at `$at` group a real thousand?
     *
     * Both halves are load-bearing. Without the tail test `1234,56` reads as 123456; without the
     * head test `0,500` reads as 500 — and a leading zero is exactly how somebody writes half a
     * pound, never how they write the first group of a thousand.
     */
    private static function groupsThousands(string $digits, int $at): bool
    {
        return preg_match('/^[1-9]\d{0,2}$/', substr($digits, 0, $at)) === 1
            && preg_match('/^\d{3}$/', substr($digits, $at + 1)) === 1;
    }
}
