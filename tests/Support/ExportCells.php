<?php

namespace Tests\Support;

use App\Support\Filament\CustomFieldsSchema;
use DateTimeInterface;
use Filament\Actions\Exports\ExportColumn;
use Filament\Actions\Exports\Exporter;
use Filament\Actions\Exports\Models\Export;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use ReflectionMethod;
use ReflectionProperty;
use RuntimeException;
use Throwable;

/**
 * Shared scaffolding for the two export gates — a CLASS, not file-scope helpers.
 *
 * A parallel worker only loads the test files it owns, so a helper declared at file scope in two
 * test files is a fatal redeclaration during collection: the suite exits 255 with no output on
 * either stream, and `--parallel` hides which files collided. Both export tests need this walk.
 */
class ExportCells
{
    /** Every exporter on disk, so the tenth is covered by existing rather than by being remembered. */
    public static function exporters(): array
    {
        return collect(glob(app_path('Filament/Exports/*.php')))
            ->map(fn (string $file): string => 'App\\Filament\\Exports\\'.basename($file, '.php'))
            ->filter(fn (string $class): bool => class_exists($class) && is_subclass_of($class, Exporter::class))
            ->values()
            ->all();
    }

    /**
     * True when the column answers its own state, so its attribute path is never resolved.
     *
     * **Only `state()`/`getStateUsing`.** `formatStateUsing` does NOT bypass path resolution — it
     * receives whatever `getStateFromRecord()` already produced — so exempting it would exempt a
     * column that still renders a relation: measured,
     * `ExportColumn::make('floor')->formatStateUsing(fn ($state) => $state)` was skipped by an
     * earlier version of this method and still rendered `{"id":1,"asset_id":3,…}`. That is a
     * realistic shape (money or number formatting layered onto a relation path), and it was both
     * over-broad AND unexercised — no column in the app uses `formatStateUsing` today. A column
     * that legitimately formats a related record should use `state()`, which really is a bypass.
     */
    public static function answersItself(ExportColumn $column): bool
    {
        $reflected = new ReflectionProperty(ExportColumn::class, 'getStateUsing');
        $reflected->setAccessible(true);

        return $reflected->getValue($column) !== null;
    }

    /**
     * How Laravel will resolve `$method` when `data_get()` reads it as a property — or null when it
     * will simply read an attribute.
     *
     * `Model::getAttribute()` sends anything that is not a readable attribute but IS a method down
     * `getRelationValue()`, and `getRelationshipFromMethod()` then **throws** unless the method
     * returns a `Relation`. So there are two failing shapes, not one:
     *
     *  - `'relation'` — a real relation, whose result stringifies to JSON (the reported defect);
     *  - `'not_a_relation'` — a no-arg method returning something else, e.g.
     *    `Bin::onHandByItem()` returning an eager Collection. Measured:
     *    `LogicException: … must return a relationship instance`, which `ExportCsv::handle()`
     *    catches with `report($exception)` — so the whole ROW is dropped from the file, silently.
     *    That is worse than a JSON blob and an earlier version of this walk passed it.
     *
     * A declared `Relations\` return type is the fast path. It is not sufficient: Laravel's own
     * `isRelation()` needs no return type, and this repo has such methods
     * (`TenantRequestSubcategory::requests()` is a real `hasMany` with none). Measured, an earlier
     * version answered FALSE for it while Laravel answered TRUE, so the reported defect written on
     * that model passed the gate. For an untyped method the question is settled by ASKING it —
     * guarded first on arity and on not being a readable attribute, so nothing with side effects
     * or required arguments is invoked.
     */
    public static function resolutionOf(Model $model, string $method): ?string
    {
        // A readable attribute wins, exactly as `HasCellState` breaks out of its walk on
        // `hasAttribute()` — this is what keeps `InvoiceExporter::unit_code` (a real accessor over
        // no column) from being read as a method call.
        if (self::isReadableAttribute($model, $method) || ! method_exists($model, $method)) {
            return null;
        }

        try {
            $reflected = new ReflectionMethod($model, $method);
        } catch (Throwable) {
            return null;
        }

        if (! $reflected->isPublic() || $reflected->isStatic() || $reflected->getNumberOfRequiredParameters() > 0) {
            return null;
        }

        $returnType = (string) $reflected->getReturnType();

        if ($returnType !== '') {
            return str_contains($returnType, 'Relations\\') ? 'relation' : 'not_a_relation';
        }

        try {
            return $model->{$method}() instanceof Relation ? 'relation' : 'not_a_relation';
        } catch (Throwable) {
            return 'not_a_relation';
        }
    }

    /** True when `$name` is a real relation — the branch the walk hops through. */
    public static function isRelation(Model $model, string $name): bool
    {
        return self::resolutionOf($model, $name) === 'relation';
    }

    /** A column, a cast, or an accessor — anything `data_get()` reads without calling a method. */
    private static function isReadableAttribute(Model $model, string $name): bool
    {
        return self::isColumn($model, $name)
            || array_key_exists($name, $model->getCasts())
            || method_exists($model, 'get'.Str::studly($name).'Attribute');
    }

    /** Is this a real column of the model's table? Memoised — the gate asks ~90 times. */
    private static function isColumn(Model $model, string $name): bool
    {
        static $columns = [];

        $table = $model->getTable();

        $columns[$table] ??= Schema::getColumnListing($table);

        return in_array($name, $columns[$table], true);
    }

