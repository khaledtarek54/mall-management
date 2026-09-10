<?php

namespace App\Support;

use Filament\Schemas\Schema;
use Filament\Tables\Table;
use Illuminate\Support\Number;

/**
 * A number is written in Latin digits — 0 1 2 … 100 — in every language this system speaks.
 *
 * ## The rule
 *
 * Arabic has two digit sets. Latin (`0-9`, "Western Arabic") is what Egypt prints on a bank
 * statement, a tax invoice, a POS receipt and a licence plate; Arabic-Indic (`٠-٩`) is the
 * traditional set, still used in the Gulf and in Persian/Urdu typography (`۰-۹`, the extended
 * range). Egyptian commercial practice is Latin, and it is what Yardi, MRI and every accounting
 * system this project benchmarks against emit for an Arabic UI. **So does this one, everywhere:**
 * panel, portal, contractor portal, PDFs, notifications, the mobile API and the handbook.
 *
 * Mixing them is worse than picking the other one. A tenant reconciling `١٢٬٧٨٠` on screen against
 * `12,780` on the bank statement has to transliterate before they can compare, and an operator
 * reading `٣٠` in one sentence and `30` in the next cannot tell which is the system's own voice.
 *
 * ## The two halves, and why the runtime half was never the problem
 *
 * **Formatted** numbers — an amount, a count, a date — are produced by `Number::` and Carbon, and
 * were already pinned. Carbon's bundled `ar` locale is Latin, and {@see self::register()} pins the
 * rest. Note that `Number::useLocale('en')` is a PIN, not a fix — `Number::$locale` already
 * defaults to `'en'` in the framework (`Illuminate\Support\Number:18`) and nothing in Laravel
 * derives it from `app.locale`, so on a stock install that call changes nothing today. It is there
 * so a framework default change, or any package calling `useLocale()`, cannot move it underneath
 * us. **The seam that does real work is the Filament one**, because Filament passes an explicit
 * locale and an explicit locale bypasses the global default entirely.
 *
 * **Typed** numbers — the digits somebody wrote into a translation string, a seeded reference row
 * or a handbook page — are produced by nobody at runtime, so no formatting seam can reach them.
 * That is where all of it actually was: the Arabic catalogue, the Egyptian public-holiday
 * register, the VAT and stamp code names, and the Arabic handbook. `tests/Feature/LatinNumeralsTest.php` had
 * carried the rule since long before, and swept seven helper calls and zero real strings — a gate
 * checking a weaker property than its own title. `LatinNumeralsConformanceTest` sweeps the strings.
 *
 * ## Reading is the opposite of writing, and stays that way
 *
 * The system WRITES Latin and READS both. A number typed on an Arabic keyboard is a number, so
 * `SearchText` folds Arabic-Indic on the way into the search blob and `SalesExclusions::amount()`
 * folds it on the way into a percentage-rent deduction. Both are call sites of {@see self::DIGITS}
 * and neither is a defect — which is why the conformance gate scopes itself to output.
 *
 * `SalesExclusions` had its own copy of this map covering only the basic range, so an exclusion
 * typed `۱٬۲۰۰` on a Persian/Urdu keyboard survived the fold as nothing, was stripped to `''` by
 * the numeric filter and deducted **0.00** — SW-164's own defect (a deduction silently worth
 * nothing, tenant over-billed on turnover that was never theirs) through the other door. Two
 * copies of one map is how that happens; there is one now.
 */
final class LatinNumerals
{
    /**
     * Arabic-Indic (U+0660–U+0669) and extended Arabic-Indic (U+06F0–U+06F9) → Latin.
     *
     * Both ranges, always. The extended set is Persian/Urdu, and it reaches Egyptian desks often
     * enough to be worth the eight entries — a keyboard layout is not a country.
     */
    public const DIGITS = [
        "\u{0660}" => '0', "\u{0661}" => '1', "\u{0662}" => '2', "\u{0663}" => '3', "\u{0664}" => '4',
        "\u{0665}" => '5', "\u{0666}" => '6', "\u{0667}" => '7', "\u{0668}" => '8', "\u{0669}" => '9',

        "\u{06F0}" => '0', "\u{06F1}" => '1', "\u{06F2}" => '2', "\u{06F3}" => '3', "\u{06F4}" => '4',
        "\u{06F5}" => '5', "\u{06F6}" => '6', "\u{06F7}" => '7', "\u{06F8}" => '8', "\u{06F9}" => '9',
    ];

    /**
     * The marks that travel WITH a number and are just as unreadable beside a Latin one.
     *
     * Kept apart from {@see self::DIGITS} because the two are used differently: every reader of the
     * digits wants these too, and the search fold deliberately does NOT — a thousands mark is
     * punctuation, and `SearchText` strips punctuation a step later anyway. Folding them there
     * would change every stored blob and buy nothing.
     *
     * U+066A ARABIC PERCENT SIGN is included because `١٤٪` is one token to a reader: converting the
     * digits and leaving the sign yields `14٪`, which is the mixed form this class exists to
     * prevent. The Arabic catalogue had already settled on ASCII `%` before anyone measured it —
     * 80 occurrences to 16, counted inside string literals across `lang/ar` at the commit before
     * this one. (The first draft of this sentence said 66 to 16: that was `grep -c`, which counts
     * LINES, not occurrences. Same direction, wrong figure.)
     */
    public const MARKS = [
        "\u{066A}" => '%',  // ٪ percent
        "\u{066B}" => '.',  // ٫ decimal separator
        "\u{066C}" => ',',  // ٬ thousands separator
    ];

