<?php

namespace App\Services\Accounting;

use App\Models\AccountingPeriod;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\LedgerAccount;
use App\Support\JournalNarrative;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * The posting engine (محرك الترحيل). Turns a balanced payload into a journal
 * entry + lines, enforcing the iron rules of double-entry:
 *
 *   - Σ debit = Σ credit (and > 0)
 *   - every line references an is_postable, active account
 *   - every line is one-sided (exactly one of debit/credit > 0, no negatives)
 *   - a posted entry's date falls inside an OPEN accounting period
 *
 * Posted entries are immutable — undo via void(), which posts a reversing entry.
 */
class JournalPostingService
{
    /**
     * Post a balanced journal entry.
     *
     * @param  array{
     *     entry_date?: \DateTimeInterface|string|null,
     *     description_key?: string|null,
     *     description_data?: array<string, mixed>|null,
     *     description_en?: string|null,
     *     description_ar?: string|null,
     *     asset_id?: int|null,
     *     source?: Model|null,
     *     is_manual?: bool,
     *     is_closing?: bool,
     *     status?: 'posted'|'draft',
     *     lines: array<int, array{
     *         ledger_account_id?: int, account_code?: string, account?: LedgerAccount,
     *         debit?: float, credit?: float, description?: string|null,
     *         asset_id?: int|null, tenant_id?: int|null, lease_id?: int|null
     *     }>
     * }  $payload
     */
    public function post(array $payload): JournalEntry
    {
        $status = $payload['status'] ?? 'posted';
        $entryDate = isset($payload['entry_date']) && $payload['entry_date']
            ? Carbon::parse($payload['entry_date'])
            : now();
        $assetId = $payload['asset_id'] ?? null;
        $source = $payload['source'] ?? null;

        // Idempotency: one posted/draft entry per source document. A second post
        // for the same source returns the existing entry rather than double-posting.
        if ($source instanceof Model && $source->exists) {
            $existing = JournalEntry::query()
                ->where('source_type', $source->getMorphClass())
                ->where('source_id', $source->getKey())
                ->whereIn('status', ['draft', 'posted'])
                ->first();
            if ($existing) {
                return $existing;
            }
        }

        $lines = $this->normalizeLines($payload['lines'] ?? []);

        $period = null;
        if ($status === 'posted') {
            $period = $this->assertOpenPeriodFor($entryDate);
        } else {
            $found = AccountingPeriod::forDate($entryDate);
            $period = $found; // draft may have no/closed period; not enforced
        }

        return DB::transaction(function () use ($payload, $status, $entryDate, $assetId, $source, $lines, $period) {
            // Lock-safe idempotency: serialize concurrent posts of the SAME source and
            // re-check under the lock, so two racing posts can't both insert (the check
            // above is only a fast path outside the transaction).
            if ($source instanceof Model && $source->exists) {
                $lock = $source->newQuery();
                if (method_exists($source, 'trashed')) {
                    $lock->withTrashed();
                }
                $lock->whereKey($source->getKey())->lockForUpdate()->first();

                $existing = JournalEntry::query()
                    ->where('source_type', $source->getMorphClass())
                    ->where('source_id', $source->getKey())
                    ->whereIn('status', ['draft', 'posted'])
                    ->lockForUpdate()
                    ->first();
                if ($existing) {
                    return $existing;
                }
            }

            $entry = JournalEntry::create([
                'entry_date' => $entryDate->toDateString(),
                'accounting_period_id' => $period?->id,
                'description_en' => $payload['description_en'] ?? null,
                'description_ar' => $payload['description_ar'] ?? null,
                // EG-36. All 24 journalizers put a narrative KEY in the payload and this — the one
                // place a journal entry row is written — dropped it, so every entry posted since
                // carried prose and no key and `JournalNarrative::resolve()` fell to its snapshot
                // for all of them. Measured on a freshly seeded demo: 0 of 699 entries keyed.
                'description_key' => $payload['description_key'] ?? null,
                'description_data' => $payload['description_data'] ?? null,
                'source_type' => $source instanceof Model ? $source->getMorphClass() : null,
                'source_id' => $source instanceof Model ? $source->getKey() : null,
                'is_closing' => $payload['is_closing'] ?? false,
                'status' => $status,
                'asset_id' => $assetId,
                'posted_by_user_id' => $status === 'posted' ? Auth::id() : null,
                'posted_at' => $status === 'posted' ? now() : null,
            ]);

            // The entry is inserted ALREADY posted, so its own lines have to be written with the
            // posted-entry guard suspended — this is the engine the guard exists to be the only
            // exception to. See JournalLine::withinPostingEngine().
            JournalLine::withinPostingEngine(function () use ($entry, $lines, $assetId) {
                foreach ($lines as $line) {
                    $entry->lines()->create([
                        'ledger_account_id' => $line['ledger_account_id'],
                        'debit' => $line['debit'],
                        'credit' => $line['credit'],
                        'description' => $line['description'],
                        'asset_id' => $line['asset_id'] ?? $assetId,
                        'tenant_id' => $line['tenant_id'] ?? null,
                        'lease_id' => $line['lease_id'] ?? null,
                    ]);
                }
            });

            return $entry->load('lines');
        });
    }

