<?php

namespace App\Models\Concerns;

use App\Models\CustomField;
use App\Support\CustomFields;
use App\Support\MorphMap;
use Illuminate\Support\Collection;

/**
 * A record that carries the operator's own fields (D-7 / EG-32).
 *
 * Values live in the model's existing nullable `metadata` JSON column — no value table, no join,
 * no N+1, and an export is a column read. {@see CustomFields} holds the register of which record
 * types may be extended and why.
 *
 * ## Only known keys are ever written
 *
 * `metadata` is `fillable` on every model that has it, and a JSON column accepts anything without
 * complaint. So {@see fillCustomFields()} writes ONLY keys the catalogue currently defines for this
 * model, and MERGES into whatever the column already holds. Two consequences worth stating:
 *
 * - a crafted Livewire payload cannot set arbitrary keys on the record through the form, and
 * - a value recorded under a field that has since been deactivated is left alone, rather than being
 *   silently dropped the next time somebody saves the record for an unrelated reason. That second
 *   one is the quiet data-loss bug this shape exists to avoid.
 *
 * ## Reading is separate from writing, deliberately
 *
 * {@see customFieldsForDisplay()} includes INACTIVE definitions; {@see customFieldsForForm()} does
 * not. Deactivating a field stops it being offered and must never blank what is already recorded —
 * a field retired half way through a year still explains what is on the records that carry it,
 * exactly as a retired charge code still labels the invoice lines it raised.
 */
trait HasCustomFields
{
    /** The morph alias this model is known by — the vocabulary the catalogue is keyed on. */
    public function customFieldAlias(): string
    {
        return MorphMap::alias(static::class);
    }

    /**
     * The fields to OFFER on a form: active definitions only.
     *
     * @return Collection<int, CustomField>
     */
    public function customFieldsForForm(): Collection
    {
        return CustomFields::for($this->customFieldAlias());
    }

    /**
     * The fields to SHOW on a record: every definition, plus anything recorded under a key the
     * catalogue no longer defines at all.
     *
     * That last part matters. A definition deleted rather than deactivated leaves its values
     * stranded in `metadata`, and a display that only iterates the catalogue would make them
     * invisible while they are still on the record — which reads as "this record has nothing"
     * rather than "something here can no longer be labelled".
     *
     * @return Collection<int, CustomField>
     */
    public function customFieldsForDisplay(): Collection
    {
        $defined = CustomFields::including($this->customFieldAlias());
        $values = $this->customFieldValues();
        $known = $defined->pluck('key')->all();

        $orphans = collect(array_keys($values))
            ->reject(fn (string $key): bool => in_array($key, $known, true))
            ->map(fn (string $key): CustomField => new CustomField([
                'model' => $this->customFieldAlias(),
                'key' => $key,
                // Its own key is the only name left. Better than a blank label over a real value.
                'label_en' => $key,
                'label_ar' => $key,
                'type' => 'text',
            ]));

        return $defined->concat($orphans)->values();
    }

    /**
     * Everything recorded on this record, as `key => value`.
     *
     * @return array<string, mixed>
     */
    public function customFieldValues(): array
    {
        $metadata = $this->metadata;

        return is_array($metadata) ? $metadata : [];
    }

    /** One recorded value, or null. */
    public function customFieldValue(string $key): mixed
    {
        return $this->customFieldValues()[$key] ?? null;
    }

    /**
     * The answers, as search terms for this record's own `search_text` blob.
     *
     * Spread into the model's `searchTextSources()`. This honours that method's one hard rule —
     * **never reach through a relation** — because `metadata` is the row's OWN attribute: the blob
     * re-folds whenever the record saves, and no other record's edit can strand it.
     *
     * **Stored VALUES only, never a choice's label.** A label lives on the definition, not on this
     * row, so indexing it would make a rename silently stale until the next rebuild — the exact
     * failure the no-relations rule exists to prevent. So a choice field is findable by what is
     * stored (`f_and_b`) and is filtered rather than searched, which is what the filter is for.
     *
     * Booleans are skipped: "1" is not a search term, and indexing it would match every record
     * carrying any number.
     *
     * Adding this to a model changes nothing already stored — run `php artisan atriom:rebuild-search`.
     *
     * @return array<int, string>
     */
    public function customFieldSearchValues(): array
    {
        $types = $this->customFieldsForDisplay()->pluck('type', 'key');
        $terms = [];

        foreach ($this->customFieldValues() as $key => $value) {
            if (is_bool($value) || $value === null || ($types[$key] ?? 'text') === 'boolean') {
                continue;
            }

            $terms[] = (string) $value;
        }

        return $terms;
    }

    /**
     * The answers, as a virtual `custom_fields` attribute Filament can bind a form to.
     *
     * A getter/setter pair rather than ten page classes: every Create and Edit page for all five
     * extended models would otherwise need the same two hooks, and the sixth model added later
     * would inherit neither. This routes through {@see fillCustomFields()}, so the key filtering
     * applies to a form, an importer and the API alike.
     *
     * The attribute is virtual on purpose — the setter never touches `$this->attributes`, so
     * Eloquent never tries to persist a `custom_fields` column, and it is not in `$appends`, so it
     * does not leak into `toArray()` and from there into an API payload.
     *
     * @return array<string, mixed>
     */
    public function getCustomFieldsAttribute(): array
    {
        return $this->customFieldValues();
    }

    /** @param  array<string, mixed>|null  $values */
    public function setCustomFieldsAttribute(?array $values): void
    {
        $this->fillCustomFields($values ?? []);
    }

    /**
     * Write the operator's answers, merging into what is already there.
     *
     * @param  array<string, mixed>  $values  keyed by field key; unknown keys are DISCARDED
     */
    public function fillCustomFields(array $values): static
    {
        $definitions = $this->customFieldsForForm()->keyBy('key');
        $metadata = $this->customFieldValues();

        foreach ($values as $key => $value) {
            $field = $definitions->get($key);

            if ($field === null) {
                continue;
            }

            // A cleared field is REMOVED, not stored as null. `metadata` would otherwise
            // accumulate a null for every field anybody ever left blank, and "recorded as empty"
            // and "never answered" would become indistinguishable on the record.
            if ($value === null || $value === '') {
                unset($metadata[$key]);

                continue;
            }

            $metadata[$key] = self::castCustomFieldValue($field, $value);
        }

        $this->metadata = $metadata === [] ? null : $metadata;

        return $this;
    }

    /**
     * Store the value in the shape its type promises.
     *
     * A form posts strings. Storing "12" for a number field means every later reader — a filter, an
     * export, a comparison — has to remember to cast, and the one that forgets compares a string.
     */
    private static function castCustomFieldValue(CustomField $field, mixed $value): mixed
    {
        return match ($field->type) {
            'number' => is_numeric($value) ? 0 + $value : null,
            'boolean' => (bool) $value,
            // A date is stored as a plain Y-m-d string, not a Carbon: it is going into JSON, and a
            // serialized Carbon carries a timezone and a time that a date field never meant.
            'date' => (string) $value,
            default => (string) $value,
        };
    }
}
