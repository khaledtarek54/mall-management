<?php

/*
|--------------------------------------------------------------------------
| The dashboard names no other system
|--------------------------------------------------------------------------
| Operator decision, 2026-09-12: the benchmark systems this project models its behaviour on are
| NEVER named on anything a person reads inside the panel. Found on `/admin/settings` — two
| helper texts explaining a default as "Yardi's default", in both languages. An operator does not
| care whose default it is; a client reading it sees a product describing itself in terms of a
| competitor.
|
| WHERE THE RULE STOPS, and why it is drawn there rather than at "anywhere in the repo": the docs
| tree is written FOR the people who change the code, and the standard genuinely IS the reason
| behind most of what it says (`docs/benchmarks/`, `docs/modules/`, CLAUDE.md). Stripping the name
| there would strip the provenance. So the sweep is scoped to what RENDERS — and that has to
| include the three markdown corpora the in-panel assistant quotes as ANSWERS, because an excerpt
| shown under the search box is dashboard text whatever file it came from. That set is DERIVED
| from `DocCorpus::files()`, never listed here, so a fourth indexed corpus is swept by being one.
|
| Six sources, one rule, each with its own non-vacuity floor:
|   · the translation catalogues, as VALUES, per locale;
|   · every string literal under `app/` (inline UI text, a toast, a mail, an API message) — with
|     the four reason registries that name the standard on purpose registered, and stale-checked;
|   · every Blade template as the browser receives it;
|   · seeded reference and demo data (a category description is a row a screen prints);
|   · the assistant's corpora (handbook + training walkthroughs + the business-rules register);
|   · the handbook's own chrome (sidebar labels, theme strings).
| PHP comments are tokenised away, Blade comments stripped — a docblock may go on saying where a
| rule came from, which is what a docblock is for. A Blade template is ONE inline-HTML token to the
| tokeniser, so a `//` comment inside `@php … @endphp` is swept as rendered text there — a stated
| limit, and it fails loud rather than silent.
*/

use App\Http\Middleware\SetLocale;
use App\Support\Assistant\DocCorpus;
use Tests\Support\RenderedText;

/**
 * The systems this project benchmarks against, plus the ones an operator in this market would
 * recognise as a competitor. Whole words, case-insensitive. Kept deliberately to PRODUCT names —
 * a word that is also ordinary English (`wave`, `tally`, `dynamics`) would fire on prose.
 *
 * @return array<int, string>
 */
function otherSystems(): array
{
    return [
        'yardi', 'voyager', 'mri', 'entrata', 'realpage', 'appfolio', 'buildium', 'propertyware',
        'odoo', 'sap', 'oracle', 'netsuite', 'quickbooks', 'xero', 'zoho', 'sage',
        'maximo', 'servicechannel', 'planon', 'archibus', 'corrigo', 'tririga', 'fexa',
    ];
}

/**
 * Whole-word, but the boundary is an ASCII one on purpose. PHP's `u` modifier also turns on
 * `PCRE2_UCP`, under which an Arabic letter is `\w` — so `\b` finds NO boundary between «بـ»
 * and `Yardi`, and «بـYardi» (the ordinary way Arabic technical prose writes "in Yardi", and the
 * exact shape of the helper text this gate was written for) walked straight past a `\b` matcher.
 * Found by review; the control below carries the clitic forms.
 */
function otherSystemsPattern(): string
{
    return '/(?<![A-Za-z0-9_])(?:'.implode('|', array_map('preg_quote', otherSystems())).')(?![A-Za-z0-9_])/iu';
}

/** @return array<int, string> the names found, or [] */
function otherSystemsIn(string $text): array
{
    return preg_match_all(otherSystemsPattern(), $text, $m) ? array_values(array_unique(array_map('strtolower', $m[0]))) : [];
}

it('names no other system in any translation catalogue', function () {
    $byLocale = RenderedText::catalogueStrings();

    foreach (SetLocale::SUPPORTED as $locale) {
        expect(count($byLocale[$locale] ?? []))->toBeGreaterThan(2000,
            "The `{$locale}` catalogue contributed almost nothing — the sweep is reporting on a set it cannot see.");
    }

    $offenders = [];

    foreach ($byLocale as $strings) {
        foreach ($strings as $where => $value) {
            if ($names = otherSystemsIn($value)) {
                $offenders[] = $where.' → '.implode(', ', $names).': '.$value;
            }
        }
    }

    expect($offenders)->toBe([], "A translation string names another system:\n  ".implode("\n  ", $offenders));
});