    /**
     * Post an already-saved DRAFT entry (created via the UI repeater). Re-validates
     * its persisted lines, assigns the open period, and flips it to 'posted'.
     */
    public function postDraft(JournalEntry $entry): JournalEntry
    {
        if ($entry->status === 'posted') {
            return $entry;
        }
        if ($entry->status === 'void') {
            throw new \DomainException(__('admin.refusals.je_post_voided'));
        }

        $entry->loadMissing('lines.account');
        $this->assertLinesValid($this->linesFromModel($entry));

        return DB::transaction(function () use ($entry) {
            $period = $this->assertOpenPeriodFor(Carbon::parse($entry->entry_date));
            $entry->accounting_period_id = $period->id;
            $entry->status = 'posted';
            $entry->posted_by_user_id = Auth::id();
            $entry->posted_at = now();
            $entry->save();

            return $entry->refresh();
        });
    }

    /**
     * Map a persisted entry's lines into the uniform shape assertLinesValid wants.
     *
     * @return array<int, array{account: ?LedgerAccount, debit: float, credit: float}>
     */
    protected function linesFromModel(JournalEntry $entry): array
    {
        return $entry->lines->map(fn ($line) => [
            'account' => $line->account,
            'debit' => round((float) $line->debit, 2),
            'credit' => round((float) $line->credit, 2),
        ])->all();
    }

    /**
     * Void a posted entry by posting a balanced reversing entry (قيد عكسي) that
     * swaps debit/credit, then marking the original 'void'. Both stay on the
     * books and net to zero. Idempotent — voiding a void is a no-op; a non-posted
     * (draft) entry cannot be voided.
     *
     * **What the reversal SAYS is a KEY, not prose (EG-36, SW-150).** This method built its
     * narrative by concatenation, and every machine `$reason` reaching it is an English sentence —
     * so the Arabic ledger read 'قيد عكسي للقيد JE-0519 — Superseded by an updated document.', one
     * line in two languages on the register an auditor reads, with no wording fix able to reach a
     * row already posted. It is the normal case, not an edge one: `LedgerPoster::sync()` voids and
     * re-posts on EVERY re-derive.
     *
     * @param  string|null  $reason  Words a PERSON typed. Never translated — an operator's own
     *                               explanation is evidence, the same rule LeaseEventNarrative
     *                               states. What is translated is the sentence AROUND them.
     * @param  string|null  $reasonKey  A JournalNarrative key, for a reason a PROGRAMMER wrote.
     *                                  Anything hardcoded belongs here rather than in $reason.
     * @param  array<string, string|int|float|null>  $reasonData  Placeholders for $reasonKey.
     */
    public function void(
        JournalEntry $entry,
        ?string $reason = null,
        ?string $reasonKey = null,
        array $reasonData = [],
    ): JournalEntry {
        if ($entry->status === 'void') {
            return $entry;
        }
        if ($entry->status !== 'posted') {
            throw new \DomainException(__('admin.refusals.je_void_state'));
        }

        $entry->loadMissing('lines');

        [$narrativeKey, $narrativeData] = $this->reversalNarrative($entry, $reason, $reasonKey, $reasonData);

        return DB::transaction(function () use ($entry, $reason, $narrativeKey, $narrativeData) {
            // Reverse into the original entry's period when it is still open (keeps
            // each period self-consistent); else into today's open period; else
            // refuse rather than silently shifting the books to another period.
            [$reversalDate, $period] = $this->reversalPeriod($entry);

            // The prose columns stay as the floor (EG-36) — but written by RESOLVING the key in
            // each language, so the Arabic snapshot is actually Arabic. These literals are the
            // last-resort floor for a key that somehow does not resolve, and are exactly what this
            // method wrote before SW-150.
            $proseEn = 'Reversal of '.$entry->number.($reason ? ' — '.$reason : '');
            $proseAr = 'قيد عكسي للقيد '.$entry->number.($reason ? ' — '.$reason : '');

            $reversal = JournalEntry::create([
                'entry_date' => $reversalDate->toDateString(),
                'accounting_period_id' => $period->id,
                'description_en' => JournalNarrative::resolve($narrativeKey, $narrativeData, $proseEn, $proseAr, 'en'),
                'description_ar' => JournalNarrative::resolve($narrativeKey, $narrativeData, $proseEn, $proseAr, 'ar'),
                'description_key' => $narrativeKey,
                'description_data' => $narrativeData,
                // A reversal inherits the original's closing flag — reversing a
                // year-end closing entry must also stay out of the income statement.
                'is_closing' => $entry->is_closing,
                'status' => 'posted',
                'asset_id' => $entry->asset_id,
                'posted_by_user_id' => Auth::id(),
                'posted_at' => now(),
                'reversal_of_id' => $entry->id,
            ]);

            JournalLine::withinPostingEngine(function () use ($entry, $reversal) {
                foreach ($entry->lines as $line) {
                    $reversal->lines()->create([
                        'ledger_account_id' => $line->ledger_account_id,
                        'debit' => $line->credit, // swapped
                        'credit' => $line->debit, // swapped
                        'description' => $line->description,
                        'asset_id' => $line->asset_id,
                        'tenant_id' => $line->tenant_id,
                        'lease_id' => $line->lease_id,
                    ]);
                }
            });

            $entry->status = 'void';
            $entry->voided_at = now();
            $entry->save();

            return $reversal->load('lines');
        });
    }

