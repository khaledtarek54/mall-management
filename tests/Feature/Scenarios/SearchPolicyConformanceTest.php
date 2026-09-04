<?php

/*
|--------------------------------------------------------------------------
| Search is a system, not 47 resources on Filament's defaults
|--------------------------------------------------------------------------
| What this replaces: every resource was left on whatever stock Filament gave it, and the result
| was a lottery nobody could see. 7 resources had NO globally searchable attribute, so their
| records could not be found from the search bar under any spelling — `UtilityMeter` has a unique
| `meter_number` and was one of them. 3 searched an integer or an enum, so typing "1" into the bar
| returned accounting periods 1, 10, 11 and 12. `ViolationResource` pointed `$recordTitleAttribute`
| at `reference`, which is a PHP accessor and not a column. 5 tables rendered no search box at all.
|
| Every one of those failures is SILENT. An empty result set looks exactly like "no such record",
| which is why none of them was ever reported as a bug — and why a gate, rather than a convention,
| is what keeps them fixed.
|
| Note what this file does NOT re-test: authorization and property isolation. Global search runs
| through `canAccess()` and `getGlobalSearchEloquentQuery()` → `getEloquentQuery()`, so it inherits
| both by construction. That inheritance is proven in SearchIsolationTest rather than assumed here.
*/

use App\Models\Concerns\HasSearchText;
use App\Support\Search\SearchText;
use App\Support\SearchPolicy;
use Filament\Resources\Resource;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/* ---- helpers -------------------------------------------------------------- */

/**
 * Every Filament resource in EVERY panel, resolved from disk rather than from a
 * list someone maintains — resource #48 is covered the day it is written.
 *
 * **The panel directories are globbed, not named.** This read
 * `[app_path('Filament/Admin/Resources'), app_path('Filament/Portal/Resources')]`, which is a list
 * someone maintains wearing the clothes of a derivation — so the contractor panel added on
 * 2026-08-28 was swept by nothing, and its one resource turned out to be the only raw-column global
 * search left in the application (SW-130). Two globs rather than one `GLOB_BRACE` pattern: brace
 * expansion is absent on some libc builds, where it fails silently back to zero directories.
 *
 * @return array<int, class-string>
 */
function searchPolicyResources(): array
{
    $resources = [];

    foreach (glob(app_path('Filament/*/Resources')) ?: [] as $dir) {
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir)) as $file) {
            if (! str_ends_with($file->getFilename(), 'Resource.php')) {
                continue;
            }

            $class = 'App\\'.str_replace(['/', '.php'], ['\\', ''], substr($file->getPathname(), strlen(app_path()) + 1));

            if (class_exists($class) && is_subclass_of($class, Filament\Resources\Resource::class)) {
                $resources[] = $class;
            }
        }
    }

    return array_values(array_unique($resources));
}

function searchPolicyUsesBlob(string $model): bool
{
    return in_array(HasSearchText::class, class_uses_recursive($model), true);
}

/* ---- rule 1: every resource is classified --------------------------------- */

it('classifies every resource as globally searchable or exempt with a reason', function () {
    // The third state — "shipped with no searchable attribute and nobody decided that" — is what
    // hid 7 resources from the search bar. Forcing the choice is the whole point.
    $unclassified = [];

    foreach (searchPolicyResources() as $resource) {
        if (SearchPolicy::isGlobalSearchExempt($resource)) {
            continue;
        }

        if ($resource::getGloballySearchableAttributes() === []) {
            $unclassified[] = class_basename($resource);
        }
    }

    expect($unclassified)->toBe([], implode('', [
        'These resources cannot be found from global search and are not registered as exempt: ',
        implode(', ', $unclassified).'. ',
        'Either give them getGloballySearchableAttributes() or add them to SearchPolicy::GLOBAL_SEARCH_EXEMPT with a reason.',
    ]));
});