/**
 * Registries whose REASON strings name the reference systems on purpose — developer documentation
 * that happens to be a string literal because a gate reads it, and that no screen renders. Each
 * entry says why, and the gate fails on a STALE one, so a registry that stops naming a system
 * drops off the list rather than standing as a permanent exemption for whatever it says next.
 *
 * A NEW file naming a system is refused until somebody decides which it is: rendered (fix the
 * string) or a registry (register it here with its reason). That question is the point.
 *
 * @return array<string, string> path relative to app/ => reason
 */
function otherSystemsRegistries(): array
{
    return [
        'Support/PropertySettings.php' => 'OVERRIDABLE reasons — why a key may differ per property; read by PropertySettingsConformanceTest, rendered by nothing (PropertyOverrides reads only the KEYS; `class` is read by PropertySettings::portfolio()).',
        'Support/RowActionPolicy.php' => 'IN_ROW_EXCEPTIONS reasons — why an act stays on the row; read by the row-action gate, rendered by nothing.',
        'Support/OwnerVisibility.php' => 'Per-group reasons for what an owner may see; read by its gate, rendered by nothing.',
        'Support/InvoiceSettlement.php' => 'RELIEVED / LIVE partition reasons; read by the partition gate, rendered by nothing.',
    ];
}

it('names no other system in any inline string the application could render', function () {
    // The whole of `app/`: a helper text written inline instead of through `__()`, a notification
    // body, a mail subject, an API message, a service's toast. `app/Support` is included because
    // it holds the assistant's own inline vocabulary (`RecordStates`, both languages) and the
    // Filament components — its reason registries are the ONE exception, registered above.
    $files = RenderedText::phpFilesUnder(app_path());

    expect(count($files))->toBeGreaterThan(1000,
        'The application sweep found almost no files — it is reporting on a set it cannot see.');

    $offenders = [];
    $registries = otherSystemsRegistries();
    $stillNaming = [];
    $literals = 0;

    foreach ($files as $path) {
        $relative = str_replace(app_path().'/', '', $path);
        $names = [];

        foreach (RenderedText::stringLiterals($path) as [$line, $value]) {
            $literals++;

            if ($found = otherSystemsIn($value)) {
                $names[] = $relative.':'.$line.' → '.implode(', ', $found);
            }
        }

        if ($names === []) {
            continue;
        }

        if (array_key_exists($relative, $registries)) {
            $stillNaming[] = $relative;
        } else {
            $offenders = [...$offenders, ...$names];
        }
    }

    // Measured 55k on the day; the floor sits well under it so that moving the assistant's inline
    // vocabulary into `lang/` — the right home for it — does not read as the loader going blind.
    expect($literals)->toBeGreaterThan(30000,
        'The tokeniser returned almost no string literals — the sweep is vacuous.');

    expect($offenders)->toBe([], "An inline string names another system:\n  ".implode("\n  ", $offenders));

    // Stale exemption: a registry that no longer names a system must leave the list.
    expect(array_values(array_diff(array_keys($registries), $stillNaming)))->toBe([],
        'A registered registry no longer names any other system — remove it from otherSystemsRegistries().');
});

it('names no other system in any Blade template as the browser receives it', function () {
    $files = RenderedText::phpFilesUnder(resource_path('views'));

    expect(count($files))->toBeGreaterThan(50,
        'The view sweep found almost no templates — it is reporting on a set it cannot see.');

    // The control for the comment stripper: the statement template opens with a Blade comment that
    // names the reference system on purpose, and it must not be reported — or the first fix
    // anyone reaches for is deleting the comment rather than trusting the gate. Asserted on the
    // LOADER's output, not the raw file: a stripper that returned an empty string would also
    // report nothing, and "comment stripped" and "everything stripped" must not look alike.
    $statement = resource_path('views/tenants/statement.blade.php');
    expect(file_exists($statement))->toBeTrue();
    expect(otherSystemsIn((string) file_get_contents($statement)))->not->toBe([],
        'The control comment is gone — this test can no longer prove Blade comments are stripped.');

    $rendered = implode('', array_column(RenderedText::stringLiterals($statement), 1));
    expect($rendered)->toContain('<table')
        ->and(otherSystemsIn($rendered))->toBe([]);

    $offenders = [];
    $chars = 0;

    foreach ($files as $path) {
        // A template is ONE token to the tokeniser, so the line number is always 1 and is not printed.
        foreach (RenderedText::stringLiterals($path) as [, $value]) {
            $chars += strlen($value);

            if ($names = otherSystemsIn($value)) {
                $offenders[] = str_replace(resource_path('views').'/', '', $path).' → '.implode(', ', $names);
            }
        }
    }

    // Measured 281k on the day.
    expect($chars)->toBeGreaterThan(100000,
        'The templates yielded almost no rendered text — the sweep is vacuous.');

    expect($offenders)->toBe([], "A template names another system:\n  ".implode("\n  ", $offenders));
});

