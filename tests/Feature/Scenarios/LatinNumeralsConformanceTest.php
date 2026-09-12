<?php

/*
|--------------------------------------------------------------------------
| A number is written in Latin digits — in every language this system speaks
|--------------------------------------------------------------------------
| Egypt writes numbers in Latin digits: the bank statement, the tax invoice, the POS receipt and
| the licence plate all say 100, not ١٠٠. So does every system this project benchmarks against.
| So does this one — panel, portal, contractor portal, PDFs, notifications, mobile API, handbook.
|
| WHAT THIS REPLACES. `tests/Feature/LatinNumeralsTest.php` carried this rule, and its title said
| "renders every number in Latin digits even under the Arabic locale" while its body called seven
| formatting helpers and read **zero real strings**. That is the gate-checks-a-weaker-property
| shape this codebase keeps recording, and it hid the whole defect: numbers are produced two ways,
| and only one of them goes through a formatter.
|
|   FORMATTED — an amount, a count, a date — produced by `Number::` / Carbon / Filament. The old
|   test covered this, and it was genuinely fine.
|
|   TYPED — the digits somebody wrote into a translation string, a seeded reference row, or a
|   handbook page. Produced by nobody at runtime, so no formatting seam can ever reach them, and
|   the old test could not see them. That is where all of it was: 1,008 codepoints across 43
|   files, including «٣٠ يومًا» in the ageing report, «نموذج ٤١» on the withholding return, the
|   VAT and stamp code names in the tax catalogue, and Egypt's own public-holiday register.
|
| So this file sweeps STRINGS, and keeps the formatter checks underneath it.
|
| WHAT IS DELIBERATELY NOT SWEPT. The system WRITES Latin and READS both — a number typed on an
| Arabic keyboard is a number. `SearchText` folds Arabic-Indic into the search blob and
| `SalesExclusions::amount()` folds it out of a percentage-rent deduction; both are call sites of
| `LatinNumerals::DIGITS` and both are correct. The sweep is scoped to OUTPUT for that reason, and
| PHP comments are tokenised away rather than matched, so a docblock may go on discussing «٤١».
*/

use App\Http\Middleware\SetLocale;
use App\Support\LatinNumerals;
use App\Support\Pdf\DocumentLocale;
use App\Support\SalesExclusions;
use App\Support\Search\SearchText;
use Filament\Schemas\Schema;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Support\Carbon;
use Illuminate\Support\Number;
use Tests\Support\RenderedText;

/* ---- helpers -------------------------------------------------------------- */

/**
 * Every leaf string of every translation catalogue, keyed by `locale:file:dotted.key`.
 *
 * The catalogues are LOADED rather than read as text, which is what makes this precise: a
 * translator reads values, so values are what is swept — a comment above one is not output.
 * The loader is `Tests\Support\RenderedText` since its second call site (the other-systems sweep,
 * 2026-09-12); this name stays so the sweeps below read as they always did.
 *
 * @return array<string, array<string, string>>
 */
function latinNumeralsCatalogueStrings(): array
{
    return RenderedText::catalogueStrings();
}

/**
 * Every string LITERAL in a PHP file, with comments and code tokenised away.
 *
 * Reading the raw text would report a docblock, and this codebase has already twice had a gate
 * fire on a sentence rather than on code — which gets the gate weakened rather than the code
 * fixed. `token_get_all` draws that line exactly where it belongs.
 *
 * @return array<int, array{0: int, 1: string}> line number and value
 */
function latinNumeralsStringLiterals(string $path): array
{
    return RenderedText::stringLiterals($path);
}

/**
 * Every PHP file under `database/`, RECURSIVE — `database/settings/` holds 40-odd migrations that
 * write seeded VALUES, and a non-recursive glob of three named directories missed every one.
 *
 * @return array<int, string>
 */
function latinNumeralsSeededFiles(): array
{
    return RenderedText::phpFilesUnder(database_path());
}

/* ---- the sweep ------------------------------------------------------------ */

