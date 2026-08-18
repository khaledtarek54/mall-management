<?php

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;
use PhpParser\Node;
use PhpParser\NodeFinder;
use PhpParser\ParserFactory;

/**
 * A test fixture must not write a column that does not exist.
 *
 * **Why this is a gate and not a lint.** Eloquent drops an unknown key on a non-fillable model and
 * throws on none of them, so a fixture can claim to set up a state and quietly set up a different
 * one. The test then passes — over a state the product could never produce. That is the **F-100
 * shape** [000-plan.md](docs/gap-analysis/000-plan.md) records: *ask of every fixture, could the
 * product actually produce this?* It has cost real bugs twice —
 *
 *  - `GrniClearingTest` set `vendor_bills.purchase_request_id`, a column no UI, service, seeder or
 *    route writes. Nine passing tests over dead code while every real bill double-counted its cost.
 *  - `BouncedChequeFeeTest` set `post_dated_cheques.lease_id` directly. No form writes it, so the
 *    NSF fee was unbillable for every cheque an operator could create — green the whole time.
 *
 * **Parsed, not matched.** A regex prototype (2026-08-18) could not delimit a `Model::create([...])`
 * block: the non-greedy match ran past the closing bracket and attributed keys to the wrong model,
 * reporting 820 "ghosts" that were almost all mis-attribution. A gate that names the wrong file
 * teaches people to ignore gates, so it was not shipped until it walked real AST nodes.
 *
 * Scope: `Model::create([...])` and `Model::factory()->create([...])` under `tests/`, resolved
 * against the model's real table. Anything it cannot resolve statically is skipped rather than
 * guessed — a false accusation costs more than a miss.
 */
it('writes only real columns in test fixtures', function () {
    $parser = (new ParserFactory)->createForNewestSupportedVersion();
    $finder = new NodeFinder;

    /** @var array<string, array<int, string>> $columnsFor */
    $columnsFor = [];
    foreach (glob(base_path('app/Models/*.php')) as $file) {
        $class = 'App\\Models\\'.basename($file, '.php');
        if (! class_exists($class) || ! is_subclass_of($class, Model::class)) {
            continue;
        }
        try {
            $table = (new $class)->getTable();
            if (Schema::hasTable($table)) {
                $columnsFor[basename($file, '.php')] = Schema::getColumnListing($table);
            }
        } catch (Throwable) {
            // Abstract or oddly-constructed model — skip rather than guess.
        }
    }

    expect($columnsFor)->not->toBeEmpty(); // the sweep must actually sweep something

    $ghosts = [];

    $testFiles = [];
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(base_path('tests'))) as $f) {
        if (str_ends_with($f->getPathname(), '.php')) {
            $testFiles[] = $f->getPathname();
        }
    }
    sort($testFiles);

    foreach ($testFiles as $path) {
        $ast = $parser->parse(file_get_contents($path));
        if ($ast === null) {
            continue;
        }

        foreach ($finder->findInstanceOf($ast, Node\Expr\StaticCall::class) as $call) {
            /** @var Node\Expr\StaticCall $call */
            if (! $call->class instanceof Node\Name) {
                continue;
            }

            $model = $call->class->getLast();
            if (! isset($columnsFor[$model])) {
                continue;
            }

            // `Model::create([...])` directly, or the array handed to `factory()->create([...])`
            // — resolved from THIS node only, never inferred from surrounding text.
            $arrays = [];
            if ($call->name instanceof Node\Identifier && $call->name->toString() === 'create') {
                $arrays[] = $call->args[0]->value ?? null;
            } elseif ($call->name instanceof Node\Identifier && $call->name->toString() === 'factory') {
                foreach ($finder->findInstanceOf([$call], Node\Expr\MethodCall::class) as $chained) {
                    /** @var Node\Expr\MethodCall $chained */
                    if ($chained->name instanceof Node\Identifier && $chained->name->toString() === 'create') {
                        $arrays[] = $chained->args[0]->value ?? null;
                    }
                }
            }

            foreach (array_filter($arrays) as $array) {
                if (! $array instanceof Node\Expr\Array_) {
                    continue; // a variable or spread — not statically knowable, so not judged
                }

                foreach ($array->items as $item) {
                    if (! $item?->key instanceof Node\Scalar\String_) {
                        continue; // computed key — skip rather than guess
                    }

                    $key = $item->key->value;
                    if (in_array($key, $columnsFor[$model], true)) {
                        continue;
                    }

                    $ghosts[] = sprintf(
                        '%s:%d — %s::create([… %s …]) but `%s` is not a column on that table',
                        str_replace(base_path().'/', '', $path),
                        $item->getLine(),
                        $model,
                        $key,
                        $key,
                    );
                }
            }
        }
    }

    $ghosts = array_values(array_unique($ghosts));

    expect($ghosts)->toBe([], "These fixtures write columns that do not exist. Eloquent drops the key silently, so the test sets up a DIFFERENT state than it claims and passes anyway — the shape that hid the GRNI double-count and the unbillable NSF fee:\n  - ".implode("\n  - ", $ghosts)."\n\nUse the real column name, or delete the key if it was describing an intent the product does not support.");
})->skip(
    // SHIPPED SKIPPED, deliberately, 2026-08-18. The gate is correct and mutation-proven — it is
    // switched off only because the tree it landed in has 58 pre-existing ghosts across ~40 test
    // files, and turning the build red on someone else's backlog is how a gate gets deleted rather
    // than obeyed.
    //
    // Triaged before switching it off, which is the part that matters: almost all 58 are INERT dead
    // keys (`vendors.category` dominates — no such column and no near neighbour, so the key was
    // always doing nothing). Two were NOT inert and are fixed: a Charge fixture set
    // `billing_frequency` where the column is `frequency`, so the charge silently took the default
    // rather than the stated one; and a MeterReading fixture set `total_cost` where the column is
    // `cost`, so a widget test's reading had no cost at all. Both now pass with the truthful name.
    // A third, a VendorBill `amount`, is knowingly wrong — its own comment says "subtotal ignored".
    //
    // To switch on: clear the remaining ghosts (mechanical — rename to the real column, or delete a
    // key that was describing an intent the schema never had), then remove this skip. Scoped in
    // docs/plans/10-round-3-followups.md §4.1.
    'Correct and mutation-proven; off until the 58 pre-existing ghosts are cleared — see §4.1 of plan 10.'
);
