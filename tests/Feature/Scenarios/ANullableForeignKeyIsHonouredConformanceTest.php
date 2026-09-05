<?php

/**
 * §9.3 gate 4 — A NULLABLE FOREIGN KEY MUST BE HONOURED BY EVERYTHING THAT READS IT.
 *
 * The `invoices.lease_id` class. That column is nullable precisely so a unit OWNER with no tenancy
 * can be billed — and every read that treats it as set is a 500 waiting for the first owner
 * assessment. The same shape recurs wherever the schema says "this may be absent" and a caller
 * says "it is there": `equipment.unit_id`, `purchase_requests.warehouse_id`,
 * `facility_work_orders.parent_work_order_id`, `credit_notes.invoice_id`.
 *
 * The sweep DERIVES the population — every nullable `*_id` column whose model has a relation
 * method of that name (118 today) — and looks for HARD dereferences (`->rel->`, never `?->`) that
 * no guard precedes. What counts as a guard is the honest list of how this codebase actually
 * refuses a null FK: `whenLoaded()` (Laravel returns before the closure when the relation is
 * null), `relationLoaded()`, a ternary or `if` on the relation, and — the form the two real
 * services use — a check on the COLUMN (`if ($x->warehouse_id === null) throw`).
 *
 * **Three exclusions, each a RULE rather than a suppression, because a gate with a list of
 * dismissed findings is one nobody trusts:**
 *
 *  1. AMBIGUOUS RECEIVER — several models own a relation of this name and nothing here types the
 *     variable. Counted across ALL models, not just the nullable ones: `$request->user()->tenant`
 *     is a `TenantUser` (whose `tenant_id` is NOT NULL) in a file that also names `TenantRequest`
 *     (whose is), and a nullable-only count reports the wrong column.
 *  2. AUTHENTICATED PRINCIPAL — `$request->user()`, `Auth::user()`. Whose FKs are the guard's
 *     business and which no file-context hint can type.
 *  3. Whitespace is NORMALISED before matching, or a ternary split across two lines
 *     (`$this->lease` ⏎ `? [...]`) reads as unguarded — three false findings came from exactly
 *     that, and a gate that fires on formatting gets weakened rather than fixed.
 *
 * **Measured on the day it was written: ZERO unguarded dereferences** — 118 nullable FKs, 27 hard
 * dereferences, every one of them guarded. This class of defect is already closed here; the gate
 * is what stops the 119th nullable column reopening it.
 *
 * **What it costs, stated rather than implied.** Exclusion 1 is a real blind spot, not just a
 * filter: a file naming two models that both own a relation of the same name is skipped entirely,
 * so deleting `PurchaseRequestService`'s own `warehouse_id === null` guard does NOT turn this
 * red (mutation-checked). Untyped PHP leaves the choice between that blind spot and reporting the
 * wrong column on every `->tenant->` in the app, and a gate whose findings get dismissed is worse
 * than a narrower one that is always right. The unambiguous case — the `invoices.lease_id` shape
 * the gate is named for — IS caught: planting `$invoice->lease->reference` in a service turns it
 * red.
 */

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/** Every model class keyed by table, memoised. */
function nullableFkModels(): array
{
    static $models;
    if ($models === null) {
        $models = [];
        foreach (glob(base_path('app/Models/*.php')) as $f) {
            $class = 'App\\Models\\'.basename($f, '.php');
            if (class_exists($class) && is_subclass_of($class, Model::class)) {
                try {
                    $models[(new $class)->getTable()] = $class;
                } catch (Throwable) {
                    // an abstract or trait-only model contributes nothing
                }
            }
        }
    }

    return $models;
}

/** [table, column, relation, class] for every nullable FK with a relation method. */
function nullableFkColumns(): array
{
    static $rows;
    if ($rows === null) {
        $rows = [];
        foreach (nullableFkModels() as $table => $class) {
            if (! Schema::hasTable($table)) {
                continue;
            }
            foreach (Schema::getColumns($table) as $col) {
                if (! str_ends_with($col['name'], '_id') || $col['name'] === 'id' || ! ($col['nullable'] ?? false)) {
                    continue;
                }
                $relation = Str::camel(substr($col['name'], 0, -3));
                if (method_exists($class, $relation)) {
                    $rows[] = [$table, $col['name'], $relation, $class];
                }
            }
        }
    }

    return $rows;
}