it('states a reason for every exemption', function () {
    // "Not searchable" without "because" is indistinguishable from an oversight a year later.
    $blank = [];

    foreach (SearchPolicy::GLOBAL_SEARCH_EXEMPT as $resource => $reason) {
        if (trim($reason) === '') {
            $blank[] = class_basename($resource);
        }
    }

    expect($blank)->toBe([], 'no reason given for: '.implode(', ', $blank));
});

it('actually disables global search on every exempt resource', function () {
    // The registry entry is a statement of intent; `$isGloballySearchable = false` is what Filament
    // reads. Listing a resource as exempt while leaving it searchable would be the worst of both.
    $stillSearchable = [];

    foreach (array_keys(SearchPolicy::GLOBAL_SEARCH_EXEMPT) as $resource) {
        if ($resource::canGloballySearch()) {
            $stillSearchable[] = class_basename($resource);
        }
    }

    expect($stillSearchable)->toBe([], implode('', [
        'Registered exempt but still searchable: '.implode(', ', $stillSearchable).'. ',
        'Add `protected static bool $isGloballySearchable = false;` to the resource.',
    ]));
});

it('does not carry an exemption for a resource that no longer exists', function () {
    // A stale entry silently grants an exemption to nothing, and reads as a decision that was made.
    $stale = array_diff(array_keys(SearchPolicy::GLOBAL_SEARCH_EXEMPT), searchPolicyResources());

    expect(array_values($stale))->toBe([], 'exempt but not a live resource: '.implode(', ', $stale));
});

/* ---- rule 2: searchable attributes are blobs, and they are real ----------- */

it('searches only fold-normalized blobs, never a raw column', function () {
    // The silent half of this: a path pointed at `tenant.name` compares a FOLDED query against an
    // UNFOLDED value, so it matches nothing for exactly the Arabic spellings the fold exists to fix
    // — while continuing to work for plain ASCII, which is what anyone testing it would try first.
    $raw = [];

    foreach (searchPolicyResources() as $resource) {
        // Exempt resources are skipped, and it matters WHY: they still carry a
        // `$recordTitleAttribute` (used for record titles, select labels and relation
        // managers), and Filament's default derives searchable attributes from it. So
        // AccountingPeriodResource still reports `period_no` here — it is simply never
        // used for search, because `canGloballySearch()` is false. Asserting against
        // resources that do not search would force us to strip title attributes that
        // the rest of the panel needs.
        if (SearchPolicy::isGlobalSearchExempt($resource)) {
            continue;
        }

        foreach ($resource::getGloballySearchableAttributes() as $attribute) {
            if (! str_ends_with($attribute, 'search_text')) {
                $raw[] = class_basename($resource).' → '.$attribute;
            }
        }
    }

    expect($raw)->toBe([], implode('', [
        'These search a raw column instead of a search_text blob: '.implode(', ', $raw).'. ',
        'Point the path at the related model\'s search_text (e.g. tenant.search_text).',
    ]));
});

/**
 * The OTHER half of "both sides go through SearchText", and the half nothing checked.
 *
 * Every gate above proves the STORED side is folded: the model has the trait, the column exists,
 * the paths end in `search_text`. None of them proved the QUERY side folds — and a resource that
 * declares `['search_text']` without `SearchesNormalizedText` hands Filament the operator's raw
 * keystrokes to compare against a folded blob, so "PTW-2026" is matched against "ptw20260001" and
 * finds nothing. It fails in total silence: no error, no empty state that looks wrong, just a
 * search bar that reports the record does not exist.
 *
 * Caught on a live database, not by a test — `WorkPermitResource` shipped every other search gate
 * green and returned zero hits for its own reference (2026-08-19).
 */
