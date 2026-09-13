<?php

namespace App\Support\Filament;

/**
 * A boolean import cell is an ANSWER — yes or no, in the languages this operator writes — or it is
 * refused. Never "anything else is true".
 *
 * Filament's `ImportColumn::boolean()` casts the recognised tokens (`1/0`, `true/false`, `yes/no`,
 * `y/n`, `on/off`) and then, for everything else, `(bool) $state` — so «لا», `N/A`, `-`, `x`, `2`
 * and Excel's `0.0` (a column formatted to one decimal) all import as TRUE, and the `boolean`
 * validation rule never sees them because validation runs on the already-cast value. Measured on
 * the review of SW-255, on the two lease columns that decide whether a tenant is chased for a
 * monthly sales declaration and whether the lease pays percentage rent; the same cast sits on
 * five sibling columns (a vendor's withholding exemption, a charge's proration flag, a chart
 * account's postable and active flags). A migrating Egyptian operator's spreadsheet says «نعم»
 * and «لا», and today that file imports every «لا» as a yes.
 *
 * Blank stays null (Filament's own rule, kept); a recognised token in either language becomes its
 * answer; an unrecognised one is handed back as the RAW STRING, which the column's `boolean` rule
 * then refuses with a message naming the field — the row fails while the spreadsheet is still
 * open, instead of a tenant being chased for a year on the strength of a dash.
 *
 * The raw-string return is only a refusal because every `->boolean()` column also carries the
 * `boolean` rule — `ImportColumn::rules()` REPLACES, so no seam can add it — and the regression
 * test sweeps the importers for a boolean column without it. The seam that reaches every column
 * is `ImportCellCasts` (one registration, because the cast hook is a single slot); the NUMERIC
 * cast's twin of this defect is `NumericImportCellIsANumber` (SW-261).
 */
final class BooleanImportCellIsAnAnswer
{
    /** @var list<string> lower-cased; the Arabic pair is the market's own spreadsheet vocabulary */
    public const YES = ['1', 'true', 'yes', 'y', 'on', "\u{646}\u{639}\u{645}"];

    /** @var list<string> */
    public const NO = ['0', 'false', 'no', 'n', 'off', "\u{644}\u{627}"];

    /** @return bool|string|null the answer, null for a blank, or the raw token for the rule to refuse */
    public static function answer(mixed $raw): bool|string|null
    {
        if (blank($raw)) {
            return null;
        }

        $token = mb_strtolower(trim((string) $raw));

        return match (true) {
            in_array($token, self::YES, true) => true,
            in_array($token, self::NO, true) => false,
            // Excel writes a flag column formatted to a decimal as `1.0` / `0.0`; a number that IS
            // one or zero is that answer, any other number is not.
            is_numeric($token) && (float) $token === 1.0 => true,
            is_numeric($token) && (float) $token === 0.0 => false,
            default => (string) $raw,
        };
    }
}
