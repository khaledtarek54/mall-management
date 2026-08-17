<?php

use App\Models\InventoryItem;
use App\Models\Invoice;
use App\Models\Lease;
use App\Models\Tenant;
use App\Models\Unit;
use App\Models\Vendor;
use App\Support\Filament\EntitySelect;
use App\Support\Filament\EntitySelectFilter;
use App\Support\Search\OptionDisplay;
use App\Support\Search\RecordOption;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;

/**
 * **Every dropdown that picks a RECORD goes through one registry.**
 *
 * WHY THIS EXISTS. `Select::make('tenant_id')->relationship('tenant', 'name')->searchable()` is
 * one line, it looks finished, and it is wrong four ways — it searches one raw column, it folds
 * neither side of the comparison, it shows one column, and every one of those failures renders as
 * an empty or ambiguous dropdown rather than an error. Nobody reports a picker that finds nothing;
 * they retype the name, then give up and leave the form. That is exactly the failure class
 * `SearchPolicyConformanceTest` was written for on the LIST side, and the pickers were the surface
 * it never covered.
 *
 * So the rules, all of them enforced by reading the source rather than a hand-kept list:
 *
 *  1. A Select whose options come from a MODEL is an `EntitySelect` (or `EntitySelectFilter`).
 *     Scalar-value pickers — "which category", "which year", `pluck('city')` — are not, and the
 *     gate distinguishes them by what the query plucks rather than by a registry of exceptions.
 *  2. Every `EntitySelect` declares `->entity(Model::class)`. One without it is a plain Select
 *     wearing the name, which is the most confusing possible state.
 *  3. Only `RecordOption` builds option markup. `allowHtml()` makes Filament emit a label through
 *     `{!! !!}`; every value in an option is operator-typed, so a label built by string
 *     concatenation anywhere else is stored XSS reachable from any form that lists the record.
 *  4. Every relation in `OptionDisplay::EAGER` exists. A typo'd eager load silently restores the
 *     N+1 it was added to remove, and looks identical to a working one.
 *  5. Every `PRELOAD` model is one whose size is bounded by the business, and every presenter
 *     returns a usable option for a real record.
 */

/** Source files that legitimately mention a model in a Select without picking one. */
function entitySelectSources(): array
{
    return filamentSources();
}

/**
 * Split a file into its component chains — `X::make(` up to the next `Y::make(`.
 *
 * @return array<int, array{class: string, name: string, body: string, line: int}>
 */
function selectComponentChains(string $source): array
{
    preg_match_all('/([A-Z]\w*)::make\(/', $source, $matches, PREG_OFFSET_CAPTURE);

    $starts = [];
    foreach ($matches[0] as $index => $hit) {
        $starts[] = ['class' => $matches[1][$index][0], 'offset' => $hit[1]];
    }

    $chains = [];
    foreach ($starts as $index => $start) {
        $end = $starts[$index + 1]['offset'] ?? strlen($source);
        $body = substr($source, $start['offset'], $end - $start['offset']);

        preg_match('/::make\(\s*[\'"]([^\'"]*)[\'"]/', $body, $named);

        $chains[] = [
            'class' => $start['class'],
            'name' => $named[1] ?? '?',
            'body' => $body,
            'line' => substr_count(substr($source, 0, $start['offset']), "\n") + 1,
        ];
    }

    return $chains;
}