it('folds the QUERY side too, on every globally searchable resource', function () {
    $unfolded = [];

    foreach (searchPolicyResources() as $resource) {
        if (SearchPolicy::isGlobalSearchExempt($resource)) {
            continue;
        }

        // The trait, or an equivalent override declared on the class itself — what matters is that
        // `applyGlobalSearchAttributeConstraints` is not Filament's stock one, which compares raw.
        $method = new ReflectionMethod($resource, 'applyGlobalSearchAttributeConstraints');

        if (! str_starts_with((string) $method->getDeclaringClass()->getName(), 'App\\')) {
            $unfolded[] = class_basename($resource);
        }
    }

    expect($unfolded)->toBe([], implode('', [
        'These search a folded blob with an UNFOLDED query, so they match nothing an operator '.
        'types with punctuation or in Arabic: '.implode(', ', $unfolded).'. ',
        'Add `use App\\Filament\\Concerns\\SearchesNormalizedText;` to the resource.',
    ]));
});

it('points every relation search path at a relation that exists and carries a blob', function () {
    // A typo'd relation path throws at search time, not at boot — so it ships green and breaks the
    // first time an operator types. A VALID relation whose model has no blob is worse: it throws
    // nothing and silently matches nothing.
    $broken = [];

    foreach (searchPolicyResources() as $resource) {
        foreach ($resource::getGloballySearchableAttributes() as $attribute) {
            if (! str_contains($attribute, '.')) {
                continue;
            }

            $path = Str::beforeLast($attribute, '.');
            $model = $resource::getModel();

            try {
                $related = $model;
                foreach (explode('.', $path) as $segment) {
                    $related = (new $related)->{$segment}()->getRelated()::class;
                }
            } catch (Throwable) {
                $broken[] = class_basename($resource).' → '.$attribute.' (no such relation)';

                continue;
            }

            if (! searchPolicyUsesBlob($related)) {
                $broken[] = class_basename($resource).' → '.$attribute.' ('.class_basename($related).' has no search_text)';
            }
        }
    }

    expect($broken)->toBe([], 'broken relation search paths: '.implode('; ', $broken));
});

it('gives every globally searchable resource a model that carries the blob', function () {
    $missing = [];

    foreach (searchPolicyResources() as $resource) {
        if (SearchPolicy::isGlobalSearchExempt($resource)) {
            continue;
        }

        if (! searchPolicyUsesBlob($resource::getModel())) {
            $missing[] = class_basename($resource).' ('.class_basename($resource::getModel()).')';
        }
    }

    expect($missing)->toBe([], implode('', [
        'Searchable, but the model has no search_text blob: '.implode(', ', $missing).'. ',
        'Add App\\Models\\Concerns\\HasSearchText + a migration, and register the model in SearchPolicy::INDEXED.',
    ]));
});

/* ---- the registry and the schema agree ------------------------------------ */

it('backs every registered model with a real search_text column', function () {
    // Adding a model to SearchPolicy::INDEXED does NOT add the column — the migration that created
    // them is a fixed historical snapshot on purpose. Without this check, a model added to the
    // registry would search a column that does not exist: a SQL error in production, and nothing
    // at all in a test that never searched it.
    $missing = [];

    foreach (SearchPolicy::INDEXED as $model) {
        $table = (new $model)->getTable();

        if (! Schema::hasColumn($table, 'search_text')) {
            $missing[] = class_basename($model).' ('.$table.')';
        }
    }

    expect($missing)->toBe([], implode('', [
        'Registered in SearchPolicy::INDEXED but the table has no search_text column: ',
        implode(', ', $missing).'. Write a migration adding it.',
    ]));
});

it('gives every registered model the trait and a non-empty source list', function () {
    $broken = [];

    foreach (SearchPolicy::INDEXED as $model) {
        if (! searchPolicyUsesBlob($model)) {
            $broken[] = class_basename($model).' is missing the HasSearchText trait';

            continue;
        }

        if ((new $model)->searchTextSources() === []) {
            $broken[] = class_basename($model).' declares no searchTextSources()';
        }
    }

    expect($broken)->toBe([], implode('; ', $broken));
});

