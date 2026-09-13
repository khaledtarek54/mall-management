<?php

namespace App\Support\Filament;

use App\Support\LatinNumerals;
use ResourceBundle;

/**
 * A numeric import cell is a NUMBER — in any notation the operator's spreadsheet writes one — or it
 * is refused. Never "whatever digits it happens to contain".
 *
 * Filament's `ImportColumn::numeric()` casts with `floatval(preg_replace('/[^0-9.-]/', '', $cell))`:
 * everything that is not a Latin digit, a dot or a hyphen is thrown away and what is left is read
 * as a float. So `-`, `TBD`, `n/a` and `pending` all import as **0.00**, `12-500` as 12, `1e5` as
 * 15, and `١٢٬٥٠٠` — Arabic-Indic digits, which this system reads everywhere else — as 0.00, because
 * every digit is stripped. The `numeric` rule never sees any of it: validation runs on the cast
 * value, and 0.00 is numeric. Found by the review of SW-255 (recorded as SW-261): on
 * `percentage_rent_rate` that is an overage priced at nothing for the term; on `base_rent_monthly`
 * a lease that bills nothing; on `ownership_share_pct` a co-owner with no share of the صيانة.
 *
 * A migrating operator's file is exactly where those tokens live — a rent column with `TBD` on the
 * two units still under negotiation, a share column with `-` on the developer's own retained
 * units — and each of them today becomes a silent zero in a money column instead of a refused row
 * naming the field.
 *
 * `answer()` reads exactly the shapes a spreadsheet writes a number in, and nothing looser:
 *
 *  - the digits in either script, with Excel's Arabic separators, folded through `LatinNumerals`
 *    the way `SearchText` and `SalesExclusions` already read them;
 *  - thousands grouped in THREES with a comma, a dot decimal, a sign, an exponent (Excel writes
 *    `1.23E+11` for a wide value in General format);
 *  - an accounting negative — `(500.00)`, and Excel's Accounting format `EGP (500.00)`, where the
 *    symbol sits OUTSIDE the parentheses;
 *  - a unit token before and/or after, set off by optional whitespace: `%`, a currency symbol, an
 *    ISO 4217 code (`EGP`, `USD` — asked of ICU's own currency bundle, never a list of ours), or
 *    the pound's abbreviations, `LE` / `L.E.` / `ج.م.` / `جنيه`. ICU's table also admits a few
 *    English words that happen to be codes (`ALL`, `TOP`, `TRY`, `CAD`) and the special codes
 *    `XXX`/`XTS`; none is a plausible companion to a figure in a money column, and it is stated
 *    rather than filtered.
 *
 * Anything else is handed back as the RAW cell (trimmed) and the column's `numeric`/`integer` rule
 * refuses it with a message naming the field, while the spreadsheet is still open. That is only a
 * refusal because every `->numeric()` import column carries such a rule — `ImportColumn::rules()`
 * REPLACES, so the seam cannot add one — and the regression test sweeps the importers for a
 * numeric column without it, the same tooth the boolean seam has.
 *
 * THE RULE THAT DECIDES THE SHAPE: a word in the cell is only ever a UNIT, and a unit is a thing
 * ICU or this market can name. The first cut allowed any run of letters as the token and stripped
 * every space, and the review showed what that reads: `TBD 2027` → 2027, `Q1 2027` → 12027,
 * `Y1 12000` → 112000, `see note 3` → 3 — each worse than the 0.00 the seam exists to end,
 * and each precisely the cell a rent column under negotiation carries. So a token is a currency
 * or `%` and nothing else, whitespace is allowed only between the token and the number, and a
 * digit run is one run: `12 500` is refused rather than read as space-grouped thousands.
 *
 * Stricter than Filament's cast in three places, each stated: a European decimal comma (`12,5`)
 * is refused rather than read as 125 (grouping must be in threes — which also means `1,250` is
 * read as one thousand two hundred and fifty; a DE/FR file with a decimal comma is ambiguous here
 * and this is the English-Egypt reading); a second decimal point or a digit after the unit
 * (`1.2.3`, `12-500`) is refused rather than read as its first fragment; and a dash that is not a
 * hyphen or the true minus (`–500`, an en dash from a copy out of Word) is refused rather than read
 * as +500. Rounding to the column's declared decimal places is Filament's own behaviour, kept.
 *
 * The seam that makes this reach every column is `ImportCellCasts`.
 */
