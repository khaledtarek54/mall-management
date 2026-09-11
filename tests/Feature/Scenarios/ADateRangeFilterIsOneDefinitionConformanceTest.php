<?php

use App\Support\PhpSource;
use Tests\Support\ActionStrips;

/*
|--------------------------------------------------------------------------
| A date-range filter is ONE definition (2026-09-12)
|--------------------------------------------------------------------------
| `App\Support\Filament\DateRangeFilter` was extracted on 2026-08 so the five hand-written copies
| would stop — its own docblock says so — and then the five were never converted and sixteen more
| were written beside it. Measured before this gate: twenty-one inline copies against three uses,
| under four spellings of the picker keys (`from/until`, `payment_from/payment_until`,
| `period_from/period_until`, `created_from/created_until`), and NINE of them with no chip at all —
| an applied range with nothing in the filter bar to say so or to clear it, which is SW-025's own
| defect wearing a different filter. Every copy queried `whereDate`, so the datetime trap the seam
| exists for had not bitten yet; the chip had.
|
| The rule is by SHAPE: a `Filter::make(…)` chain carrying two `DatePicker`s that narrows ONE
| column is the seam's job. A range over TWO columns (the bank statement's period, which asks
| "statements overlapping this window") is a different filter and is left alone — told apart by
| the columns its query names, not by a list.
*/

it('writes every single-column date range through DateRangeFilter', function () {
    $offenders = [];
    $seamUses = 0;
    $twoColumn = 0;

    foreach (ActionStrips::sources() as $file) {
        $source = PhpSource::fileWithoutComments($file);
        $seamUses += preg_match_all('/DateRangeFilter::make\(/', $source);

        foreach (filterChainsIn($source) as [$name, $text]) {
            if (substr_count($text, 'DatePicker::make(') !== 2) {
                continue;
            }

            preg_match_all("/where(?:Date)?\(\s*'([a-z_.]+)'\s*,\s*'[<>]=?'/", $text, $wh);
            $columns = array_values(array_unique($wh[1]));

            if (count($columns) > 1) {
                $twoColumn++;

                continue;
            }

            $offenders[] = str_replace(base_path().'/', '', $file)." [{$name}] narrows `".($columns[0] ?? '?')."` with its own two pickers — DateRangeFilter::make('".($columns[0] ?? 'column')."', …, name: '{$name}')";
        }
    }

    // Premises: the seam is in use (23 sites on the day this was written) and the two-column
    // shape it deliberately leaves alone still exists (one), so a sweep that read nothing is
    // told apart from a clean one.
    expect($seamUses)->toBeGreaterThanOrEqual(20, 'DateRangeFilter is used on far fewer tables than expected — the sweep is reading the wrong tree.');
    expect($twoColumn)->toBeGreaterThanOrEqual(1, 'The two-column range (bank statement period) has vanished — re-check the carve-out is still needed.');

    expect($offenders)->toBe([], implode("\n  ", $offenders));
});

/**
 * Every `Filter::make('name')` chain in a file, as `[name, chain text]`, by token depth.
 *
 * @return array<int, array{0: string, 1: string}>
 */
function filterChainsIn(string $source): array
{
    $tokens = token_get_all($source);
    $n = count($tokens);
    $out = [];

    for ($i = 0; $i < $n; $i++) {
        $t = $tokens[$i];

        // `Filter::make(` imported, or `\Filament\Tables\Filters\Filter::make(` written out — the
        // latter is ONE `T_NAME_FULLY_QUALIFIED` token, and the first cut of this walker looked for
        // a bare `T_STRING`, so the gate's own registered mutation (written fully qualified) was
        // reported as a HOLE by the audit.
        if (! is_array($t)
            || ! in_array($t[0], [T_STRING, T_NAME_FULLY_QUALIFIED, T_NAME_QUALIFIED], true)
            || ! in_array(ltrim($t[1], '\\'), ['Filter', 'Filament\\Tables\\Filters\\Filter'], true)) {
            continue;
        }

        if (! isset($tokens[$i + 4]) || ! is_array($tokens[$i + 1]) || $tokens[$i + 1][0] !== T_DOUBLE_COLON
            || ! is_array($tokens[$i + 2]) || $tokens[$i + 2][1] !== 'make'
            || ! is_array($tokens[$i + 4]) || $tokens[$i + 4][0] !== T_CONSTANT_ENCAPSED_STRING) {
            continue;
        }

        $depth = 0;
        $text = '';

        for ($j = $i; $j < $n; $j++) {
            $s = is_array($tokens[$j]) ? $tokens[$j][1] : $tokens[$j];

            if ($s === '(' || $s === '[' || $s === '{') {
                $depth++;
            } elseif ($s === ')' || $s === ']' || $s === '}') {
                if ($depth === 0) {
                    break;
                }
                $depth--;
            } elseif (($s === ',' || $s === ';') && $depth === 0) {
                break;
            }

            $text .= $s;
        }

        $out[] = [trim($tokens[$i + 4][1], "'\""), $text];
    }

    return $out;
}