    /**
     * Why the terminal segment of $name would not render as a value, or null when it would.
     */
    public static function verdict(Model $model, string $name): ?string
    {
        $segments = explode('.', $name);
        $cursor = $model;

        foreach ($segments as $index => $segment) {
            $isTerminal = $index === count($segments) - 1;
            $resolution = self::resolutionOf($cursor, $segment);

            if ($resolution === 'not_a_relation') {
                return 'is a method that is not a relation, so Eloquent throws "must return a '
                    .'relationship instance" reading it — which `ExportCsv` swallows with report(), '
                    .'dropping the whole ROW from the file; name an attribute or add a state() closure';
            }

            if ($resolution === 'relation') {
                if ($isTerminal) {
                    return 'names the relation itself, so the cell renders the whole '
                        .class_basename($cursor->{$segment}()->getRelated())
                        .' row as JSON — name an attribute on it';
                }

                $cursor = $cursor->{$segment}()->getRelated();

                continue;
            }

            if ($isTerminal) {
                $cast = $cursor->getCasts()[$segment] ?? null;

                if ($cast !== null && preg_match('/^(encrypted:)?(array|json|object|collection)$/', $cast)) {
                    // What each cast actually renders, MEASURED through a real `Exporter::__invoke`
                    // rather than assumed — an earlier version of this message said "renders as
                    // JSON" for all four and was right about one. A flat array becomes `a, b`
                    // (`CanFormatState` implodes it), a NESTED one becomes the literal `Array`
                    // plus a PHP conversion warning, an `object` cast raises a TypeError against
                    // `getFormattedState(): ?string` which `ExportCsv` swallows with `report()` —
                    // so the whole ROW is silently dropped from the file — and only `collection`
                    // renders JSON. Three of the four lose data; none is what the operator asked
                    // for. NOTE: no exporter reaches this branch today, so it is UNEXERCISED.
                    return "is cast to {$cast}, so the cell cannot render as a value — flat arrays "
                        .'implode, nested ones become the literal "Array", and an object cast drops '
                        .'the whole ROW from the file; export a single key or add a state() closure';
                }

                return null;
            }

            // Not a relation and not terminal, so the path is indexing INTO a value rather than
            // hopping through a relation. The only such shape here is `custom_fields.<key>`, the
            // D-7 operator fields, where the terminal segment is a KEY and the answer under it is
            // the scalar the operator typed — held scalar by `castCustomFieldValue()`, which the
            // second test in the conformance gate pins.
            //
            // Named EXPLICITLY rather than allowed for any array-ish attribute: the general form
            // stopped the walk without ever inspecting the terminal segment, so `metadata.anything`
            // would have passed whatever was under it. `metadata` is written only by
            // `fillCustomFields()` today, and a gate should not depend on that staying true.
            if ($segment === CustomFieldsSchema::KEY) {
                return null;
            }

            return "segment \"{$segment}\" is not a relation, and the only non-relation path this "
                .'export layer can resolve is '.CustomFieldsSchema::KEY.'.<key> — so this cannot resolve';
        }

        return null;
    }

    /**
     * One exported row as `column name => rendered value`.
     *
     * Through the exporter's own `__invoke()` — the seam `ExportCsv` calls per record — rather than
     * through a column's `getState()`, which skips the formatting the writer applies and would have
     * passed against a Model just as happily.
     *
     * @return array<string, mixed>
     */
    public static function row(string $exporter, Model $record): array
    {
        $names = array_map(fn (ExportColumn $column): string => $column->getName(), $exporter::getColumns());

        // `array_combine` would raise on a duplicate name; the map Filament builds dedupes, so a
        // clash here means the exporter itself declares one column twice.
        if (count($names) !== count(array_unique($names))) {
            throw new RuntimeException($exporter.' declares the same column name twice: '
                .implode(', ', array_diff_assoc($names, array_unique($names))));
        }

        $instance = new $exporter(new Export, array_combine($names, $names), []);

        return array_combine($names, $instance($record));
    }

    /**
     * The reason a rendered cell is unusable in a spreadsheet, or null when it is fine.
     *
     * An ALLOW-LIST of scalars, deliberately, because the deny-list version was wrong twice. It
     * exempted anything `Stringable`, and PHP 8 auto-implements that for every class declaring
     * `__toString()` — so an Eloquent MODEL and an Eloquent COLLECTION both satisfied it, i.e. the
     * two shapes this exists to catch. And it only passed at all because
     * `getFormattedState(): ?string` coerces them to strings first, which is a reason belonging to
     * Filament rather than to this method.
     *
     * A `DateTimeInterface` is the one object a writer legitimately formats.
     */
    public static function unusable(mixed $value): ?string
    {
        if ($value === null || is_scalar($value) || $value instanceof DateTimeInterface) {
            // A stringified record still reads as a value to `is_scalar`, so the shape is caught by
            // its CONTENT. Both forms: a BelongsTo renders one `{"id":…}` object, a HasMany renders
            // `[{"id":…}]` — an earlier regex anchored on `[{\["]` matched the first and missed the
            // second, which is the multi-row twin the gate is named for.
            if (is_string($value) && preg_match('/^\s*\[?\s*\{"/', $value)) {
                return 'a JSON blob: '.Str::limit($value, 60);
            }

            return null;
        }

        if (is_array($value)) {
            return 'an array: '.Str::limit(json_encode($value) ?: '', 60);
        }

        return 'a '.get_debug_type($value).' object';
    }
}