final class NumericImportCellIsANumber
{
    /**
     * What `LatinNumerals::toLatin()` does not already fold: the true minus (U+2212, a copy out of
     * a PDF) and the bidi marks Excel's Arabic-Egypt currency format writes after `ج.م.` — a mark
     * is invisible in the cell and would otherwise fail the unit token. The digits and the Arabic
     * decimal, thousands and percent marks are `LatinNumerals`' own map.
     */
    private const FOLD = ["\u{2212}" => '-', "\u{200E}" => '', "\u{200F}" => '', "\u{061C}" => ''];

    /** Whitespace a spreadsheet pads with, including the no-break kinds Excel's currency formats emit. */
    private const SPACE = '[\s\x{00A0}\x{202F}]*';

    /**
     * The number itself, Latin digits ONLY — `\d` under `/u` matches every Unicode decimal digit
     * (fullwidth, Devanagari, Thai), which `(float)` then reads as 0: the silent zero, produced by
     * the seam's own regex (review-found). Grouping in threes, a dot decimal, an exponent.
     */
    private const NUMBER = '[+-]?(?:(?:[0-9]{1,3}(?:,[0-9]{3})+|[0-9]+)(?:\.[0-9]+)?|\.[0-9]+)(?:[eE][+-]?[0-9]+)?';

    /**
     * A unit token that needs no dictionary: `%`, one or more currency symbols, or the Egyptian
     * pound's abbreviations in either script. A THREE-LETTER token is checked against ICU below.
     */
    private const UNIT = '%|\p{Sc}+|LE|L\.E\.?|\x{62C}\.\x{645}\.?|\x{62C}\x{646}\x{64A}\x{647}|[A-Za-z]{3}';

    /**
     * @return float|string|null the number, null for a blank, or the raw cell for the rule to refuse
     */
    public static function answer(mixed $raw, ?int $decimalPlaces = null): float|string|null
    {
        if (blank($raw)) {
            return null;
        }

        if (is_int($raw) || is_float($raw)) {
            return self::rounded((float) $raw, $decimalPlaces);
        }

        // Trimmed on BOTH branches: ` 1e5 ` passes Laravel's `numeric` rule (`is_numeric()` allows
        // padding) and then throws inside the model's decimal cast, a crash where the seam promised
        // a refusal or a number (review-found).
        $cell = preg_replace('/^'.self::SPACE.'|'.self::SPACE.'$/u', '', (string) $raw) ?? '';
        $text = strtr(LatinNumerals::toLatin($cell), self::FOLD);

        $shape = '/^(?:(?<pre>'.self::UNIT.')'.self::SPACE.')?'
            .'(?<open>\()?(?<num>'.self::NUMBER.')(?<close>\))?'
            .'(?:'.self::SPACE.'(?<post>'.self::UNIT.'))?$/u';

        if (preg_match($shape, $text, $m) !== 1 || ! self::isUnit($m['pre'] ?? '') || ! self::isUnit($m['post'] ?? '')) {
            return $cell;
        }

        // An accounting negative, `(500.00)`: both parentheses or neither, and no sign of its own
        // inside them — `(-500)` is two statements about one sign.
        $parenthesised = ($m['open'] ?? '') !== '' || ($m['close'] ?? '') !== '';
        if ($parenthesised && (($m['open'] ?? '') === '' || ($m['close'] ?? '') === '' || preg_match('/^[+-]/', $m['num']) === 1)) {
            return $cell;
        }

        $value = (float) str_replace(',', '', $m['num']);

        return self::rounded($parenthesised ? -$value : $value, $decimalPlaces);
    }

    /**
     * A three-letter token is a unit only when it is a currency ICU knows — `EGP`, `USD`, `SAR`,
     * never `TBD`, `TBA` or `QTY`. Every other shape `UNIT` matches is a unit by construction.
     */
    private static function isUnit(string $token): bool
    {
        if ($token === '' || preg_match('/^[A-Za-z]{3}$/', $token) !== 1) {
            return true;
        }

        return self::isCurrencyCode(strtoupper($token));
    }

    private static function isCurrencyCode(string $code): bool
    {
        static $currencies = null;

        // ICU's own currency table, read once per process; `intl` is a declared dependency of
        // this application (`ext-intl` in composer.json, `PhpExtensions` gates it on the box).
        $currencies ??= ResourceBundle::create('en', 'ICUDATA-curr', false)?->get('Currencies', false);

        return $currencies !== null && $currencies->get($code, false) !== null;
    }

    /** Filament's own rounding, kept: an `->integer()` column declares 0 places. */
    private static function rounded(float $value, ?int $decimalPlaces): float
    {
        return $decimalPlaces === null ? $value : round($value, $decimalPlaces);
    }
}