it('registers every model that carries the blob', function () {
    // THE COMPLETENESS DIRECTION, and the one nothing was asking. Every other check here starts
    // from INDEXED and validates what is in it, or starts from a resource and checks its model has
    // the trait. All of those pass while a model carries the trait, the column and a searchable
    // resource yet never appears in INDEXED — which is exactly what `RentableItem` did.
    //
    // The cost is silent and delayed. `atriom:rebuild-search` iterates INDEXED, so an unregistered
    // model is skipped by the ONE command that exists to re-fold blobs. Change the fold or a
    // `searchTextSources()`, run the rebuild as the docs instruct, and every existing row of that
    // model keeps its old blob forever while newly-saved rows get the new one — the search then
    // answers differently for the same query depending on when a row was last touched, which is
    // precisely the Arabic-spelling inconsistency the fold exists to remove.
    $unregistered = [];

    foreach (glob(app_path('Models/*.php')) as $file) {
        $model = 'App\\Models\\'.basename($file, '.php');

        if (! class_exists($model) || ! is_subclass_of($model, Model::class)) {
            continue;
        }

        if ((new ReflectionClass($model))->isAbstract()) {
            continue;
        }

        if (searchPolicyUsesBlob($model) && ! in_array($model, SearchPolicy::INDEXED, true)) {
            $unregistered[] = class_basename($model);
        }
    }

    sort($unregistered);

    expect($unregistered)->toBe([], implode('', [
        'These models maintain a search_text blob but are not in SearchPolicy::INDEXED, so ',
        'atriom:rebuild-search skips them and their blobs go stale on the next fold change: ',
        implode(', ', $unregistered),
        '. Register them, or drop the HasSearchText trait if they should not be searchable.',
    ]));
});

it('keeps the blob out of every serialized payload', function () {
    // The blob concatenates a record's identifying fields. On Tenant that is name, legal name,
    // contact person, email and phone in one string — on a model the tenant-facing API returns.
    $exposed = [];

    foreach (SearchPolicy::INDEXED as $model) {
        if (! in_array('search_text', (new $model)->getHidden(), true)) {
            $exposed[] = class_basename($model);
        }
    }

    expect($exposed)->toBe([], 'search_text is serializable on: '.implode(', ', $exposed));
});

it('never makes the blob mass-assignable', function () {
    // The saving hook overwrites whatever is submitted, so this is belt-and-braces — but a
    // fillable derived column invites someone to "just set it" in a seeder and wonder why it
    // reverts.
    $fillable = [];

    foreach (SearchPolicy::INDEXED as $model) {
        if (in_array('search_text', (new $model)->getFillable(), true)) {
            $fillable[] = class_basename($model);
        }
    }

    expect($fillable)->toBe([], 'search_text is fillable on: '.implode(', ', $fillable));
});

/* ---- rule 3: no table renders a search box it cannot answer ---------------- */

it('renders no search box on a table that could never answer it', function () {
    // TableDefaults gives every table the blob search, which is what renders the box. A table whose
    // model has no blob AND marks no column searchable therefore shows an input that always returns
    // nothing — which an operator reads as "no such row", not as "this box is broken". Such a table
    // must opt out explicitly with ->searchable(false).
    $dead = [];

    $check = function (string $model, string $file, string $label) use (&$dead): void {
        if (searchPolicyUsesBlob($model)) {
            return;
        }

        $source = file_get_contents($file);

        if (str_contains($source, '->searchable(')) {
            return; // either a searchable column, or an explicit ->searchable(false)
        }

        $dead[] = $label;
    };

    foreach (searchPolicyResources() as $resource) {
        // A resource's columns live in its Tables/ directory, so scan the whole resource tree.
        $dir = dirname((new ReflectionClass($resource))->getFileName());
        $source = '';

        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir)) as $inner) {
            if ($inner->getExtension() === 'php') {
                $source .= file_get_contents($inner->getPathname());
            }
        }

        if (! searchPolicyUsesBlob($resource::getModel()) && ! str_contains($source, '->searchable(')) {
            $dead[] = class_basename($resource);
        }

        foreach ($resource::getRelations() as $relation) {
            if (! is_string($relation) || ! class_exists($relation)) {
                continue;
            }

            try {
                $related = (new ($resource::getModel()))->{$relation::getRelationshipName()}()->getRelated()::class;
            } catch (Throwable) {
                continue;
            }

            $check($related, (new ReflectionClass($relation))->getFileName(), class_basename($relation));
        }
    }

    expect(array_values(array_unique($dead)))->toBe([], implode('', [
        'These render a search box that can never match anything: '.implode(', ', array_unique($dead)).'. ',
        'Add ->searchable(false) with a comment saying why, or give the model a search_text blob.',
    ]));
});

