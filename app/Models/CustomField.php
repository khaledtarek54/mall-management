<?php

namespace App\Models;

use App\Models\Concerns\HasCustomFields;
use App\Models\Concerns\RefusesDeletionWhenReferenced;
use App\Support\ActivityLogging;
use App\Support\Attributes\DeletableWhenUnused;
use App\Support\Attributes\PortfolioShared;
use App\Support\CustomFields;
use App\Support\MorphMap;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * حقل مخصص — one field the operator added to a record type (D-7 / EG-32).
 *
 * The largest structural gap this system had against Yardi UDFs, MRI user-defined fields and Odoo
 * Studio: there was nowhere to record a fact the vendor never modelled, so it went in the notes
 * field where nothing can filter, report or export it — or it cost a deploy.
 *
 * Values are stored in the record's own `metadata` JSON column, not in a value table. See
 * {@see CustomFields} for why the register of extensible models is explicit, and
 * {@see HasCustomFields} for why only known keys are ever written.
 *
 * ## The key is immutable; the label is not
 *
 * `key` is the address of every value already recorded, so renaming it would strand them — the data
 * would sit in `metadata` under the old key and nothing would read it again. `saving` refuses the
 * change rather than leaving that to a form. The LABEL is what an operator renames, in both
 * languages, and it reaches every record at once because a label is resolved at read time — the
 * same rule the activity log runs on: the row stores DATA, the words come later.
 *
 * ## Deactivating is not deleting
 *
 * `is_active` stops a field being OFFERED; it never removes a value already recorded, and the
 * display keeps showing it. A field retired half way through a year still explains what is on the
 * records that carry it, exactly as a retired charge code still labels the invoice lines it raised.
 */
#[DeletableWhenUnused(
    // Deliberately empty: what holds a definition is not a RELATION. Answers live inside each
    // record's own `metadata` JSON, so {@see CustomField::deletionBlockers()} overrides the count
    // rather than naming a relation the registry could verify.
    blockedBy: [],
    instead: 'Deactivate it. Deleting a definition orphans every value already recorded under its key — the data stays in each record\'s metadata and nothing can ever label or read it again, which is worse than a field nobody fills in.',
)]
// Shared: what an operator records about a tenant is their vocabulary, not one mall's. A per-property
// definition would mean re-adding "parent group" once per mall and losing every portfolio comparison.
#[PortfolioShared]
class CustomField extends Model
{
    use LogsActivity;
    use RefusesDeletionWhenReferenced;

    /** What a field can hold. Registered in `ValueSets`, which refuses anything else on save. */
    public const TYPES = ['text', 'textarea', 'number', 'date', 'select', 'boolean'];

    protected $fillable = [
        'model',
        'key',
        'label_en',
        'label_ar',
        'type',
        'options',
        'is_required',
        'is_active',
        'sort_order',
    ];

    protected $casts = [
        'options' => 'array',
        'is_required' => 'boolean',
        'is_active' => 'boolean',
        'sort_order' => 'integer',
    ];

    protected $attributes = [
        'type' => 'text',
        'is_required' => false,
        'is_active' => true,
        'sort_order' => 0,
    ];

    protected static function booted(): void
    {
        static::saving(function (self $field) {
            // The model must be one the operator may actually extend. Not a UI concern: the form
            // offers only the register, and this is what a crafted payload or an import meets.
            if (! CustomFields::isExtensible((string) $field->model)) {
                throw new \DomainException(__('admin.refusals.cf_model_not_extensible', ['model' => $field->model]));
            }

            // Filament reads a dot as NESTING, so `parent.group` would silently become a two-level
            // array in the form state and the answer would never reach `metadata` under that key.
            // The definition form carries the same rule; this is the gate, per the codebase's own
            // "guard in the model, the form is the UI half" doctrine — an import or a crafted
            // payload meets this one.
            if (! preg_match('/^[a-z][a-z0-9_]*$/', (string) $field->key)) {
                throw new \DomainException(__('admin.refusals.cf_bad_key', ['key' => $field->key]));
            }

            // The ADDRESS of every answer already recorded is the PAIR: `model` says which table's
            // `metadata` holds them, `key` says under which JSON key. Both are refused, because
            // moving either strands the answers on records nothing will read again.
            //
            // Measured 2026-09-04 at HEAD (83624504): only `key` was refused here, while this
            // class's own docblock, `CustomFieldForm`'s and docs/modules/38 §4 all say both are —
            // §4 in so many words, "(`CustomField::saving()` refuses the change; the form disables
            // both)". The form does disable both, and both are `->dehydrated()`, so the value still
            // arrives in the Livewire payload and a disabled input is a statement of intent rather
            // than a gate. Re-pointing a definition from `tenant` to `lease` left every tenant
            // answer in place under a key nothing offers or reads AND emptied `deletionBlockers()`,
            // which counts records of the model the row NOW names — so the definition became freely
            // deletable and the orphaning permanent. That is the one act `#[DeletableWhenUnused]`
            // is on this model to prevent.
            if ($field->exists && $field->isDirty('key')) {
                throw new \DomainException(__('admin.refusals.cf_key_immutable'));
            }

            if ($field->exists && $field->isDirty('model')) {
                throw new \DomainException(__('admin.refusals.cf_model_immutable'));
            }

            // **Unique PER MODEL, said in words rather than as a 1062.** `custom_fields` has carried
            // `unique(['model', 'key'])` since the table was created — the key is the ADDRESS of
            // every value recorded under it — and nothing above the database asked. Measured at
            // HEAD 2026-09-04: `CustomFieldForm` carried `required`, `maxLength(64)` and the key
            // regex and no uniqueness rule, and this hook checked the SHAPE of the key and not
            // whether it was taken, so adding a second `parent_group` to Tenants came back as a raw
            // QueryException, i.e. the 500 page, on an ordinary create.
            //
            // Dirty-only, so a rename, a reorder or a deactivation costs no query. It covers a MOVE
            // as well as a create, because `model` is disabled on the form and still dehydrated, so
            // a crafted payload can carry a different one. The index stays the backstop for the
            // race neither guard can close.
            if ($field->isDirty(['model', 'key'])) {
                $conflict = static::keyConflictRefusal(
                    (string) $field->model,
                    (string) $field->key,
                    $field->exists ? $field->getKey() : null,
                );

                if ($conflict !== null) {
                    throw new \DomainException(__($conflict['key'], $conflict['replace']));
                }
            }

            // A select with no choices is a dropdown that can never be answered, and `is_required`
            // would then make the whole record unsaveable. Refused here rather than in the form,
            // for the same reason as above.
            if ($field->type === 'select' && $field->options === []) {
                throw new \DomainException(__('admin.refusals.cf_choice_needs_option'));
            }
        });

        // The resolver memoises per request and a write here fires no event on it — so without this
        // a field the operator just added would stay invisible for the rest of the request, and for
        // the rest of the day on a `queue:work` daemon.
        static::saved(fn () => CustomFields::flush());
        static::deleted(fn () => CustomFields::flush());
    }