it('writes no Arabic-Indic digit into any translation catalogue', function () {
    $byLocale = latinNumeralsCatalogueStrings();

    // Non-vacuity PER LOCALE, never as a total. `lang/en` alone is ~15k strings, so a single
    // global floor stays cleared by 4x even if `lang/ar` — the only catalogue that can hold an
    // Arabic-Indic digit — stopped being loaded entirely (a renamed directory, a `require`
    // returning a non-array, a locale added as `ar-EG/`). That is the lesson
    // `ArabicPanelHasNoEnglishChromeConformanceTest` had to learn: count per panel, not in total.
    expect(array_keys($byLocale))->toContain('en', 'ar');

    foreach (SetLocale::SUPPORTED as $locale) {
        expect(count($byLocale[$locale] ?? []))->toBeGreaterThan(2000,
            "The `{$locale}` catalogue contributed almost nothing — the sweep is reporting on a set it cannot see.");
    }

    $offenders = [];

    foreach ($byLocale as $strings) {
        foreach ($strings as $where => $value) {
            if (LatinNumerals::contains($value)) {
                $offenders[] = $where.' → '.$value.'   (should be: '.LatinNumerals::toLatin($value).')';
            }
        }
    }

    expect($offenders)->toBe([], "A translation string is written in Arabic-Indic digits:\n  ".implode("\n  ", $offenders));
});

it('writes no numeric range an RTL reader would see backwards', function () {
    // A SEPARATE rule with the same cause. An EN DASH is bidi class ON, so UAX#9 W4 cannot absorb
    // it, N1 resolves it to the paragraph direction between two numbers, and L2 then lays the two
    // out right-to-left: «1–30 يومًا» renders `30–1 يومًا`. A HYPHEN-MINUS is class ES and W4 folds
    // `EN ES EN` into one numeric run, so `1-30` renders forwards with no invisible marks at all.
    //
    // Measured live: `admin.widgets.ar_aging.d_1_30` is a column header on the collections widget
    // and a Stat title on the monthly close. This predates the digit conversion — with
    // Arabic-Indic digits it reversed identically — but it is the same rule and the same screen.
    //
    // The same applies to a bidi MARK placed inside a numeric group, which is why six of them were
    // removed rather than carried over: with Latin digits they block the very rule that makes the
    // group read forwards.
    // RTL locales ONLY. In an English paragraph an en-dash range is correct typography and reads
    // forwards; the reversal is a property of the RTL paragraph it sits in, not of the dash.
    // Derived from `DocumentLocale::RTL`, the list this project already keeps, rather than `=== 'ar'`.
    $byLocale = latinNumeralsCatalogueStrings();

    expect(array_intersect(DocumentLocale::RTL, array_keys($byLocale)))->not->toBeEmpty(
        'No RTL catalogue was loaded, so this sweep examined nothing.');

    $offenders = [];

    foreach (DocumentLocale::RTL as $locale) {
        foreach ($byLocale[$locale] ?? [] as $where => $value) {
            // Two shapes, and they are not one pattern. A DASH is the defect only between two
            // numbers. A bidi MARK is the defect the moment it FOLLOWS a digit at all — the six
            // that were removed sat between the digit and the separator (`30<RLM>/60`), which a
            // digit-mark-digit pattern is structurally unable to see. (It did not see it: this
            // gate reported clean on the exact string the change had just fixed.) A mark that
            // PRECEDES a Latin word — `>‏/admin` in the handbook — is correct and stays.
            if (preg_match('/[0-9][\x{2013}\x{2010}][0-9%]|[0-9][\x{200E}\x{200F}]/u', $value)) {
                $offenders[] = $where.' → '.$value;
            }
        }
    }

    expect($offenders)->toBe([],
        "A numeric range uses an en-dash or carries a bidi mark, so an RTL reader sees it backwards:\n  ".implode("\n  ", $offenders));
});

