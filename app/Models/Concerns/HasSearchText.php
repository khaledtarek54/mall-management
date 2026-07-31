<?php

namespace App\Models\Concerns;

use App\Support\Search\SearchText;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Maintains a model's denormalized `search_text` column — the folded blob of its
 * own searchable values (see `App\Support\Search\SearchText` for the fold).
 *
 * A model using this trait declares `searchTextSources()` and gets, for free:
 *   - the blob rebuilt on every save,
 *   - a `search($query)` scope that folds the operator's query the same way,
 *   - inclusion in `atriom:rebuild-search`.
 *
 * ## The one invariant: the blob is a pure function of the row's OWN attributes
 *
 * `searchTextSources()` must never reach through a relation. It is tempting —
 * putting the tenant's name into the invoice's blob would let one indexed column
 * answer "invoice for Zara" with no join at all. It is also how this class would
 * become a second `accounting:sync-ledger`: renaming a tenant would silently
 * strand every invoice blob that quoted the old name, and the drift would be
 * invisible until an operator swore an invoice had vanished.
 *
 * Keeping the blob local means a save is the ONLY thing that can invalidate it,
 * so the trait's own `saving` hook is a complete guarantee. Cross-model search
 * ("find the invoice by tenant name") stays a relation search against the
 * RELATED model's own `search_text` — see `SearchesNormalizedText`, which points
 * relation paths at `tenant.search_text` rather than `tenant.name`. That still
 * normalizes correctly, because the related row maintains its own blob.
 *
 * ## The known hole, and why it is acceptable
 *
 * A mass `Model::query()->update([...])` fires no model events, so it leaves the
 * blob stale. That is why `atriom:rebuild-search` exists and why
 * `SearchPolicyConformanceTest` asserts every registered model can rebuild from
 * scratch. Services in this codebase save models rather than mass-update them
 * (the `Invoice::recomputeTotals()` invariant depends on that too), so the hole
 * is theoretical today — but a future bulk backfill must run the rebuild after.
 *
 * @mixin Model
 */
trait HasSearchText
{
    /**
     * The row's own values that an operator might type to find it.
     *
     * Return raw values, not folded ones — the trait folds them. Include derived
     * tokens where the raw column is not what gets typed: a phone stored as
     * "+20 100 123 4567" should also contribute its digits-only form so that
     * typing 01001234567 finds it.
     *
     * @return array<int, string|int|float|null>
     */
    abstract public function searchTextSources(): array;

    /**
     * The digits of a phone number, as an extra token alongside the raw value.
     *
     * Phone numbers are stored the way they were typed — "+20 100 123 4567",
     * "0100 123 4567", "(02) 2735 1234" — and searched the way they are read off
     * a screen, usually as one unbroken run of digits. The fold strips the
     * punctuation but PRESERVES spaces (deliberately, so that multi-word names
     * still match word by word), which leaves "20 100 123 4567" unable to match a
     * typed "01001234567". Contributing the digits-only form as its own token
     * makes both spellings work without weakening name matching.
     *
     * Returns null for a blank input so `SearchText::blob()` drops it.
     */
    protected static function digitsOf(?string $value): ?string
    {
        if ($value === null || trim($value) === '') {
            return null;
        }

        $digits = preg_replace('/\D+/', '', $value);

        return ($digits === null || $digits === '') ? null : $digits;
    }

    public static function bootHasSearchText(): void
    {
        static::saving(function (self $model): void {
            $model->refreshSearchText();
        });

        // A second fold AFTER the insert lands, because `saving` is genuinely too
        // early for two of the most-searched values in the system:
        //
        //   - Document numbers. `AllocatesDocumentNumber` assigns `number` in
        //     `creating`, and Eloquent fires saving → creating → INSERT. A
        //     `saving`-only fold therefore stores a blob with NO invoice number
        //     in it — on the one model whose number is the thing operators type
        //     most. Every invoice, credit note, deposit transaction, vendor bill,
        //     expense, journal entry and payroll run has this shape.
        //   - Id-derived references. `Violation::$reference` is the accessor
        //     'VIO-'.str_pad($this->id), and there is no id before the INSERT, so
        //     the first fold would bake in VIO-00000 for every violation ever
        //     created.
        //
        // The write is conditional, so this costs one extra comparison — not one
        // extra UPDATE — on the majority of models, whose sources are all plain
        // columns already present at `saving` time.
        static::created(function (self $model): void {
            $before = $model->getAttribute('search_text');
            $model->refreshSearchText();

            if ($model->getAttribute('search_text') === $before) {
                return;
            }

            // Quietly and without timestamps: this is the same row settling into
            // its final derived value, not a second edit by anyone.
            $timestamps = $model->timestamps;
            $model->timestamps = false;
            $model->saveQuietly();
            $model->timestamps = $timestamps;
        });
    }

    /**
     * Keep the blob out of every serialized payload.
     *
     * Laravel calls `initialize{Trait}` from the model constructor, so this
     * applies to all 35 models without a line of per-model work — which matters,
     * because the blob is a concatenation of a record's identifying fields and
     * would otherwise ride along in `/api/v1` responses, in `toArray()` output,
     * and in every Livewire payload. For `Tenant` that means name, legal name,
     * contact person, email, phone AND the digits-only phone, duplicated into one
     * string, on a model returned by the tenant-facing API.
     *
     * It is also NOT in any model's `$fillable`, so a crafted form request cannot
     * write it — the `saving` hook overwrites whatever is there anyway, but the
     * gate asserts the absence so nobody adds it "for convenience" later.
     */
    public function initializeHasSearchText(): void
    {
        $this->hidden[] = 'search_text';
    }

    /**
     * Recompute the blob from the model's current attribute values.
     *
     * Assigns rather than saves, so it composes with the `saving` hook without
     * recursing. `atriom:rebuild-search` calls this then saves quietly.
     */
    public function refreshSearchText(): void
    {
        $this->attributes['search_text'] = SearchText::blob($this->searchTextSources());
    }

    /**
     * Constrain a query to rows matching every word of the operator's search.
     *
     * Words AND together, matching Filament's own global-search semantics, so
     * "zara cairo" narrows the result set rather than widening it.
     *
     * A query that folds to nothing (blank, or pure punctuation) leaves the
     * builder UNTOUCHED — the caller decides whether "no usable query" means
     * "show everything" (a table with an empty search box) or "show nothing"
     * (global search, which must not dump the database on a stray keystroke).
     * Baking either choice in here would be wrong for the other caller.
     */
    public function scopeSearch(Builder $query, ?string $search): Builder
    {
        foreach (SearchText::words($search) as $word) {
            $query->where('search_text', 'like', '%'.$word.'%');
        }

        return $query;
    }
}