    /**
     * What a reversal SAYS — the narrative KEY and its placeholders.
     *
     * Three shapes, and the order matters. A caller that states a KEY has written the reason in
     * code, so it is translated. A caller that passes only a `$reason` is relaying words a PERSON
     * typed, which are never translated — only the sentence around them is, which is why
     * `reversal.reason` carries the typed string as a placeholder rather than trying to resolve it.
     * Passing neither is legitimate too: an entry reversed with no explanation still needs a
     * narrative, because a ledger line with no description reads as an entry nobody described.
     *
     * @param  array<string, string|int|float|null>  $reasonData
     * @return array{0: string, 1: array<string, string|int|float|null>}
     */
    private function reversalNarrative(JournalEntry $entry, ?string $reason, ?string $reasonKey, array $reasonData): array
    {
        $data = ['number' => (string) $entry->number] + $reasonData;

        if ($reasonKey !== null) {
            return [$reasonKey, $data];
        }

        if (filled($reason)) {
            return ['reversal.reason', $data + ['reason' => $reason]];
        }

        return ['reversal.posted', $data];
    }

    /**
     * Resolve each raw line's account and build a uniform shape, then validate the
     * whole set. `account` is carried so assertLinesValid can check it without a
     * re-query.
     *
     * @return array<int, array{ledger_account_id:int, account:LedgerAccount, debit:float, credit:float, description:?string, asset_id:?int, tenant_id:?int, lease_id:?int}>
     */
    protected function normalizeLines(array $rawLines): array
    {
        $out = [];
        foreach ($rawLines as $line) {
            $account = $this->resolveAccount($line);

            $out[] = [
                'ledger_account_id' => $account->id,
                'account' => $account,
                'debit' => round((float) ($line['debit'] ?? 0), 2),
                'credit' => round((float) ($line['credit'] ?? 0), 2),
                'description' => $line['description'] ?? null,
                'asset_id' => $line['asset_id'] ?? null,
                'tenant_id' => $line['tenant_id'] ?? null,
                'lease_id' => $line['lease_id'] ?? null,
            ];
        }

        $this->assertLinesValid($out);

        return $out;
    }