it('routes every model-backed picker through EntitySelect', function () {
    // A chain picks a RECORD when its options come from a model query that plucks the KEY —
    // `pluck('name', 'id')`, `mapWithKeys(fn ($m) => [$m->id => …])`, `->relationship(…)`. A chain
    // that plucks a bare column (`pluck('city')`, `pluck('category')`) is choosing a VALUE, and a
    // rich two-line option for the string "Cairo" would be noise. Read off the code, not a
    // registry, so a picker written next month is judged by the same rule.
    $offenders = [];

    foreach (entitySelectSources() as $file) {
        $source = file_get_contents($file);

        foreach (selectComponentChains($source) as $chain) {
            if (! in_array($chain['class'], ['Select', 'SelectFilter'], true)) {
                continue;
            }

            // The KEY is what separates the two. `pluck('name', 'id')` and
            // `mapWithKeys(fn ($m) => [$m->id => …])` key by the record — that is a record picker.
            // `pluck('city', 'city')` keys by the value itself, and a two-line option for the
            // string "Cairo" would be noise, so those stay plain Selects with no exemption needed.
            $picksRecord = str_contains($chain['body'], '->relationship(')
                || preg_match('/pluck\(\s*[\'"][\w.]+[\'"]\s*,\s*[\'"](?:\w+\.)?id[\'"]\s*\)/', $chain['body'])
                || preg_match('/mapWithKeys\([^;]{0,300}\$\w+->id\s*=>/s', $chain['body']);

            if (! $picksRecord) {
                continue;
            }

            $offenders[] = str_replace(base_path().'/', '', $file).':'.$chain['line']
                .' — '.$chain['class']."::make('{$chain['name']}')";
        }
    }

    // The exemptions are chains that pick a record and deliberately stay plain, each for a reason
    // that survives review. Kept here rather than in a support class because there are three of
    // them and they are all one shape: a fixed catalogue nobody searches.
    $exempt = [
        // Roles: fourteen rows in a permission matrix, chosen by name. `OptionDisplay::PLAIN`
        // records the same decision on the model side.
        "Select::make('roles')",
        "SelectFilter::make('roles')",
        // A fiscal year IS its number; there is nothing else about one to show.
        "SelectFilter::make('fiscal_year_id')",
        // Repayments of ONE advance, listed in date order under the advance itself.
        "Select::make('repayment_id')",
        // Saved table views — the operator's own, named by them, never more than a handful.
        "Select::make('view_id')",
        // The accounting period picker: a fixed ladder of twelve, chosen by period number.
        "Select::make('accounting_period_id')",
    ];

    $offenders = array_values(array_filter(
        $offenders,
        fn (string $row): bool => ! collect($exempt)->contains(fn (string $e) => str_contains($row, $e)),
    ));

    expect($offenders)->toBe([], "These pickers choose a RECORD but are not EntitySelect/EntitySelectFilter:\n  ".implode("\n  ", $offenders));
});

it('gives every EntitySelect an entity to pick from', function () {
    $orphans = [];

    foreach (entitySelectSources() as $file) {
        $source = file_get_contents($file);

        foreach (selectComponentChains($source) as $chain) {
            if (! in_array($chain['class'], ['EntitySelect', 'EntitySelectFilter'], true)) {
                continue;
            }

            if (str_contains($chain['body'], '->entity(')) {
                continue;
            }

            $orphans[] = str_replace(base_path().'/', '', $file).':'.$chain['line']
                ." — {$chain['class']}::make('{$chain['name']}')";
        }
    }

    expect($orphans)->toBe([], "EntitySelect without ->entity(): a plain Select wearing the name.\n  ".implode("\n  ", $orphans));
});

it('lets only RecordOption build option markup', function () {
    // `allowHtml()` hands the label to Filament's `{!! !!}` and to the browser as innerHTML. Every
    // value in an option is operator-typed — a tenant name, a unit code — so a label built by
    // concatenation is stored XSS reachable from any form that lists the record. `RecordOption`
    // escapes each part; nothing else may produce the markup.
    $offenders = [];

    foreach (entitySelectSources() as $file) {
        $source = file_get_contents($file);

        if (! str_contains($source, '->allowHtml(')) {
            continue;
        }

        foreach (selectComponentChains($source) as $chain) {
            if (! str_contains($chain['body'], '->allowHtml(')) {
                continue;
            }

            // An EntitySelect gets its markup from RecordOption by construction.
            if (str_contains($chain['body'], '->entity(')) {
                continue;
            }

            if (str_contains($chain['body'], 'RecordOption')) {
                continue;
            }

            $offenders[] = str_replace(base_path().'/', '', $file).':'.$chain['line']
                ." — {$chain['class']}::make('{$chain['name']}')";
        }
    }

    expect($offenders)->toBe([], "allowHtml() with a hand-built label — escape it through RecordOption:\n  ".implode("\n  ", $offenders));
});

it('eager-loads only relations that exist', function () {
    $broken = [];

    foreach (OptionDisplay::EAGER as $model => $relations) {
        $instance = new $model;

        foreach ($relations as $path) {
            $current = $instance;

            foreach (explode('.', $path) as $segment) {
                if (! method_exists($current, $segment)) {
                    $broken[] = class_basename($model).' → '.$path." (no `{$segment}()`)";

                    continue 2;
                }

                $relation = $current->{$segment}();

                if (! $relation instanceof Relation) {
                    $broken[] = class_basename($model).' → '.$path." (`{$segment}()` is not a relation)";

                    continue 2;
                }

                $current = $relation->getRelated();
            }
        }
    }

    expect($broken)->toBe([], "OptionDisplay::EAGER names relations that do not exist — a typo here silently restores the N+1:\n  ".implode("\n  ", $broken));
});

