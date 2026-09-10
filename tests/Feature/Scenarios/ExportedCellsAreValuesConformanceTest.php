<?php

use App\Http\Middleware\SetLocale;
use App\Models\Bin;
use App\Models\Concerns\HasCustomFields;
use App\Models\CustomField;
use App\Models\Invoice;
use App\Models\TenantRequestSubcategory;
use App\Models\Unit;
use Filament\Actions\Exports\ExportColumn;
use Filament\Actions\Imports\ImportColumn;
use Illuminate\Support\Facades\App;
use Tests\Support\ExportCells;

/**
 * AN EXPORT COLUMN RENDERS A VALUE, NEVER A RECORD.
 *
 * `ExportColumn::make('floor')` named a BelongsTo relation, and the units CSV handed the operator
 * this in its Floor column:
 *
 *     {"id":2,"asset_id":2,"code":"G","name":"Ground","level":0,"created_at":"…","updated_at":"…"}
 *
 * **Why it renders instead of failing.** `HasCellState::getStateFromRecord()` enters its
 * relationship branch only when the name CONTAINS A DOT — `getRelationship()` returns null early
 * otherwise — so a bare relation name skips relation resolution entirely and falls through to
 * `data_get($record, 'floor')`, which is the Floor MODEL. Nothing downstream rejects it: the CSV
 * writer stringifies whatever it is handed, and `Model::__toString()` is `toJson()`. So the export
 * SUCCEEDS, the completion notification says so, and the damage is one column of every row.
 *
 * **Why nothing saw it.** `EveryRegisterCanBeExportedTest` asserts column NAMES — that `code` comes
 * first, that `tax_id` is present — which is the gate-checks-a-weaker-property shape this codebase
 * keeps finding: `floor` is a perfectly good name. Nothing resolved a cell.
 *
 * **The rule is about the TERMINAL segment.** Walk the dotted path; the last segment must be a
 * scalar attribute. Two ways it is not:
 *
 *  1. it is a RELATION method — the defect above, whether BelongsTo (one JSON object) or HasMany
 *     (a JSON array of them);
 *  2. it is cast to `array`/`json`/`object`/`collection`, which json-encodes for the same reason
 *     and reads the same way in the spreadsheet.
 *
 * **Two things are deliberately NOT defects.** A column with its own `state()` or
 * `formatStateUsing()` closure answers for itself (`CreditNoteExporter::on_the_books` returns a
 * translated yes/no), so the path is never consulted. And a path may index INTO an array-returning
 * accessor rather than hop through a relation — `custom_fields.<key>`, the D-7 operator fields —
 * where the terminal segment is a KEY and the value under it is the scalar the operator typed.
 *
 * Structural on purpose: it needs no rows, so it runs in the ordinary sqlite suite and covers an
 * exporter whose model has nothing seeded — where the live sweep can only report "unverified".
 * The live counterpart is `AnExportedCellIsAValueNotARecordTest`.
 */
it('never exports a relation or a json-cast column as a cell', function () {
    $exporters = ExportCells::exporters();
    $offenders = [];
    $resolved = 0;
    $perExporter = [];

    foreach ($exporters as $exporter) {
        $model = $exporter::getModel();

        foreach ($exporter::getColumns() as $column) {
            if (ExportCells::answersItself($column)) {
                continue;
            }

            $resolved++;
            $perExporter[class_basename($exporter)] = ($perExporter[class_basename($exporter)] ?? 0) + 1;

            if ($verdict = ExportCells::verdict(new $model, $column->getName())) {
                $offenders[] = class_basename($exporter).'::'.$column->getName().' — '.$verdict;
            }
        }
    }

    // A sweep that examined nothing passes for the wrong reason, and a single total is a weak way
    // to say so: a bare floor stays satisfied while one exporter quietly stops being swept. So the
    // premise is PER EXPORTER — every one must have contributed at least one resolved path — which
    // is the property that actually matters and needs no magic number to be maintained.
    expect($exporters)->not->toBeEmpty('found no exporters to sweep')
        ->and($resolved)->toBeGreaterThan(0)
        ->and($unresolved = array_diff(
            array_map('class_basename', $exporters),
            array_keys($perExporter),
        ))->toBe([], 'these exporters contributed no resolved path, so the sweep says nothing about '
            .'them: '.implode(', ', $unresolved));

    expect($offenders)->toBe([], "an export column does not render a value:\n- ".implode("\n- ", $offenders));
});

