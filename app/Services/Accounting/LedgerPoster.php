<?php

namespace App\Services\Accounting;

use App\Models\CreditNote;
use App\Models\DepositTransaction;
use App\Models\DepreciationEntry;
use App\Models\Expense;
use App\Models\FixedAsset;
use App\Models\FixedAssetDisposal;
use App\Models\Invoice;
use App\Models\JournalEntry;
use App\Models\MarketingSpend;
use App\Models\Payment;
use App\Models\Payroll;
use App\Models\StockMovement;
use App\Models\VendorBill;
use App\Models\VendorBillPayment;
use App\Services\Accounting\Journalizers\CreditNoteJournalizer;
use App\Services\Accounting\Journalizers\DepositTransactionJournalizer;
use App\Services\Accounting\Journalizers\DepreciationEntryJournalizer;
use App\Services\Accounting\Journalizers\ExpenseJournalizer;
use App\Services\Accounting\Journalizers\FixedAssetAcquisitionJournalizer;
use App\Services\Accounting\Journalizers\FixedAssetDisposalJournalizer;
use App\Services\Accounting\Journalizers\InvoiceJournalizer;
use App\Services\Accounting\Journalizers\InventoryMovementJournalizer;
use App\Services\Accounting\Journalizers\Journalizer;
use App\Services\Accounting\Journalizers\MarketingSpendJournalizer;
use App\Services\Accounting\Journalizers\PaymentJournalizer;
use App\Services\Accounting\Journalizers\PayrollJournalizer;
use App\Services\Accounting\Journalizers\VendorBillJournalizer;
use App\Services\Accounting\Journalizers\VendorBillPaymentJournalizer;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

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

    /**
     * Reconcile a document's ledger entry to its CURRENT state (idempotent upsert):
     *   - no entry + has effect      → post
     *   - entry matches              → no-op
     *   - entry differs (e.g. late fee bumped the total) → void the stale one + re-post
     *   - no effect now + entry exists (e.g. cancelled / refunded) → void it
     *
     * Safe to call repeatedly. This is what the sweep command and backfill use, so
     * the GL self-heals regardless of how/when the source document changed — no need
     * to entangle real-time hooks with the recomputeTotals/saveQuietly machinery.
     */
    public function sync(Model $source): ?JournalEntry
    {
        $journalizer = $this->journalizerFor($source);
        if (! $journalizer) {
            return null;
        }

        // Lock-safe: serialize concurrent syncs of the SAME document (a manual
        // --all backfill can run alongside the scheduled sweep), and re-read the
        // existing entry under the lock so two runs can't both post.
        return DB::transaction(function () use ($source, $journalizer) {
            // Lock the source row — include trashed rows so a soft-deleted document
            // can still be locked + reconciled (voided) here.
            $lock = $source->newQuery();
            if (method_exists($source, 'trashed')) {
                $lock->withTrashed();
            }
            $lock->whereKey($source->getKey())->lockForUpdate()->first();

            // A soft-deleted document has no ledger effect — its entry must be voided,
            // exactly like a cancelled one. The sweep visits trashed sources
            // (withTrashed) so a deleted-but-posted document self-heals to void.
            $trashed = method_exists($source, 'trashed') && $source->trashed();
            $payload = $trashed ? null : $journalizer->payload($source);

            $existing = JournalEntry::query()
                ->where('source_type', $source->getMorphClass())
                ->where('source_id', $source->getKey())
                ->where('status', 'posted')
                ->lockForUpdate()
                ->latest('id')
                ->first();

            if ($payload === null) {
                if ($existing) {
                    $this->posting->void($existing, 'Document no longer has a ledger effect.');
                }

                return null;
            }

            if ($existing) {
                if ($this->matches($existing, $payload)) {
                    return $existing;
                }
                $this->posting->void($existing, 'Superseded by an updated document.');
            }

            $payload['source'] = $source;

            return $this->posting->post($payload);
        });
    }

    /** True when a posted entry's lines already equal the payload's (same accounts + amounts). */
    protected function matches(JournalEntry $entry, array $payload): bool
    {
        $signature = static function (array $lines): array {
            return collect($lines)
                ->map(fn ($l) => $l['ledger_account_id']
                    .'|'.number_format((float) ($l['debit'] ?? 0), 2, '.', '')
                    .'|'.number_format((float) ($l['credit'] ?? 0), 2, '.', ''))
                ->sort()
                ->values()
                ->all();
        };

        $existing = $entry->lines->map(fn ($l) => [
            'ledger_account_id' => $l->ledger_account_id,
            'debit' => (float) $l->debit,
            'credit' => (float) $l->credit,
        ])->all();

        // The books dimension (asset_id) is part of the entry's identity — an
        // invoice re-pointed to another property must re-derive, not match stale.
        if ((int) $entry->asset_id !== (int) ($payload['asset_id'] ?? 0)) {
            return false;
        }

        return $signature($existing) === $signature($payload['lines']);
    }

    protected function journalizerFor(Model $source): ?Journalizer
    {
        return match ($source::class) {
            Invoice::class => new InvoiceJournalizer($this->accounts),
            Payment::class => new PaymentJournalizer($this->accounts),
            CreditNote::class => new CreditNoteJournalizer($this->accounts),
            VendorBill::class => new VendorBillJournalizer($this->accounts),
            VendorBillPayment::class => new VendorBillPaymentJournalizer($this->accounts),
            Expense::class => new ExpenseJournalizer($this->accounts),
            Payroll::class => new PayrollJournalizer($this->accounts),
            DepositTransaction::class => new DepositTransactionJournalizer($this->accounts),
            MarketingSpend::class => new MarketingSpendJournalizer($this->accounts),
            StockMovement::class => new InventoryMovementJournalizer($this->accounts),
            FixedAsset::class => new FixedAssetAcquisitionJournalizer($this->accounts),
            DepreciationEntry::class => new DepreciationEntryJournalizer($this->accounts),
            FixedAssetDisposal::class => new FixedAssetDisposalJournalizer($this->accounts),
            default => null,
        };
    }
}
