<?php

namespace App\Support\Filament;

use App\Support\ValueSets;
use Filament\Forms\Components\Select;
use Illuminate\Database\Eloquent\Model;
use ReflectionProperty;

/**
 * A record keeps the catalogue code it already carries, even after the operator retires it.
 *
 * ## The failure
 *
 * `IsCodeCatalogue::catalogueOptions()` offers only ACTIVE rows, which is right: retiring a code is
 * how an operator stops it being chosen again. But Filament derives a `Select`'s `Rule::in` from the
 * options it resolved, so the moment a code leaves that list **every record already carrying it
 * becomes unsavable** — and in the worst way, because nothing on the screen says so. The field
 * renders empty (Filament cannot label a value it was not offered), and then either the submit is
 * refused as *invalid* on a field the operator never touched, or — where the field is optional — the
 * save succeeds and silently BLANKS a classification that was correct.
 *
 * That is the other half of the 2026-08-18 deposit bug. The per-code floor fixed the case where a
 * SHIPPED code had no row; it cannot help a code the operator deliberately switched off, which is
 * the ordinary case a catalogue exists for. Measured on `tenant_requests.category`: retire
 * `electrical` and every open electrical request refuses to save.
 *
 * ## Why the container, and not twenty-two call sites
 *
 * `Field::make()` is `app($fieldClass, ['name' => $name])`, so every `Select` in both panels resolves
 * through the container — the same seam that put `AuthorizedAction` there, and for the same reason:
 * the twenty-third picker is covered before anyone remembers it. A `->getOptionLabelUsing()` per call
 * site would have been twenty-two chances to forget, in six files another session is holding.
 *
 * ## It is DERIVED, never a list
 *
 * `ValueSets::catalogueWidenedColumns()` already maps `table.column → catalogue model`, because the
 * saving listener needs exactly that. This asks the same registry, so a catalogue that grows a column
 * is covered by being registered rather than by anyone editing this class. Everything else — every
 * ordinary `Select` in the app — falls straight through untouched: one array lookup on a key that is
 * not there.
 *
 * **The label comes from `labelFor()`, which reads INACTIVE rows on purpose** (*"retiring a code must
 * not blank the label on documents that carry it"*). So a retired code reads as itself rather than as
 * a raw string, which is what makes the field honest rather than merely savable.
 */
class CatalogueAwareSelect extends Select
{
    /**
     * @return array<array-key, mixed>
     */
    /**
     * The column names any catalogue governs — the cheap bail-out.
     *
     * `getOptions()` runs about three times per Select per render plus once for validation, and this
     * binding is GLOBAL, so the first thing it must do is get out of the way of every ordinary
     * Select in the app. Comparing the field NAME against five distinct strings does that before
     * anything touches the container, the state or the record — each of which is real work
     * (`getState()` resolves a state cast out of the container on every call). Measured before this
     * bail-out: +9.4% on `EditTenantRequest` mount+refresh.
     *
     * @var array<string, true>|null
     */
    private static ?array $governedColumns = null;

    private static ?ReflectionProperty $containerProperty = null;

    /**
     * @return array<array-key, mixed>
     */
    public function getOptions(): array
    {
        $options = parent::getOptions();

        self::$governedColumns ??= array_fill_keys(array_map(
            fn (string $key): string => substr($key, strpos($key, '.') + 1),
            array_keys(ValueSets::catalogueWidenedColumns()),
        ), true);

        if (! isset(self::$governedColumns[$this->getName()])) {
            return $options;
        }

        // **A DETACHED COMPONENT MUST FALL THROUGH, NOT THROW.** `getRecord()` reaches for
        // `$container`, a TYPED property with no default, so a `Select` built outside a mounted
        // schema fatals on `must not be accessed before initialization` — the same trap
        // `getHelperText()` and `Repeater::getLabel()` set, and the reason two of this project's own
        // gates were once sweeping nothing. This binding is global, so any tool, test or conformance
        // gate that inspects a bare `Select::make()->options([...])` would start throwing on a call
        // that worked before. Reflection rather than a try/catch: an initialisation check must not
        // also swallow a genuine error inside the state resolution.
        self::$containerProperty ??= new ReflectionProperty(self::class, 'container');

        if (! self::$containerProperty->isInitialized($this)) {
            return $options;
        }

        $record = $this->getRecord();

        // Only a SAVED record can be carrying a retired code. On a create form the operator is
        // choosing now, and offering a switched-off code would be the opposite of what retiring one
        // means.
        if (! $record instanceof Model || ! $record->exists) {
            return $options;
        }

        // **THE STORED VALUE, NEVER `getState()`.** The first version read the component's state,
        // which is whatever the CLIENT last submitted — so any string a crafted payload sent was
        // appended as a valid option, `getOptionLabel()` resolved it (`labelFor()` returns the code
        // itself for an unknown one and can never fail), and Filament emitted NO `In` rule at all.
        // Measured: a `parking` subcategory — an ACCESS code the maintenance picker deliberately
        // does not offer — saved cleanly, where before it was refused. That turned a fix into a hole
        // bigger than the bug, on all sixteen catalogue columns at once, and it falsified this
        // codebase's own stated invariant that a Select refuses what it cannot label.
        //
        // `getRawOriginal()`, not `getAttribute()`: the persisted string, before any cast.
        $stored = $record->getRawOriginal($this->getName());

        if (! is_string($stored) || $stored === '' || array_key_exists($stored, $options)) {
            return $options;
        }

        $model = $this->catalogueFor($record);

        if ($model === null) {
            return $options;
        }

        // Appended, never prepended: the retired code is history, not a suggestion, and the active
        // codes stay in the order the catalogue's own `sort_order` put them.
        return $options + [$stored => $model::labelFor($stored)];
    }

    /**
     * The catalogue that governs this field's column, or null when nothing does.
     *
     * @return class-string|null
     */
    private function catalogueFor(Model $record): ?string
    {
        $key = $record->getTable().'.'.$this->getName();
        $entry = ValueSets::catalogueWidenedColumns()[$key] ?? null;

        if ($entry === null) {
            return null;
        }

        [$model] = $entry;

        return method_exists($model, 'labelFor') ? $model : null;
    }
}