/**
 * The one hole in the carve-out above, closed by the model rather than by hope.
 *
 * `custom_fields.<key>` is exempted from the path walk because the terminal segment is a KEY into
 * an array-returning accessor, not an attribute — so the gate cannot see what shape the value under
 * it has. It is a scalar for every type today only because `castCustomFieldValue()` coerces one:
 * `number`, `boolean` and `date` have their own arms and everything else falls to `(string)`.
 *
 * Add a multi-value type — a `multiselect` — with an arm that stores an array, and every operator
 * field of that type would export as JSON in all five extensible registers at once, invisibly to
 * the sweep. So the types are DERIVED from `CustomField::TYPES` and each is put through the real
 * coercion: a new type that stores an array turns this red and forces a decision about how it is
 * exported, which is the same idiom the deletion and value-set registries use.
 */
it('stores every custom field type as a scalar, so its export column can be a bare key', function () {
    $coerce = new ReflectionMethod(HasCustomFields::class, 'castCustomFieldValue');
    $coerce->setAccessible(true);

    $arrayLike = [];

    foreach (CustomField::TYPES as $type) {
        // What a form actually posts: a string. An array-storing arm would still be caught, since
        // it is the arm that would split or wrap it (`explode(',', $value)`), and passing an array
        // in would only prove that `(string)` warns on one.
        foreach (['1', 'text', '2026-01-01', 'a,b'] as $posted) {
            $stored = $coerce->invoke(null, new CustomField(['type' => $type]), $posted);

            if (is_array($stored) || is_object($stored)) {
                $arrayLike[] = "{$type} stores ".gettype($stored);
            }
        }
    }

    expect(CustomField::TYPES)->not->toBeEmpty()
        ->and(array_unique($arrayLike))->toBe([], 'a custom field type stores a non-scalar, so its '
            ."export column renders JSON:\n- ".implode("\n- ", array_unique($arrayLike)));
});

/**
 * The walk's own teeth, both directions.
 *
 * A gate is only as good as what it can SEE, and an adversarial review of the first version found
 * three shapes it passed. Each is pinned here against a real model in this repo, paired with a
 * control that must NOT be flagged — because a walk that refused everything would satisfy the
 * findings alone and read as a pass.
 */
it('sees every shape that cannot render as a value', function () {
    $flagged = [
        // A relation declared with NO return type. Laravel's own `isRelation()` needs none, so
        // Filament resolves it; an earlier version required `Relations\` in the signature and
        // answered false here, i.e. the reported defect written on this model passed the gate.
        'untyped relation' => [new TenantRequestSubcategory, 'requests'],
        // A no-arg method that is NOT a relation. Eloquent throws "must return a relationship
        // instance", `ExportCsv` swallows it with report(), and the whole ROW leaves the file.
        'non-relation method' => [new Bin, 'onHandByItem'],
        // The reported defect itself.
        'bare BelongsTo' => [new Unit, 'floor'],
        // The carve-out for `custom_fields.<key>` used to admit any array-ish attribute and stop
        // the walk without inspecting what was under it.
        'other json column path' => [new Unit, 'metadata.anything'],
    ];

    $clean = [
        'real column' => [new Unit, 'code'],
        'cast column' => [new Unit, 'area_sqm'],
        'accessor over no column' => [new Invoice, 'unit_code'],
        'one relation hop' => [new Unit, 'asset.name'],
        'two relation hops' => [new Unit, 'activeLease.tenant.name'],
        'operator custom field' => [new Unit, 'custom_fields.shutter_type'],
    ];

    foreach ($flagged as $why => [$model, $path]) {
        expect(ExportCells::verdict($model, $path))
            ->not->toBeNull("the walk does not see a {$why} ({$path})");
    }

    foreach ($clean as $why => [$model, $path]) {
        expect(ExportCells::verdict($model, $path))
            ->toBeNull("the walk wrongly flags a {$why} ({$path})");
    }
});

/**
 * `formatStateUsing` is NOT a bypass, and exempting it exempted a column that still renders JSON.
 */
it('exempts a column only when it really answers its own state', function () {
    expect(ExportCells::answersItself(ExportColumn::make('floor')->state(fn () => 'G')))
        ->toBeTrue('state() is a real bypass and must exempt')
        ->and(ExportCells::answersItself(ExportColumn::make('floor')->formatStateUsing(fn ($state) => $state)))
        ->toBeFalse('formatStateUsing receives the resolved state, so the path must still be checked')
        ->and(ExportCells::answersItself(ExportColumn::make('floor')))
        ->toBeFalse('a plain column answers nothing itself');
});

