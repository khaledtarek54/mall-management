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

/**
 * Every method declared in a file, name => source from its signature to the next one.
 *
 * A crude slice on purpose. The only question asked of it is "does this method's text pluck a
 * key", and a brace-counting parse would have to survive `{$interpolation}` — PHP's tokenizer
 * emits `T_CURLY_OPEN` for the opening and a plain `}` for the close, which is exactly what left
 * `TestHelperUniquenessConformanceTest` blind for a year over the one file it existed to watch.
 *
 * @return array<string, string>
 */
function selectHelperBodies(string $source): array
{
    if (! preg_match_all('/^\s*(?:public|protected|private)?\s*(?:static\s+)?function\s+(\w+)\s*\(/m', $source, $matches, PREG_OFFSET_CAPTURE)) {
        return [];
    }

    $starts = [];

    foreach ($matches[0] as $index => $hit) {
        $starts[] = ['name' => $matches[1][$index][0], 'offset' => $hit[1]];
    }

    $bodies = [];

    foreach ($starts as $index => $start) {
        $end = $starts[$index + 1]['offset'] ?? strlen($source);
        $bodies[$start['name']] = substr($source, $start['offset'], $end - $start['offset']);
    }

    return $bodies;
}

/**
 * A chain's body, plus the body of any method in the SAME FILE it calls to build its options.
 *
 * **ONE HOP, derived — and without it this gate could be defeated by an `Extract Method`.** The
 * record test below reads the chain's own text for a `pluck('name', 'id')`, and a picker written
 * `->options(fn () => $this->warehouseOptions())` has no such text in it: the pluck is twenty
 * lines further down the file in a private helper. Measured 2026-09-04 (SW-196): the sweep
 * reported ZERO offenders and there were ELEVEN, across seven files — three warehouse pickers,
 * two work-order assignees, a custodian, a payroll employee, an advance, a unit and two invoice
 * lines.
 *
 * `$this->`, `self::` and `static::` only, and only methods declared in the same file. A hop into
 * another class would need the whole call graph, and a private helper beside the picker is the
 * shape that actually hides these.
 *
 * Joined with a `;` rather than a newline, because the `mapWithKeys` pattern below matches across
 * up to 300 characters of anything-but-a-semicolon — without the separator a `mapWithKeys(` in the
 * chain could pair with an unrelated `->id =>` in the helper and invent an offender.
 *
 * @param  array<string, string>  $helperBodies
 */
