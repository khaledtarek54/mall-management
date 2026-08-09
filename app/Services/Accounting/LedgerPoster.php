<?php

namespace App\Services\Accounting;

use App\Support\PostMonth;
use App\Models\CreditNote;
use App\Models\Custody;
use App\Models\CustodyTransaction;
use App\Models\DepositTransaction;
use App\Models\DepreciationEntry;
use App\Models\Disbursement;
use App\Models\EmployeeAdvance;
use App\Models\EmployeeAdvanceRepayment;
use App\Models\Expense;
use App\Models\FixedAsset;
use App\Models\FixedAssetDisposal;
use App\Models\Invoice;
use App\Models\JournalEntry;
use App\Models\MaintenancePenalty;
use App\Models\MarketingSpend;
use App\Models\OwnerStatementRun;
use App\Models\Payment;
use App\Models\Payroll;
use App\Models\StockMovement;
use App\Models\InvoiceWriteOff;
use App\Models\DepositApplication;
use App\Models\StraightLineRentAdjustment;
use App\Models\TenantCreditApplication;
use App\Models\VendorBill;
use App\Models\VendorBillPayment;
use App\Services\Accounting\Journalizers\CreditNoteJournalizer;
use App\Services\Accounting\Journalizers\CustodyJournalizer;
use App\Services\Accounting\Journalizers\CustodyTransactionJournalizer;
use App\Services\Accounting\Journalizers\DepositTransactionJournalizer;
use App\Services\Accounting\Journalizers\DepreciationEntryJournalizer;
use App\Services\Accounting\Journalizers\DisbursementJournalizer;
use App\Services\Accounting\Journalizers\EmployeeAdvanceJournalizer;
use App\Services\Accounting\Journalizers\EmployeeAdvanceRepaymentJournalizer;
use App\Services\Accounting\Journalizers\ExpenseJournalizer;
use App\Services\Accounting\Journalizers\FixedAssetAcquisitionJournalizer;
use App\Services\Accounting\Journalizers\FixedAssetDisposalJournalizer;
use App\Services\Accounting\Journalizers\InventoryMovementJournalizer;
use App\Services\Accounting\Journalizers\InvoiceJournalizer;
use App\Services\Accounting\Journalizers\Journalizer;
use App\Services\Accounting\Journalizers\MaintenancePenaltyJournalizer;
use App\Services\Accounting\Journalizers\MarketingSpendJournalizer;
use App\Services\Accounting\Journalizers\OwnerStatementRunJournalizer;
use App\Services\Accounting\Journalizers\PaymentJournalizer;
use App\Services\Accounting\Journalizers\PayrollJournalizer;
use App\Services\Accounting\Journalizers\InvoiceWriteOffJournalizer;
use App\Services\Accounting\Journalizers\DepositApplicationJournalizer;
use App\Services\Accounting\Journalizers\StraightLineRentAdjustmentJournalizer;
use App\Services\Accounting\Journalizers\TenantCreditApplicationJournalizer;
use App\Services\Accounting\Journalizers\VendorBillJournalizer;
use App\Services\Accounting\Journalizers\VendorBillPaymentJournalizer;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The bridge between business documents and the ledger. Given a source document
 * it finds the right journalizer, builds the balanced payload, and posts it
 * (idempotently — JournalPostingService keys on source, so re-posting the same
 * document returns the existing entry instead of double-booking).
 *
 * Registering a new document type = add one line to JOURNALIZERS. The GL engine
 * never changes, and every dispatch path derives from that one line — see below.
 */
class LedgerPoster
{
    /**
     * **The** registry of what posts to the general ledger: source document ⇒ its journalizer.
     * Every journalizer takes only an AccountResolver, so this map is enough to build one.
     *
     * This const is the single source of truth for "which models reach the GL", and all four
     * dispatch paths derive from it via `sources()` rather than re-listing it:
     *   1. `LedgerRealtimeSync::register()`  — the near-real-time post on save/delete/restore
     *   2. `SyncLedgerCommand`               — the windowed + `--all` self-healing sweep
     *   3. `PeriodService`                   — the close gate (via SOURCE_DATE_COLUMNS)
     *   4. `BooksReconciliationService`      — the GL-drift check behind `billing:reconcile`
     *
     * Why it is a const and not a match(): those five lists were hand-maintained copies, and
     * they drifted — `MaintenancePenalty` had a correct journalizer here while being absent
     * from all of the others, so an applied SLA penalty cut the vendor bill's AP balance and
     * posted nothing, and neither the sweep nor the drift check could see it (fixed 2026-07-16).
     * A registry that can be enumerated can be conformance-gated; a match() cannot.
     *
     * @var array<class-string<Model>, class-string<Journalizer>>
     */
    public const JOURNALIZERS = [
        Invoice::class => InvoiceJournalizer::class,
        Payment::class => PaymentJournalizer::class,
        CreditNote::class => CreditNoteJournalizer::class,
        VendorBill::class => VendorBillJournalizer::class,
        VendorBillPayment::class => VendorBillPaymentJournalizer::class,
        MaintenancePenalty::class => MaintenancePenaltyJournalizer::class,
        Expense::class => ExpenseJournalizer::class,
        Payroll::class => PayrollJournalizer::class,
        DepositTransaction::class => DepositTransactionJournalizer::class,
        MarketingSpend::class => MarketingSpendJournalizer::class,
        StockMovement::class => InventoryMovementJournalizer::class,
        FixedAsset::class => FixedAssetAcquisitionJournalizer::class,
        DepreciationEntry::class => DepreciationEntryJournalizer::class,
        FixedAssetDisposal::class => FixedAssetDisposalJournalizer::class,
        EmployeeAdvance::class => EmployeeAdvanceJournalizer::class,
        EmployeeAdvanceRepayment::class => EmployeeAdvanceRepaymentJournalizer::class,
        Custody::class => CustodyJournalizer::class,
        CustodyTransaction::class => CustodyTransactionJournalizer::class,
        OwnerStatementRun::class => OwnerStatementRunJournalizer::class,
        Disbursement::class => DisbursementJournalizer::class,
        TenantCreditApplication::class => TenantCreditApplicationJournalizer::class,
        DepositApplication::class => DepositApplicationJournalizer::class,
        StraightLineRentAdjustment::class => StraightLineRentAdjustmentJournalizer::class,
        InvoiceWriteOff::class => InvoiceWriteOffJournalizer::class,
    ];