it('presents every registered model as a usable option', function () {
    // Not "does the closure exist" — does it RUN, on an instance, and produce a title. A presenter
    // that fataled on a null relation would otherwise only be found by opening the form.
    $failures = [];

    foreach (OptionDisplay::presentedModels() as $model) {
        try {
            $option = OptionDisplay::for(new $model);
        } catch (Throwable $e) {
            $failures[] = class_basename($model).' — '.$e->getMessage();

            continue;
        }

        if (! $option instanceof RecordOption) {
            $failures[] = class_basename($model).' — presenter did not return a RecordOption';

            continue;
        }

        if ($option->title === '') {
            $failures[] = class_basename($model).' — empty title (an option nobody can click)';
        }
    }

    expect($failures)->toBe([], "Presenters that cannot present:\n  ".implode("\n  ", $failures));
});

it('keeps preloading to sets the business bounds', function () {
    // Preloading means "every row of this table, in the page payload". The bar is not "small
    // today" — a tenant table is small on day one of every deployment. It is "cannot grow without
    // the business changing shape".
    expect(OptionDisplay::PRELOAD)
        ->not->toContain(Tenant::class)
        ->not->toContain(Unit::class)
        ->not->toContain(Lease::class)
        ->not->toContain(Invoice::class)
        ->not->toContain(InventoryItem::class)
        ->not->toContain(Vendor::class);

    foreach (OptionDisplay::PRELOAD as $model) {
        expect(class_exists($model))->toBeTrue("PRELOAD names a class that does not exist: {$model}")
            ->and(is_subclass_of($model, Model::class))->toBeTrue("PRELOAD names a non-model: {$model}");
    }
});

it('states a reason for every model left on the plain label', function () {
    foreach (OptionDisplay::PLAIN as $model => $reason) {
        expect(class_exists($model))->toBeTrue("PLAIN names a class that does not exist: {$model}")
            ->and(strlen($reason))->toBeGreaterThan(40, "PLAIN reason for {$model} is too short to be a reason")
            ->and(OptionDisplay::hasPresenter($model))->toBeFalse("{$model} is listed as PLAIN but has a presenter");
    }

    foreach (OptionDisplay::PICKER_SCOPES as $model => $reason) {
        expect(class_exists($model))->toBeTrue("PICKER_SCOPES names a class that does not exist: {$model}")
            ->and(strlen($reason))->toBeGreaterThan(40, "PICKER_SCOPES reason for {$model} is too short to be a reason");
    }
});

it('names what an operator may type, in both languages', function () {
    // A prompt is the whole reason a phone number is discoverable — a picker that says "Search…"
    // teaches the operator that it searches names. Missing from AR is the same failure in the
    // language half this operator's staff work in, so both are checked, and `fallback: false`
    // because `Lang::has($key, 'ar')` silently answers for English otherwise.
    $missing = [];

    foreach (OptionDisplay::presentedModels() as $model) {
        $key = 'admin.search.prompts.'.str(class_basename($model))->snake()->toString();

        if (! Lang::has($key, 'en', fallback: false)) {
            continue; // Falls back to the generic prompt, which is a decision the registry allows.
        }

        if (! Lang::has($key, 'ar', fallback: false)) {
            $missing[] = $key.' [ar]';
        }
    }

    foreach (['searching', 'no_results', 'and_more', 'outstanding', 'header_account', 'inactive'] as $leaf) {
        foreach (['en', 'ar'] as $locale) {
            if (! Lang::has("admin.search.option.{$leaf}", $locale, fallback: false)) {
                $missing[] = "admin.search.option.{$leaf} [{$locale}]";
            }
        }
    }

    expect($missing)->toBe([], "Untranslated picker vocabulary:\n  ".implode("\n  ", $missing));
})->group('i18n');

it('found something to sweep', function () {
    // The predecessor of this gate's shape swept a trait FQCN that does not exist in the version we
    // ship and was green for a year over zero models. An empty sweep must fail loudly.
    $entityChains = 0;

    foreach (entitySelectSources() as $file) {
        foreach (selectComponentChains(file_get_contents($file)) as $chain) {
            if (in_array($chain['class'], ['EntitySelect', 'EntitySelectFilter'], true)) {
                $entityChains++;
            }
        }
    }

    expect($entityChains)->toBeGreaterThan(50, 'The sweep found almost no entity pickers — it is matching nothing.')
        ->and(count(OptionDisplay::presentedModels()))->toBeGreaterThan(15);
});