it('seeds no other system\'s name into reference or demo data', function () {
    $files = RenderedText::phpFilesUnder(database_path());

    expect(count($files))->toBeGreaterThan(100,
        'The seeder sweep found almost no files — it is reporting on a set it cannot see.');

    $offenders = [];
    $literals = 0;

    foreach ($files as $path) {
        foreach (RenderedText::stringLiterals($path) as [$line, $value]) {
            $literals++;

            if ($names = otherSystemsIn($value)) {
                $offenders[] = basename($path).':'.$line.' → '.implode(', ', $names);
            }
        }
    }

    expect($literals)->toBeGreaterThan(5000, 'The tokeniser returned almost no string literals — the sweep is vacuous.');

    expect($offenders)->toBe([], "Seeded data names another system:\n  ".implode("\n  ", $offenders));
});

it('names no other system in anything the assistant quotes as an answer', function () {
    // DERIVED from the assistant's own registry: `DocCorpus::SOURCES` (the handbook, the training
    // walkthroughs) plus `ROOT_FILES` (the business-rules register). `docs/modules/` is
    // `TECHNICAL_SOURCES`, indexed only under `ASSISTANT_INDEX_TECHNICAL_DOCS` — a DEPLOYER's env
    // switch, not a setting — and it is developer prose where the standard is the whole
    // explanation. Asserted OFF here so it is not swept; `config/assistant.php` says what turning
    // it on puts in front of the operator.
    expect((bool) config('assistant.index_technical_docs'))->toBeFalse(
        'Technical docs are indexed in this environment; the sweep would report developer prose.');

    $files = DocCorpus::files(base_path('docs'));

    expect(count($files))->toBeGreaterThan(30,
        'The assistant corpus resolved to almost no files — the sweep is reporting on a set it cannot see.');

    expect(array_keys($files))->toContain('BUSINESS-RULES.md');

    $offenders = [];

    foreach (array_keys($files) as $relative) {
        $body = (string) file_get_contents(base_path('docs/'.$relative));

        foreach (explode("\n", $body) as $i => $line) {
            if ($names = otherSystemsIn($line)) {
                $offenders[] = $relative.':'.($i + 1).' → '.implode(', ', $names);
            }
        }
    }

    expect($offenders)->toBe([],
        "A page the assistant quotes inside the panel names another system:\n  ".implode("\n  ", $offenders));
});

it('names no other system in the handbook\'s own chrome', function () {
    // The markdown is covered above through the corpus registry; the sidebar labels, the theme
    // components and the locale strings live beside it in `.vitepress/` and are rendered by the
    // same page. Build artefacts and the module cache are not sources.
    $root = base_path('docs/visual/.vitepress');
    $files = [];

    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)) as $file) {
        $path = $file->getPathname();

        if (str_contains($path, '/cache/') || str_contains($path, '/dist/')) {
            continue;
        }

        if (in_array($file->getExtension(), ['ts', 'mts', 'js', 'vue', 'json'], true)) {
            $files[] = $path;
        }
    }

    expect(count($files))->toBeGreaterThan(3,
        'The handbook chrome sweep found almost no files — it is reporting on a set it cannot see.');

    $offenders = [];

    foreach ($files as $path) {
        foreach (file($path) ?: [] as $i => $line) {
            if ($names = otherSystemsIn($line)) {
                $offenders[] = str_replace($root.'/', '', $path).':'.($i + 1).' → '.implode(', ', $names);
            }
        }
    }

    expect($offenders)->toBe([], "The handbook chrome names another system:\n  ".implode("\n  ", $offenders));
});

it('still recognises the name when a screen would print it', function () {
    // The control: a sweep whose matcher matched nothing would satisfy every assertion above.
    expect(otherSystemsIn("Off (Yardi's default): a charge steps nothing"))->toBe(['yardi'])
        ->and(otherSystemsIn('وهو الافتراضي في Yardi'))->toBe(['yardi'])
        // Glued to an Arabic clitic — «بـYardi», «وMRI» — the form a `\b` matcher cannot see under /u.
        ->and(otherSystemsIn('كما بـYardi'))->toBe(['yardi'])
        ->and(otherSystemsIn('الافتراضي في Yardi وMRI'))->toBe(['yardi', 'mri'])
        ->and(otherSystemsIn('an ORACLE database'))->toBe(['oracle'])
        ->and(otherSystemsIn('an MRI scan is not a property system'))->toBe(['mri'])
        // Whole words only: the reference system's name inside another word is not a mention.
        ->and(otherSystemsIn('sapling'))->toBe([])
        ->and(otherSystemsIn('yardigan'))->toBe([]);
});