    public function __construct(
        private JournalPostingService $posting,
        private AccountResolver $accounts,
    ) {}

    /**
     * Every model that posts to the GL. The one list every dispatch path must walk —
     * derive from this, never re-declare it.
     *
     * @return array<int, class-string<Model>>
     */
    public static function sources(): array
    {
        return array_keys(self::JOURNALIZERS);
    }

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
        $payload = self::applyPostMonth($payload, $source);

        return $this->posting->post($payload);
    }

    /**
     * Move the entry into the document's POST MONTH, if one was set (story MF-05).
     *
     * Applied here, where every payload is built, rather than in each of the 24 journalizers — and
     * before the sync's change-detection reads `entry_date`, which is the part that matters. Apply
     * it any later and the comparison would see the raw document date, decide the entry had drifted,
     * and void-and-repost it on every sweep for ever.
     *
     * A no-op for every document without an override, which is all of them until an operator sets
     * one, so no existing source changes behaviour.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private static function applyPostMonth(array $payload, Model $source): array
    {
        $payload['entry_date'] = PostMonth::resolve($source, $payload['entry_date'] ?? null);

        return $payload;
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

            // BEFORE the change-detection below, not after. The existing entry already carries the
            // overridden date; comparing it against the raw document date would report a drift that
            // is not one, and the sweep would void and re-post the same entry every night.
            if ($payload !== null) {
                $payload = self::applyPostMonth($payload, $source);
            }

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

    /**
     * Dry-run of sync(): would a fresh sync of this source change the ledger? True when the
     * source's posted entry is out of step with its CURRENT state — no entry but it has an
     * effect (would post), an entry but no effect now / trashed (would void), or an entry that
     * differs from the re-derived payload (would re-post). Read-only (no lock, no write) — used
     * by the reconcile harness's GL-in-sync check and the period-close gate.
     */
    public function wouldChange(Model $source): bool
    {
        $journalizer = $this->journalizerFor($source);
        if (! $journalizer) {
            return false;
        }

        $trashed = method_exists($source, 'trashed') && $source->trashed();
        $payload = $trashed ? null : $journalizer->payload($source);

        // Only 'posted' entries, matching sync()'s void/re-post decision. (post()'s idempotency
        // guard also considers 'draft' entries, but no code path ever keys a draft entry to a
        // source — manual drafts have no source — so this can't diverge from sync() today.)
        $existing = JournalEntry::query()
            ->where('source_type', $source->getMorphClass())
            ->where('source_id', $source->getKey())
            ->where('status', 'posted')
            ->latest('id')
            ->first();

        if ($payload === null) {
            return $existing !== null; // effectless (cancelled/trashed) but still posted → would void
        }
        if ($existing === null) {
            return true; // has an effect, nothing posted → would post
        }

        return ! $this->matches($existing, $payload); // differs → would re-post
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

        // So is the entry DATE — it decides which accounting period the entry lands in,
        // which is the whole point of a period. Without this, a date-only correction (an
        // operator fixing a typo'd MarketingSpend.spent_on or FixedAsset.acquisition_date —
        // both deliberately left editable) produced an identical line signature, matched
        // stale, and no-op'd: the entry kept the WRONG period forever. One month's P&L
        // overstates and another understates, by construction, with no control account
        // moving — so AR/AP tie-out cannot see it, and because wouldChange() reuses this
        // method, neither the close gate nor `billing:reconcile --deep` could either.
        // Normalised on both sides: journalizers emit either a Carbon or a Y-m-d string.
        if (self::dateKey($entry->entry_date) !== self::dateKey($payload['entry_date'] ?? null)) {
            return false;
        }

        return $signature($existing) === $signature($payload['lines']);
    }

    /** Normalise a Carbon|string|null date to a comparable Y-m-d, so the source's spelling can't matter. */
    private static function dateKey(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        return $value instanceof \DateTimeInterface
            ? $value->format('Y-m-d')
            : Carbon::parse((string) $value)->format('Y-m-d');
    }

    protected function journalizerFor(Model $source): ?Journalizer
    {
        $journalizer = self::JOURNALIZERS[$source::class] ?? null;

        return $journalizer === null ? null : new $journalizer($this->accounts);
    }
}
