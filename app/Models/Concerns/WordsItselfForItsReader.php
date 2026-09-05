<?php

namespace App\Models\Concerns;

use App\Support\LineNarrative;

/**
 * A money document that stores DATA and is worded when it is READ (UX-30).
 *
 * Shared by `InvoiceItem`, `CreditNoteItem` and `CreditNote`, because it is one question asked of
 * three tables: *what does this say, to the person holding the document?* It began as
 * `DescribesItsLine` on the two line tables and generalised on its second real call site — the
 * credit note's own `reason_notes`, the paragraph a tenant reads ABOVE the lines, which was raw
 * English from the CAM sweep and a write-time `__()` from the move-out one. On the demo books that
 * put an English explanation directly over Arabic line text on a single document.
 *
 * ## An operator's own words WIN, and typing them clears the key
 *
 * Every prose column here is editable on a form. When somebody types there they are describing this
 * particular document, not choosing a template — so the key is dropped and their words are what
 * every reader gets, in every language. Same precedence `LeaseEventNarrative` gives an operator's
 * own reason, learned there the expensive way: testing the key first threw away the only part of
 * the row carrying the WHY.
 *
 * **Both companion columns are checked, not just the key.** The common re-template keeps the SAME
 * key and moves only the DATA, so testing the key alone reads that as a human edit and wipes it.
 *
 * The clearing happens on the MODEL rather than in the form, so a service, an importer or a console
 * command that rewrites prose cannot leave a key describing text nobody will ever see.
 */
trait WordsItselfForItsReader
{
    /**
     * Which prose columns this model words at read time, and where each keeps its key and data.
     *
     * The default is the line shape. A model with a different pair overrides it — `CreditNote`
     * declares `reason_notes` — and gets the boot hook and both accessors for free.
     *
     * @return array<string, array{0: string, 1: string}>
     */
    protected static function narrativeColumns(): array
    {
        return ['description' => ['description_key', 'description_data']];
    }

    public static function bootWordsItselfForItsReader(): void
    {
        static::updating(function (self $model): void {
            foreach (static::narrativeColumns() as $prose => [$keyColumn, $dataColumn]) {
                if ($model->isDirty($prose)
                    && ! $model->isDirty($keyColumn)
                    && ! $model->isDirty($dataColumn)) {
                    $model->{$keyColumn} = null;
                    $model->{$dataColumn} = null;
                }
            }
        });
    }

    /**
     * What this document says, in `$locale` — the reader's language, not the writer's.
     *
     * Falls through to the stored prose, which is the floor for every row written before the key
     * columns existed and for every one an operator worded themselves.
     */
    public function narrative(?string $locale = null): string
    {
        return $this->narrativeFor(array_key_first(static::narrativeColumns()), $locale);
    }

    /** The same, for a model that words more than one column. */
    public function narrativeFor(string $prose, ?string $locale = null): string
    {
        [$keyColumn, $dataColumn] = static::narrativeColumns()[$prose];

        return LineNarrative::resolve(
            $this->{$keyColumn},
            $this->{$dataColumn},
            $this->{$prose},
            $locale,
        );
    }
}