    /**
     * The refusal for a (model, key) pair this record type already carries — null when it is free.
     *
     * ONE decision about the wording, read by this model's own guard and by the definition form's
     * field rule, so an inline error and a toast can never say different things. Returns the KEY
     * and its replacements rather than the finished sentence, so both call sites raise it through
     * `__()` — which is also what `RefusalsAreTranslatedConformanceTest` reads.
     *
     * The existing field's own state chooses the sentence, because the ESCAPE is the opposite one.
     * While it is live the answer is "give this one a different key". Once it has been switched off
     * the answer is "turn that one back on": every answer already recorded sits under that key, and
     * a second definition could never read them.
     *
     * @return array{key: string, replace: array<string, string>}|null
     */
    public static function keyConflictRefusal(string $model, string $key, mixed $ignoreId = null): ?array
    {
        if ($model === '' || $key === '') {
            return null;
        }

        $existing = static::query()
            ->where('model', $model)
            ->where('key', $key)
            ->when($ignoreId !== null, fn (Builder $q) => $q->whereKeyNot($ignoreId))
            ->first();

        if ($existing === null) {
            return null;
        }

        return [
            'key' => $existing->is_active ? 'admin.refusals.cf_key_taken' : 'admin.refusals.cf_key_taken_inactive',
            'replace' => ['key' => $key, 'label' => $existing->label()],
        ];
    }

    /**
     * How many records have actually ANSWERED this field — the thing that makes it undeletable.
     *
     * Overridden rather than declared as a `blockedBy` relation, because there is no relation to
     * declare: an answer is a key inside the host record's own `metadata` JSON. The registry's gate
     * verifies that every relation named exists, and naming one that does not would be the exact
     * silent failure that check was written for.
     *
     * A field nobody has filled in is a mistake worth clearing. Once answered it is deactivated,
     * never removed — deleting it leaves the answers stranded in `metadata` with nothing able to
     * label or read them, which is worse than a field nobody fills in.
     *
     * Soft-deleted records count. A retired tenant's answers are still what an auditor asks about,
     * and the trait applies the same rule to every other blocking relation in the system.
     *
     * @return array<int, string>
     */
    public function deletionBlockers(): array
    {
        $class = $this->modelClass();

        if ($class === null || $this->key === null || $this->key === '') {
            return [];
        }

        $query = $class::query();

        if (in_array(SoftDeletes::class, class_uses_recursive($class), true)) {
            $query = $query->withTrashed();
        }

        $count = $query->whereJsonContainsKey('metadata->'.$this->key)->count();

        return $count > 0
            ? [$count.' '.str(class_basename($class))->snake(' ')->plural($count)->toString()]
            : [];
    }

    /** The label in the reader's language, falling back to the other rather than to the key. */
    public function label(): string
    {
        $preferred = app()->getLocale() === 'ar' ? $this->label_ar : $this->label_en;

        // A blank where a field name belongs is worse than the wrong language — the same rule
        // `DocumentText` applies to a tenant-facing block.
        return $preferred !== null && $preferred !== ''
            ? $preferred
            : (string) ($this->label_en ?: $this->label_ar ?: $this->key);
    }

    /**
     * The choices, as `value => label`, in the reader's language.
     *
     * Each option carries BOTH labels on the row. A dropdown an Arabic-speaking operator reads must
     * not fall back to English for half its entries.
     *
     * @return array<string, string>
     */
    public function choices(): array
    {
        $isArabic = app()->getLocale() === 'ar';
        $choices = [];

        foreach ($this->options ?? [] as $option) {
            if (! is_array($option) || ! isset($option['value'])) {
                continue;
            }

            $value = (string) $option['value'];
            $preferred = $isArabic ? ($option['label_ar'] ?? null) : ($option['label_en'] ?? null);

            $choices[$value] = (string) ($preferred ?: $option['label_en'] ?? $option['label_ar'] ?? $value);
        }

        return $choices;
    }

    /** The model class this field is defined on, resolved through the morph map. */
    public function modelClass(): ?string
    {
        return MorphMap::model((string) $this->model);
    }

    /** @param  Builder<self>  $query */
    public function scopeForModel(Builder $query, string $morphAlias): Builder
    {
        return $query->where('model', $morphAlias);
    }

    public function getActivitylogOptions(): LogOptions
    {
        return ActivityLogging::for($this, 'custom_field');
    }
}