it('seeds no Arabic-Indic digit into reference or demo data', function () {
    $files = latinNumeralsSeededFiles();

    expect(count($files))->toBeGreaterThan(100,
        'The seeder sweep found almost no files — it is reporting on a set it cannot see.');

    $offenders = [];
    $literals = 0;

    foreach ($files as $path) {
        foreach (latinNumeralsStringLiterals($path) as [$line, $value]) {
            $literals++;

            if (LatinNumerals::contains($value)) {
                $offenders[] = basename($path).':'.$line.' → '.trim($value);
            }
        }
    }

    expect($literals)->toBeGreaterThan(5000,
        'The tokeniser returned almost no string literals — the sweep is vacuous.');

    expect($offenders)->toBe([], "Seeded data is written in Arabic-Indic digits:\n  ".implode("\n  ", $offenders));
});

it('writes no Arabic-Indic digit into the Arabic handbook', function () {
    // Both of `Assistant\DocCorpus::SOURCES` — the two corpora written for an OPERATOR rather than
    // for whoever changes the code. The handbook is read inside the panel at /admin/handbook; the
    // training walkthroughs are published nowhere, so the assistant quotes them and "its excerpt IS
    // the answer". `docs/modules/` is deliberately absent: it is `TECHNICAL_SOURCES`, off by
    // default, and it is developer prose where a docblock may legitimately discuss «٤١».
    $pages = [];

    foreach ([base_path('docs/visual/ar'), base_path('docs/training')] as $dir) {
        if (! is_dir($dir)) {
            continue;
        }

        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir)) as $file) {
            if ($file->getExtension() === 'md') {
                $pages[$file->getPathname()] = file_get_contents($file->getPathname());
            }
        }
    }

    expect(count($pages))->toBeGreaterThan(20,
        'The handbook sweep found almost no pages — it is reporting on a set it cannot see.');

    $offenders = [];

    foreach ($pages as $path => $body) {
        foreach (explode("\n", $body) as $i => $line) {
            if (LatinNumerals::contains($line)) {
                $offenders[] = basename($path).':'.($i + 1);
            }
        }
    }

    expect($offenders)->toBe([], 'A handbook page is written in Arabic-Indic digits: '.implode(', ', $offenders));
});

/* ---- the formatters ------------------------------------------------------- */

it('formats every number in Latin digits under the Arabic locale', function () {
    app()->setLocale('ar');

    $date = Carbon::parse('2026-06-15');

    $samples = [
        'number_format' => number_format(1234567.5, 2),
        'carbon_format' => $date->format('d M Y'),
        'carbon_isoFormat' => $date->locale('ar')->isoFormat('MMM YYYY'),
        'carbon_translatedFormat' => $date->locale('ar')->translatedFormat('d M Y'),
        'carbon_diffForHumans' => $date->locale('ar')->copy()->subDays(3)->diffForHumans(),
        'number_helper_format' => Number::format(1234567.5),
        'number_helper_currency' => Number::currency(1234.5, 'EGP'),
        'number_helper_percentage' => Number::percentage(12.5),
    ];

    foreach ($samples as $label => $value) {
        expect(LatinNumerals::contains($value))->toBeFalse("Arabic-Indic digits in {$label}: {$value}");
    }
});

