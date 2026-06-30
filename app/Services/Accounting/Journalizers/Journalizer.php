<?php

namespace App\Services\Accounting\Journalizers;

use Illuminate\Database\Eloquent\Model;

/**
 * A journalizer turns ONE business document (invoice, payment, credit note, …)
 * into a balanced JournalPostingService payload. This is the extensibility
 * contract: a new module posts to the ledger by adding one journalizer and
 * registering it in LedgerPoster — the GL engine itself never changes.
 *
 * Implementations use AccountResolver to map semantic roles to accounts, so they
 * never hard-code an account number.
 */
interface Journalizer
{
    /**
     * Build a JournalPostingService::post() payload for $source, or return null
     * to skip (the document has no GL effect — e.g. a draft or cancelled doc).
     * The caller (LedgerPoster) attaches the `source` for idempotency.
     */
    public function payload(Model $source): ?array;
}