/**
 * `unusable()` allow-lists scalars. The deny-list version exempted anything `Stringable`, which
 * PHP 8 auto-implements for every class with `__toString()` — so an Eloquent model AND an Eloquent
 * collection, the two shapes it exists to catch, both satisfied it.
 */
it('recognises both stringified-record shapes and every raw non-scalar', function () {
    expect(ExportCells::unusable('{"id":1,"code":"G"}'))->not->toBeNull('a BelongsTo blob')
        // A HasMany renders `[{"id":…}]`; a regex anchored on `{"` alone missed it.
        ->and(ExportCells::unusable('[{"id":1}]'))->not->toBeNull('a HasMany blob')
        ->and(ExportCells::unusable(new Unit))->not->toBeNull('a raw model is Stringable in PHP 8')
        ->and(ExportCells::unusable(Unit::query()->limit(0)->get()))->not->toBeNull('a raw collection')
        ->and(ExportCells::unusable(['a', 'b']))->not->toBeNull('a raw array')
        // Controls: the values a real cell actually holds.
        ->and(ExportCells::unusable('A-1'))->toBeNull()
        ->and(ExportCells::unusable('G'))->toBeNull()
        ->and(ExportCells::unusable(1234.5))->toBeNull()
        ->and(ExportCells::unusable(null))->toBeNull()
        ->and(ExportCells::unusable(now()))->toBeNull('a date is formatted by the writer');
});

/**
 * AN EXPORT IS NOT A ONE-WAY DOOR: whatever its importer REQUIRES must be in the file.
 *
 * The floor fix was justified partly on `floor.code` being *"what a re-import would join on"* — and
 * a review of it found the file did not re-import at all, at its one **required** column. The
 * export emitted `asset.name` (labelled *Asset*) while `UnitImporter::asset_code` is
 * `requiredMapping()` and resolves a CODE: measured, `resolveVisibleAsset('Atriom Walk')` is NULL
 * and only `'AW'` resolves. `ImportColumn::getSelect()` is `->required($this->isMappingRequired())`,
 * so the mapping modal cannot even be submitted with it blank, and an operator picking the only
 * plausible header fails every row. Six of seven columns auto-guessed; the seventh was the one that
 * had to.
 *
 * **Filament maps by LABEL, so the label is the contract.** `ImportColumn::getGuesses()` unshifts
 * `getLabel()`, and Filament writes an export's header row from the export columns' labels — so a
 * required import column whose label appears among the export headers maps itself, and one whose
 * label does not is a door the operator has to guess at.
 *
 * Derived from disk in BOTH directions — every resource that has an exporter and an importer — so a
 * tenth exporter or a new `requiredMapping()` column is covered by existing rather than by being
 * remembered. Checked in EVERY supported locale, because the labels are translated and a pair that
 * matches in English can drift in Arabic while every English-run test stays green.
 */
it('exports every column its own importer requires, in every locale', function () {
    $pairs = collect(ExportCells::exporters())
        ->mapWithKeys(function (string $exporter): array {
            $importer = str_replace(
                ['\\Exports\\', 'Exporter'],
                ['\\Imports\\', 'Importer'],
                $exporter,
            );

            return class_exists($importer) ? [$exporter => $importer] : [];
        });

    $missing = [];

    foreach (SetLocale::SUPPORTED as $locale) {
        App::setLocale($locale);

        foreach ($pairs as $exporter => $importer) {
            $headers = array_map(
                fn (ExportColumn $column): string => (string) $column->getLabel(),
                $exporter::getColumns(),
            );

            foreach ($importer::getColumns() as $column) {
                if (! $column->isMappingRequired()) {
                    continue;
                }

                if (! in_array((string) $column->getLabel(), $headers, true)) {
                    $missing[] = sprintf(
                        '[%s] %s requires "%s" (%s) and %s exports no column with that label',
                        $locale, class_basename($importer), $column->getLabel(), $column->getName(),
                        class_basename($exporter),
                    );
                }
            }
        }
    }

    App::setLocale(config('app.locale'));

    // The sweep is only as good as the pairs it found, and only a pair with a REQUIRED column says
    // anything at all — an importer requiring nothing satisfies this vacuously.
    expect($pairs)->not->toBeEmpty('found no exporter/importer pairs to compare')
        ->and($pairs->contains(fn (string $i): bool => collect($i::getColumns())
            ->contains(fn (ImportColumn $c): bool => $c->isMappingRequired())))
        ->toBeTrue('no paired importer requires any column, so this proves nothing')
        ->and($missing)->toBe([], "an export cannot be re-imported through its own importer:\n- "
            .implode("\n- ", $missing));
});
