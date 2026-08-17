<?php

use App\Models\FacilityWorkOrder;
use App\Models\Invoice;
use App\Support\MorphMap;
use Illuminate\Database\ClassMorphViolationException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * The gate on App\Support\MorphMap.
 *
 * It asserts COMPLETENESS, not just validity — every model has an alias, rather than merely every
 * alias pointing at a real model. That distinction is the whole point: with the map enforced, a
 * model missing from it throws `ClassMorphViolationException` on its first polymorphic write, so a
 * gate that only checked the aliases it already has would pass while the next new model waits to
 * fail in production. The same weaker-than-its-name failure has bitten this project repeatedly —
 * an isolation gate that verified relations exist while the chain no longer reached an asset, a
 * translation list "derived from useLogName()" that tracked a log name nothing emitted.
 */
/** Every concrete Eloquent model under app/Models. */
function allEloquentModels(): array
{
    $models = [];

    foreach (glob(base_path('app/Models/*.php')) as $file) {
        $class = 'App\\Models\\'.basename($file, '.php');

        if (! class_exists($class)) {
            continue;
        }

        $reflection = new ReflectionClass($class);

        if ($reflection->isAbstract() || ! $reflection->isSubclassOf(Model::class)) {
            continue;
        }

        $models[] = $class;
    }

    return $models;
}

it('gives every model an alias — a missing one throws on its first morph write, not at boot', function () {
    $unmapped = array_values(array_diff(allEloquentModels(), array_values(MorphMap::MAP)));

    expect($unmapped)->toBe([], 'Add these to App\Support\MorphMap::MAP');
});

it('found models to check, so the sweep cannot pass by matching nothing', function () {
    expect(count(allEloquentModels()))->toBeGreaterThan(50);
});

/**
 * The sweep above walks `app/Models`, which is precisely the assumption that let two unmapped
 * models through: spatie's Role and Permission are morph subjects (AccessControlAudit does
 * `performedOn($role)`) and live in vendor. The gate and the map were generated from the same
 * directory, so the check agreed with the mistake instead of catching it — validity passing while
 * completeness failed. These are named explicitly because a sweep cannot discover them.
 */
it('maps the morph targets that are NOT ours, which no app/Models sweep can find', function () {
    foreach ([Role::class, Permission::class] as $vendorModel) {
        expect(in_array($vendorModel, MorphMap::MAP, true))->toBeTrue(
            "{$vendorModel} is written to a morph column but has no alias"
        );
    }
});

it('proves the enforced map really does throw for something unmapped', function () {
    // Without this, every assertion above could pass while `requireMorphMap` was quietly off and
    // class names kept leaking into the columns.
    $orphan = new class extends Model
    {
        protected $table = 'invoices';
    };

    expect(fn () => $orphan->getMorphClass())
        ->toThrow(ClassMorphViolationException::class);
});

it('points every alias at a model that still exists', function () {
    $dangling = [];

    foreach (MorphMap::MAP as $alias => $class) {
        if (! class_exists($class) || ! is_subclass_of($class, Model::class)) {
            $dangling[] = "{$alias} => {$class}";
        }
    }

    expect($dangling)->toBe([]);
});

it('maps each model exactly once — two aliases for one model is two truths in the data', function () {
    $duplicated = array_keys(array_filter(array_count_values(MorphMap::MAP), fn ($n) => $n > 1));

    expect($duplicated)->toBe([]);
});

/**
 * The rule is "the model's canonical short name", not "snake_case of the class". Where a model
 * declares a log name, that IS its canonical short name and the alias must match it — otherwise the
 * same model answers to two different words, one in the audit trail and one in the morph columns.
 * Three models genuinely differ from the mechanical form (`cam_pool`, `work_order_part`,
 * `tenant_sales`) and the declared name is correct in all three.
 */
it('names each model once — the declared log name where there is one, snake_case otherwise', function () {
    $offenders = [];

    foreach (MorphMap::MAP as $alias => $class) {
        $source = file_get_contents((new ReflectionClass($class))->getFileName());

        $expected = preg_match("/useLogName\('([a-z_]+)'\)/", $source, $m)
            ? $m[1]
            : Str::snake(class_basename($class));

        if ($alias !== $expected) {
            $offenders[] = "{$class} is '{$alias}', expected '{$expected}'";
        }
    }

    expect($offenders)->toBe([]);
});

/**
 * A registry nobody installs protects nothing — the same shape as a settings screen that reads a
 * value it never writes. This pins the installation, not just the list.
 */
it('is actually INSTALLED and enforced, not merely declared', function () {
    expect(Relation::requiresMorphMap())->toBeTrue()
        ->and(Relation::morphMap())->not->toBeEmpty();

    foreach (MorphMap::MAP as $alias => $class) {
        expect(Relation::getMorphedModel($alias))->toBe($class);
    }
});

it('makes a model report its alias, not its class name, as its morph type', function () {
    expect((new Invoice)->getMorphClass())->toBe('invoice')
        ->and((new FacilityWorkOrder)->getMorphClass())->toBe('facility_work_order');
});

/**
 * The audit trail resolves a row's subject label through `admin.activity.subjects.{log_name}`, so an
 * alias that disagrees with the log name would give one model two names for the same concept —
 * which is the confusion the 2026-08-15 rename existed to remove.
 */
it('agrees with the activity log name each model declares', function () {
    $mismatched = [];

    foreach (MorphMap::MAP as $alias => $class) {
        $source = file_get_contents((new ReflectionClass($class))->getFileName());

        if (preg_match("/useLogName\('([a-z_]+)'\)/", $source, $m) && $m[1] !== $alias) {
            $mismatched[] = "{$class}: alias '{$alias}' vs log name '{$m[1]}'";
        }
    }

    expect($mismatched)->toBe([]);
});

/**
 * The columns the backfill migration had to rewrite. Listed here as the EXPECTED set rather than
 * discovered, so that a new polymorphic column added later shows up as a failure demanding a
 * backfill — discovery in the migration finds them, this notices when the set changes.
 */
it('knows every polymorphic column, including the ones no application code owns', function () {
    $found = [];

    foreach (Schema::getTableListing() as $table) {
        $table = str_contains($table, '.') ? substr($table, strrpos($table, '.') + 1) : $table;

        if (isset($seen[$table]) || ! Schema::hasTable($table)) {
            continue;
        }

        $seen[$table] = true;
        $columns = Schema::getColumnListing($table);

        foreach ($columns as $column) {
            if (str_ends_with($column, '_type') && in_array(substr($column, 0, -5).'_id', $columns, true)) {
                $found[] = "{$table}.{$column}";
            }
        }
    }

    sort($found);

    // Three of these belong to packages, not to us, and all three are load-bearing: miss
    // model_has_roles and every user silently holds no roles; miss personal_access_tokens and API
    // tokens stop authenticating; miss notifications and every bell inbox empties.
    expect($found)->toBe([
        'activity_log.causer_type',
        'activity_log.subject_type',
        'journal_entries.source_type',
        'media.model_type',
        'model_has_permissions.model_type',
        'model_has_roles.model_type',
        'notes.noteable_type',
        'notifications.notifiable_type',
        'personal_access_tokens.tokenable_type',
        'posting_month_overrides.source_type',
        'stock_movements.source_type',
        'tenant_request_comments.author_type',
        'tenant_sales_declarations.declared_by_type',
    ]);
});