    /**
     * Every codepoint this class refuses to see in output, as a COMPLETE character class —
     * brackets included. Do not compose it as a body (`'[^'.PATTERN.']'`): that nests a class
     * inside a class and silently matches something else.
     */
    public const PATTERN = '[\x{0660}-\x{0669}\x{06F0}-\x{06F9}\x{066A}-\x{066C}]';

    /**
     * Pin every number-formatting seam to Latin digits, from `AppServiceProvider::boot()`.
     *
     * Three seams — but only two of them do anything today, and saying which is the point.
     *
     * `Number::useLocale('en')` sets the DEFAULT the helper uses when a caller names no locale.
     * On a stock install that default is ALREADY `'en'` (`Illuminate\Support\Number:18`) and
     * nothing in Laravel derives it from `app.locale`, so this call is a pin against that changing
     * — not a fix, and deleting it would break nothing today. The two that carry the weight are
     * the Filament ones, because Filament passes an explicit locale, and an explicit locale
     * bypasses the global default entirely: `TextColumn::money()`,
     * `->numeric()`, their summarizers and every infolist `TextEntry` resolve
     * `$locale ?? $container->getDefaultNumberLocale() ?? config('app.locale')` and pass it
     * explicitly, which walks straight past the default.
     *
     * **And `config('app.locale')` is NOT the `.env` value at runtime.** `Application::setLocale()`
     * WRITES it (`$this['config']->set('app.locale', $locale)`), so the moment `SetLocale` puts an
     * operator into Arabic, every Filament money and numeric column in that request is resolving
     * the locale `ar` — not `en`. It renders Latin anyway for a reason nobody here chose: current
     * CLDR maps `ar` to the **`latn`** numbering system. That has changed before and is not ours
     * to rely on.
     *
     * **`ar_EG` maps to `arab`**, and `SetLocale::SUPPORTED` does not contain it — an unrecognised
     * value is never clamped, it is simply not applied, so `APP_LOCALE=ar_EG` (the obvious thing
     * for an Egyptian operator to write) stands for the whole request. Measured on ICU 77.1: a
     * money column then renders `‏١٢٬٧٨٠٫٠٠ ج.م.‏` while `Number::currency()` two lines away still
     * answers `EGP 12,780.00` — every amount in the panel in one digit set and every amount the
     * app composed itself in the other, from a one-word config edit, with nothing to report it.
     *
     * **The date picker is deliberately NOT pinned, and the reason is worth writing down.**
     * `DateTimePicker::getLocale()` reads the same `config('app.locale')`, so an Arabic operator's
     * pickers really do run dayjs's `ar` locale — whose bundled definition carries a `postformat`
     * that maps every Latin digit to Arabic-Indic. It is **declared and never invoked**: dayjs
     * core does not apply `postformat` (that is moment.js), and it is called 0 times in the only
     * published asset that contains it. So there is no defect to fix, and pinning that component
     * to `en` would be a REGRESSION — its locale also chooses the month and weekday names, and
     * English months inside an Arabic sentence is a defect this project has already fixed once
     * (SW-028). If a Filament upgrade ever starts applying `postformat`, the fix is a dayjs locale
     * whose names are Arabic and whose digits are Latin — never `->locale('en')`.
     *
     * So the middle tier is filled in rather than left to `config('app.locale')`. It is a FLOOR:
     * `configureUsing` runs inside `Table::make()`/`Schema::make()`, before a resource's own
     * `configure()`, so a call site that genuinely wants another locale still wins.
     *
     * **What this does NOT reach, stated rather than implied.** Three Filament VIEWS format a
     * number from `app()->getLocale()` directly, consulting neither the helper default nor the
     * table: the "select all N records" indicators (`tables/…/index.blade.php:745,765` — one PHP,
     * one `Intl.NumberFormat`) and FilePond's size labels (`forms/…/file-upload.blade.php:80`).
     * They are Latin under `ar` and would be Arabic-Indic under `ar_EG`, and they are vendor
     * blades — unreachable from here without a fork. **Chart.js is the other one, and it is
     * reachable**: it formats axis ticks and tooltips through `Intl.NumberFormat(options.locale)`,
     * Filament never sets `options.locale`, and unset means the BROWSER's locale — so each of the
     * four `ChartWidget`s pins `locale: 'en'` in its own `getOptions()`, which is that component's
     * only seam. `ChartWidgetsPinTheirNumberLocaleTest` keeps widget #5 honest.
     */
    public static function register(): void
    {
        Number::useLocale('en');

        Table::configureUsing(fn (Table $table) => $table->defaultNumberLocale('en'));
        Schema::configureUsing(fn (Schema $schema) => $schema->defaultNumberLocale('en'));
    }

    /** Does this text hold a digit or numeric mark that a Latin reader cannot read? */
    public static function contains(?string $text): bool
    {
        return $text !== null && preg_match('/'.self::PATTERN.'/u', $text) === 1;
    }

    /**
     * Rewrite one string in Latin digits, marks and all.
     *
     * The general operation. `SalesExclusions` reads a number out of free text and wants its own
     * decimal handling on top, so it composes rather than calls this.
     */
    public static function toLatin(?string $text): string
    {
        return $text === null ? '' : strtr($text, self::DIGITS + self::MARKS);
    }
}