function selectChainWithItsHelpers(string $body, array $helperBodies): string
{
    if (! preg_match_all('/(?:\$this->|self::|static::)(\w+)\(/', $body, $calls)) {
        return $body;
    }

    foreach (array_unique($calls[1]) as $method) {
        $body .= "\n;\n".($helperBodies[$method] ?? '');
    }

    return $body;
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
        $helperBodies = selectHelperBodies($source);

        foreach (selectComponentChains($source) as $chain) {
            if (! in_array($chain['class'], ['Select', 'SelectFilter'], true)) {
                continue;
            }

            // The KEY is what separates the two. `pluck('name', 'id')` and
            // `mapWithKeys(fn ($m) => [$m->id => …])` key by the record — that is a record picker.
            // `pluck('city', 'city')` keys by the value itself, and a two-line option for the
            // string "Cairo" would be noise, so those stay plain Selects with no exemption needed.
            // The chain PLUS the same-file helpers it calls — see selectChainWithItsHelpers().
            $body = selectChainWithItsHelpers($chain['body'], $helperBodies);

            $picksRecord = str_contains($body, '->relationship(')
                || preg_match('/pluck\(\s*[\'"][\w.]+[\'"]\s*,\s*[\'"](?:\w+\.)?id[\'"]\s*\)/', $body)
                || preg_match('/mapWithKeys\([^;]{0,300}\$\w+->id\s*=>/s', $body);

            if (! $picksRecord) {
                continue;
            }

            $offenders[] = str_replace(base_path().'/', '', $file).':'.$chain['line']
                .' — '.$chain['class']."::make('{$chain['name']}')";
        }
    }

    // The exemptions are chains that pick a record and deliberately stay plain, each for a reason
    // that survives review: `[file fragment, chain, why]`.
    //
    // The file fragment is `''` for the seven that predate the one-hop expansion and are
    // unambiguous by column name. **Everything added since NAMES its file**, because a bare
    // `Select::make('employee_id')` key would exempt every future picker on that column too —
    // which is how an exemption list stops being a list of decisions and becomes a hole.
    $exempt = [
        ['', "Select::make('roles')", 'Fourteen rows in a permission matrix, chosen by name. `OptionDisplay::PLAIN` records the same decision on the model side.'],
        ['', "SelectFilter::make('roles')", 'The filter half of the roles picker above, exempt for the same reason and over the same fourteen rows.'],
        ['', "SelectFilter::make('fiscal_year_id')", 'A fiscal year IS its number; there is nothing else about one to show on a second line.'],
        ['', "Select::make('repayment_id')", 'Repayments of ONE advance, listed in date order under the advance itself — a handful of rows nobody searches.'],
        ['', "Select::make('view_id')", 'Saved table views: the operator\'s own, named by them, never more than a handful on one list.'],
        ['', "Select::make('accounting_period_id')", 'The accounting period picker — a fixed ladder of twelve, chosen by period number rather than searched.'],
        ['', "Select::make('trades')", 'Which trades a vendor does: fourteen rows of facility configuration, ticked from a preloaded list. `Trade` carries no search blob by design (`SearchPolicy::GLOBAL_SEARCH_EXEMPT`), so an EntitySelect\'s folded search would have nothing to search.'],

        // ── Revealed by the one-hop expansion (SW-196, 2026-09-04) ───────────────────────────
        //
        // Eleven pickers had been invisible to this gate. Three were converted in the same change
        // (both ends of the stock transfer, and the ticket consumption store). These are the rest,
        // and they split into two kinds — say which, because "this one is different" is not
        // reviewable otherwise.
        //
        // (a) Deliberately plain, and converting them would BREAK something measured:
        ['Custodies/Schemas/CustodyForm.php', "Select::make('employee_id')", 'The custodian must be offered back after they leave the payroll or their asset_id moves to another mall, and EntitySelect resolves a label through the SCOPED pickable() query — which is also its write guard, so it structurally cannot. That form\'s own docblock measures the consequence against filament v4.11.8: a value the picker cannot label makes Rule::in([]) and the whole custody unsavable, purpose and reference included, arriving the day the person holding it leaves.'],
        ['PayrollLinesRelationManager.php', "Select::make('employee_advance_id')", 'One employee\'s advances, and the CURRENTLY LINKED advance stays selectable even after it runs out of headroom — the same offer-the-stored-value-back requirement as the custodian above, which a scoped label lookup cannot satisfy.'],
        ['Admin/Actions/InvoiceActions.php', "Select::make('invoice_item_id')", 'The LINES of the one invoice being acted on, listed under the document itself — the same shape as repayment_id, and InvoiceItem carries no search blob to fold against.'],

        // (b) Record pickers that SHOULD move and have not yet. Each is on a resource Create form
        //     swept by `EveryPickerShowsSomethingConformanceTest`, so converting one changes what
        //     that sweep sees; they are named here rather than converted blind, and each says what
        //     it is waiting on.
        ['PayrollLinesRelationManager.php', "Select::make('employee_id')", 'Should be an EntitySelect over Employee narrowed to the run\'s property and excluding employees already on a line. Not converted with SW-196 because payroll is the one module where an unsavable draft run blocks a pay date; it needs its own change with the run-scope clause driven through the real modal.'],
        ['FacilityWorkOrders/Schemas/FacilityWorkOrderForm.php', "Select::make('assigned_to_user_id')", 'Should be an EntitySelect over User — `OwnerRequestForm` already picks an assignee that way, and the User presenter exists precisely so an operator can see what the person can DO. Deferred with SW-196 because this is a swept Create form and the reachability clause (assigned to this mall OR unassigned) has to be re-proved against that sweep, not reasoned about.'],
        ['FacilityWorkOrders/Schemas/CorrectiveWorkOrderForm.php', "Select::make('assigned_to_user_id')", 'The same picker on the corrective form, waiting on the same conversion and for the same reason — the two share `technicianOptions()` and must move together or the two doors onto one field diverge.'],
        ['UnitOwnerships/Schemas/UnitOwnershipForm.php', "Select::make('unit_id')", 'Should be an EntitySelect over Unit narrowed by the form\'s own asset_id, beside the EntitySelect tenant picker already in that section. Deferred with SW-196: its helper returns [] for a blank asset_id, which on a swept Create form is the difference between "waiting for a parent" and "offers nothing", and that distinction has to be settled in the sweep rather than here.'],
    ];

    // An exemption nobody can review is not an exemption, and a STALE one silently widens the
    // gate — so both are checked against what this sweep actually found, before it is filtered.
    foreach ($exempt as [$file, $chain, $why]) {
        expect(strlen($why))->toBeGreaterThan(60, "Exemption {$file} {$chain}: the reason is too thin to review");

        expect(collect($offenders)->contains(fn (string $row): bool => str_contains($row, $file) && str_contains($row, $chain)))
            ->toBeTrue("Stale exemption: {$file} {$chain} no longer names a plain record picker — delete the entry.");
    }

    $unexplained = array_values(array_filter(
        $offenders,
        fn (string $row): bool => ! collect($exempt)->contains(
            fn (array $entry): bool => str_contains($row, $entry[0]) && str_contains($row, $entry[1]),
        ),
    ));

    expect($unexplained)->toBe([], "These pickers choose a RECORD but are not EntitySelect/EntitySelectFilter:\n  ".implode("\n  ", $unexplained));
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