/** Comment-stripped app/ sources, relative path => source. */
function nullableFkSources(): array
{
    static $files;
    if ($files === null) {
        $files = [];
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(base_path('app')));
        foreach ($it as $f) {
            if (! $f->isFile() || $f->getExtension() !== 'php') {
                continue;
            }
            $src = file_get_contents($f->getPathname());
            $out = $src;
            foreach (token_get_all($src) as $token) {
                if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                    $at = strpos($out, $token[1]);
                    if ($at !== false) {
                        $out = substr_replace($out, str_repeat(' ', strlen($token[1])), $at, strlen($token[1]));
                    }
                }
            }
            $files[ltrim(str_replace(base_path().'/', '', $f->getPathname()), '/')] = $out;
        }
    }

    return $files;
}

function nullableFkUnguarded(): array
{
    $findings = [];

    foreach (nullableFkSources() as $rel => $src) {
        $flat = preg_replace('/\s+/', ' ', $src);

        foreach (nullableFkColumns() as [$table, $column, $relation, $class]) {
            $hint = class_basename($class);
            if (! str_contains($src, $hint) && ! str_contains($rel, $hint)) {
                continue;
            }

            // Exclusion 1 — ambiguous receiver.
            $owners = 0;
            foreach (nullableFkModels() as $otherClass) {
                if (! method_exists($otherClass, $relation)) {
                    continue;
                }
                $otherHint = class_basename($otherClass);
                if (str_contains($src, $otherHint) || str_contains($rel, $otherHint)) {
                    $owners++;
                }
            }
            if ($owners > 1) {
                continue;
            }

            if (! preg_match_all('/(?<!\?)->'.preg_quote($relation, '/').'->/', $flat, $hits, PREG_OFFSET_CAPTURE)) {
                continue;
            }

            foreach ($hits[0] as [$text, $at]) {
                $before = substr($flat, max(0, $at - 900), min(900, $at));

                // Exclusion 2 — the receiver is the authenticated principal.
                if (str_contains(substr($before, -40), 'user()')) {
                    continue;
                }

                $guards = [
                    "whenLoaded('$relation'", "relationLoaded('$relation')",
                    "->$relation ?", "->$relation)", "->$relation !== null", "->$relation === null",
                    "\$$relation", "?->$relation", "->$relation,",
                    // COLUMN-level guards: the honest form the services use. Deliberately NOT a
                    // bare mention of the column name — `'warehouse_id'` appears in a fillable
                    // array or a validation rule and would vouch for a guard that is not there
                    // (mutation-proved: with the real null test deleted, the loose token kept the
                    // gate green).
                    "$column === null", "$column !== null", "$column !== ''", "! \$locked->$column",
                    "$column) {", "$column === 0",
                ];

                $guarded = false;
                foreach ($guards as $guard) {
                    if (str_contains($before, $guard)) {
                        $guarded = true;
                        break;
                    }
                }

                if (! $guarded) {
                    $findings[] = "$rel — `->$relation->` with no guard ($table.$column is nullable)";
                }
            }
        }
    }

    return array_values(array_unique($findings));
}

it('finds the nullable foreign keys it was written against — the sweep collects something', function () {
    // A gate that silently stops collecting reports on a set it no longer sees (three recorded
    // instances in this codebase). 118 on the day this was written.
    expect(count(nullableFkColumns()))->toBeGreaterThanOrEqual(100);
});

it('proves it can SEE a hard dereference at all, guarded or not', function () {
    // Without this, the sweep passing means nothing: a regex that matched zero call sites would
    // report "no unguarded dereferences" just as happily. Count the hard dereferences BEFORE the
    // guard test — 27 on the day this was written, all of them correctly guarded.
    $seen = 0;
    foreach (nullableFkSources() as $rel => $src) {
        $flat = preg_replace('/\s+/', ' ', $src);
        foreach (nullableFkColumns() as [$table, $column, $relation, $class]) {
            $hint = class_basename($class);
            if ((str_contains($src, $hint) || str_contains($rel, $hint))
                && preg_match('/(?<!\?)->'.preg_quote($relation, '/').'->/', $flat)) {
                $seen++;
            }
        }
    }

    expect($seen)->toBeGreaterThanOrEqual(15);
});

it('never dereferences a nullable relation without a guard', function () {
    expect(nullableFkUnguarded())->toBe([]);
});