    /**
     * The single double-entry validator used by BOTH the array path (post) and the
     * persisted path (postDraft). Each line must carry `account`, `debit`, `credit`.
     * Enforces: ≥2 lines, postable+active accounts, one-sided non-negative amounts,
     * non-zero movement, and Σ debit = Σ credit (to the cent).
     *
     * @param  array<int, array{account: ?LedgerAccount, debit: float, credit: float}>  $lines
     */
    protected function assertLinesValid(array $lines): void
    {
        if (count($lines) < 2) {
            throw new \DomainException(__('admin.refusals.je_needs_two_lines'));
        }

        $debit = 0.0;
        $credit = 0.0;
        foreach ($lines as $i => $line) {
            $account = $line['account'] ?? null;
            if (! $account instanceof LedgerAccount) {
                throw new \DomainException(__('admin.refusals.je_line_unknown_account', ['line' => $i]));
            }
            if (! $account->is_postable) {
                throw new \DomainException(__('admin.refusals.je_line_summary_account', ['line' => $i, 'code' => $account->code]));
            }
            if (! $account->is_active) {
                throw new \DomainException(__('admin.refusals.je_line_inactive_account', ['line' => $i, 'code' => $account->code]));
            }

            $d = round((float) ($line['debit'] ?? 0), 2);
            $c = round((float) ($line['credit'] ?? 0), 2);
            if ($d < 0 || $c < 0) {
                throw new \DomainException(__('admin.refusals.je_line_negative', ['line' => $i]));
            }
            if ($d > 0 && $c > 0) {
                throw new \DomainException(__('admin.refusals.je_line_two_sided', ['line' => $i]));
            }
            if ($d == 0.0 && $c == 0.0) {
                throw new \DomainException(__('admin.refusals.je_line_empty', ['line' => $i]));
            }
            $debit += $d;
            $credit += $c;
        }

        $debit = round($debit, 2);
        $credit = round($credit, 2);
        if ($debit <= 0) {
            throw new \DomainException(__('admin.refusals.je_zero_amount'));
        }
        if (abs($debit - $credit) >= 0.005) {
            throw new \DomainException(__('admin.refusals.je_unbalanced', ['debit' => $debit, 'credit' => $credit]));
        }
    }

    protected function resolveAccount(array $line): LedgerAccount
    {
        if (($line['account'] ?? null) instanceof LedgerAccount) {
            return $line['account'];
        }
        if (! empty($line['ledger_account_id'])) {
            $account = LedgerAccount::find($line['ledger_account_id']);
        } elseif (! empty($line['account_code'])) {
            $account = LedgerAccount::where('code', $line['account_code'])->first();
        } else {
            $account = null;
        }

        if (! $account) {
            throw new \DomainException(__('admin.refusals.je_unknown_account'));
        }

        return $account;
    }

    /**
     * Choose the date + open period to post a reversal into: the original entry's
     * period if still open (keeps that period self-consistent), else today's open
     * period, else refuse.
     *
     * @return array{0: Carbon, 1: AccountingPeriod}
     */
    protected function reversalPeriod(JournalEntry $entry): array
    {
        $found = $this->openPeriodForReversalOf($entry);

        if ($found === null) {
            throw new \DomainException(__('admin.refusals.je_void_no_open_period'));
        }

        return $found;
    }

    /**
     * The same choice, ASKED rather than enforced — null where `reversalPeriod()` would refuse.
     *
     * A caller that must know BEFORE it commits needs this (SW-230): the ledger void runs in an
     * `afterCommit` job whose handler catches `\Throwable` and only logs, so a reversal that cannot
     * find an open period leaves the source row gone and its entry standing. The rule lives here
     * once and is read twice — a second copy at the call site is how the guard and the act come to
     * disagree about which periods are open.
     *
     * @return array{0: Carbon, 1: AccountingPeriod}|null
     */
    public function openPeriodForReversalOf(JournalEntry $entry): ?array
    {
        foreach ([Carbon::parse($entry->entry_date), now()] as $date) {
            $period = AccountingPeriod::forDate($date);
            if ($period && $period->isOpen()) {
                return [$date, $period];
            }
        }

        return null;
    }

    protected function assertOpenPeriodFor(Carbon $date): AccountingPeriod
    {
        $period = AccountingPeriod::forDate($date);

        if (! $period) {
            throw new \DomainException(__('admin.refusals.je_no_period', ['date' => $date->toDateString()]));
        }
        if (! $period->isOpen()) {
            throw new \DomainException(__('admin.refusals.je_period_closed', ['month' => $date->format('Y-m')]));
        }

        return $period;
    }
}