it('pins Filament to Latin digits even when APP_LOCALE names the country', function () {
    // The case that actually bites. Filament's money/numeric columns, their summarizers and every
    // infolist entry resolve `$locale ?? $container->getDefaultNumberLocale() ?? config('app.locale')`
    // and pass it EXPLICITLY, which walks straight past `Number::useLocale('en')`.
    //
    // WHICH Arabic locale is Arabic-Indic depends on the box's ICU, and ours disagree: staging runs
    // ICU 74.2 where plain `ar` is `arab`; this laptop runs 77.1 where CLDR has moved `ar` to
    // `latn`. So on the deployment this was live on every money column, and on a dev machine it is
    // invisible. `ar_EG` is `arab` on BOTH, which is why the premise below is pinned on it — the
    // test then proves the pin whichever ICU it runs on.
    // `Application::setLocale()` WRITES `config('app.locale')`, so the config key Filament reads
    // is the RUNTIME locale, not the `.env` value. Pinned here because the whole reason this seam
    // is needed rests on it — if it ever stopped being true the comment above would go quietly
    // wrong rather than loudly.
    app()->setLocale('ar');
    expect(config('app.locale'))->toBe('ar');

    // `ar_EG` is not in SetLocale::SUPPORTED, so it is never applied AND never clamped — it simply
    // stands, which is what makes it reachable from a one-word `.env` edit.
    expect(SetLocale::SUPPORTED)->not->toContain('ar_EG');

    config(['app.locale' => 'ar_EG']);

    // The premise, first: this locale really does produce Arabic-Indic digits on this ICU build.
    // Without this the test passes on a build where `ar_EG` is Latin anyway, proving nothing.
    expect(LatinNumerals::contains(Number::currency(12780, 'EGP', 'ar_EG')))->toBeTrue(
        'ICU on this machine renders ar_EG in Latin digits, so this test cannot prove the pin.');

    $table = Table::make(Mockery::mock(HasTable::class));

    expect($table->getDefaultNumberLocale())->toBe('en')
        ->and(Schema::make()->getDefaultNumberLocale())->toBe('en');

    // …and what a money column would therefore print.
    expect(LatinNumerals::contains(
        Number::currency(12780, 'EGP', $table->getDefaultNumberLocale() ?? config('app.locale'))
    ))->toBeFalse();
});

it('pins every chart to Latin digits', function () {
    // Chart.js formats axis ticks and default tooltips through `new Intl.NumberFormat(options.locale)`
    // (`Ticks.formatters.numeric` in the published widgets bundle), and **Filament never sets
    // `options.locale`** — so unset it is `Intl.NumberFormat(undefined)`, i.e. the BROWSER's locale.
    // Measured: `Intl.NumberFormat('ar-EG').format(1234567.5)` is `١٬٢٣٤٬٥٦٧٫٥`, so an Egyptian
    // operator's browser rendered that widget's y-axis in Arabic-Indic while every other number on
    // the page was Latin — the mixed form this whole rule exists to prevent.
    //
    // A widget's `getOptions()` is its only seam: Filament has no `configureUsing` for widgets, and
    // three of the four returned a raw JS literal, so there is nothing to merge an array into. Hence
    // per widget — and hence this gate, so widget #5 cannot ship without it.
    $widgets = glob(app_path('Filament/*/Widgets/*.php')) ?: [];

    $charts = array_values(array_filter($widgets, fn (string $f): bool => str_contains(file_get_contents($f), 'function getOptions')));

    expect(count($charts))->toBeGreaterThan(3,
        'Found almost no chart widgets — this sweep is reporting on a set it cannot see.');

    $unpinned = [];

    foreach ($charts as $file) {
        $source = file_get_contents($file);

        // Either shape: `locale: 'en'` in a RawJs literal, or `'locale' => 'en'` in an array.
        if (! preg_match("/locale:\s*'en'|'locale'\s*=>\s*'en'/", $source)) {
            $unpinned[] = basename($file);
        }
    }

    expect($unpinned)->toBe([],
        'A chart widget does not pin its number locale, so its axis follows the browser: '.implode(', ', $unpinned));
});

it('still reads a number typed on an Arabic keyboard', function () {
    // The control. This sweep must never be "satisfied" by making the system refuse Arabic-Indic
    // INPUT — writing Latin and reading both are opposite halves of one rule, and a fix that broke
    // the reading half would pass every assertion above.
    expect(SearchText::normalize('فاتورة ٢٠٢٦'))->toContain('2026')
        ->and(SalesExclusions::amount('١٬٢٠٠٫٥٠'))->toEqual(1200.5)
        ->and(SalesExclusions::amount('۱٬۲۰۰٫۵۰'))->toEqual(1200.5);
});
