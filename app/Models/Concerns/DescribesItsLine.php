<?php

namespace App\Models\Concerns;

use App\Support\LineNarrative;

/**
 * A money-document line that stores DATA and is worded when it is READ (UX-30).
 *
 * Shared by `InvoiceItem` and `CreditNoteItem` because it is one question asked of two tables:
 * *what does this line say, to the person holding the document?*
 *
 * ## An operator's own words WIN, and typing them clears the key
 *
 * `description` is an editable field on the invoice form. When somebody types there they are
 * describing this particular line, not choosing a template — so the key is dropped and the prose
 * they wrote is what every reader gets, in every language. That is the same precedence
 * `LeaseEventNarrative` gives an operator's own reason, and it was learned there the expensive way:
 * testing the key first threw away the only part of the row carrying the WHY.
 *
 * The clearing happens on the MODEL rather than in the form, so a service, an importer or a console
 * command that rewrites a description cannot leave a key describing text nobody will ever see.
 */
trait DescribesItsLine
{
    public static function bootDescribesItsLine(): void
    {
        static::updating(function (self $line): void {
            // Only when the prose actually moved AND the caller did not state a new key in the same
            // breath: a service re-templating a line legitimately writes both, and reading that as
            // an operator's edit would discard the key it just set.
            if ($line->isDirty('description') && ! $line->isDirty('description_key')) {
                $line->description_key = null;
                $line->description_data = null;
            }
        });
    }

    /**
     * What this line says, in `$locale` — the reader's language, not the writer's.
     *
     * Falls through to the stored prose, which is the floor for every line raised before the key
     * columns existed and for every one an operator worded themselves.
     */
    public function narrative(?string $locale = null): string
    {
        return LineNarrative::resolve(
            $this->description_key,
            $this->description_data,
            $this->description,
            $locale,
        );
    }
}