/* ---- the ordering registry stays honest ----------------------------------- */

it('ranks only resources that exist and are actually searchable', function () {
    $stale = [];

    foreach (SearchPolicy::PRIORITY as $resource) {
        if (! class_exists($resource)) {
            $stale[] = $resource.' (no such class)';
        } elseif (SearchPolicy::isGlobalSearchExempt($resource)) {
            $stale[] = class_basename($resource).' (ranked but exempt)';
        }
    }

    expect($stale)->toBe([], 'SearchPolicy::PRIORITY is stale: '.implode(', ', $stale));
});

it('ranks each resource at most once', function () {
    // A duplicate makes the order depend on which entry array_search finds first — the kind of
    // thing that looks fine until two categories swap places for no visible reason.
    $duplicates = array_keys(array_filter(array_count_values(SearchPolicy::PRIORITY), fn (int $n): bool => $n > 1));

    expect(array_map('class_basename', $duplicates))->toBe([], 'listed twice in SearchPolicy::PRIORITY');
});

/* ---- the fold itself ------------------------------------------------------ */

it('folds the Arabic spellings an Egyptian operator actually types', function () {
    // These are not hypothetical: they are the spelling pairs that appear across the same tenant
    // list depending on who typed the row. Each pair MUST fold to one string or search misses.
    $pairs = [
        ['أحمد', 'احمد'],                 // hamza above alef
        ['إبراهيم', 'ابراهيم'],           // hamza below alef
        ['آمنة', 'امنه'],                 // madda + teh marbuta
        ['شركة الفتح', 'شركه الفتح'],     // teh marbuta — endemic in company names
        ['مصطفى', 'مصطفي'],               // alef maqsura
        ['مؤسسة', 'موسسه'],               // hamza carrier + teh marbuta
        ['مُحَمَّد', 'محمد'],                // tashkeel, as pasted from Word
        ['٢٠٢٦', '2026'],                 // Arabic-Indic digits
    ];

    foreach ($pairs as [$a, $b]) {
        expect(SearchText::normalize($a))
            ->toBe(SearchText::normalize($b), "«{$a}» and «{$b}» must fold to the same string");
    }
});

it('makes document numbers findable with or without their punctuation', function () {
    expect(SearchText::normalize('INV-AW-202607-0110'))->toBe('invaw2026070110')
        ->and(SearchText::normalize('invaw2026070110'))->toBe('invaw2026070110');
});

it('folds a pure-punctuation query to nothing so callers can refuse it', function () {
    // The dangerous alternative is returning a blank string that a caller LIKEs against, which
    // matches every row in the table.
    expect(SearchText::words('---'))->toBe([])
        ->and(SearchText::words('  '))->toBe([])
        ->and(SearchText::words(null))->toBe([]);
});

it('keeps words separate so two fields cannot fuse into a match', function () {
    // If `blob` joined without a separator, a tenant named Zara with contact Ahmed would match the
    // query "raahmed" — a hit no human could explain.
    expect(SearchText::blob(['Zara', 'Ahmed']))->toBe('zara ahmed');
});
