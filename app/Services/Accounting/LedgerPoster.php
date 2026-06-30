<?php

namespace App\Services\Accounting;

use App\Models\CreditNote;
use App\Models\Invoice;
use App\Models\JournalEntry;
use App\Models\Payment;
use App\Services\Accounting\Journalizers\CreditNoteJournalizer;
use App\Services\Accounting\Journalizers\InvoiceJournalizer;
use App\Services\Accounting\Journalizers\Journalizer;
use App\Services\Accounting\Journalizers\PaymentJournalizer;
use Illuminate\Database\Eloquent\Model;

/**
 * The bridge between business documents and the ledger. Given a source document
 * it finds the right journalizer, builds the balanced payload, and posts it
 * (idempotently — JournalPostingService keys on source, so re-posting the same
 * document returns the existing entry instead of double-booking).
 *
 * Registering a new document type = add one line to the registry below. The GL
 * engine never changes.
 */
class LedgerPoster
{
    public function __construct(
        private JournalPostingService $posting,
        private AccountResolver $accounts,
    ) {}

    /** Post the ledger entry for a source document. Returns null if nothing was posted. */
    public function post(Model $source): ?JournalEntry
    {
        $journalizer = $this->journalizerFor($source);
        if (! $journalizer) {
            return null;
        }

        $payload = $journalizer->payload($source);
        if ($payload === null) {
            return null; // document has no GL effect (draft, cancelled, uncaptured…)
        }

        $payload['source'] = $source;

        return $this->posting->post($payload);
    }

    protected function journalizerFor(Model $source): ?Journalizer
    {
        return match ($source::class) {
            Invoice::class => new InvoiceJournalizer($this->accounts),
            Payment::class => new PaymentJournalizer($this->accounts),
            CreditNote::class => new CreditNoteJournalizer($this->accounts),
            default => null,
        };
    }
}
