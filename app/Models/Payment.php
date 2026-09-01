<?php

namespace App\Models;

use App\Models\Concerns\AllocatesDocumentNumber;
use App\Models\Concerns\GuardsPostingDate;
use App\Models\Concerns\HasSearchText;
use App\Models\Concerns\RecordsBankAccount;
use App\Models\Concerns\RefusesDeletionOfCommittedRecords;
use App\Notifications\PaymentReceivedNotification;
use App\Support\ActivityLogging;
use App\Support\Attributes\NeverDeletable;
use App\Support\Attributes\PostingDateGuardedBy;
use App\Support\Attributes\PropertyOwned;
use App\Support\DocumentNumbering;
use App\Support\InvoiceSettlement;
use App\Support\Translate;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

#[NeverDeletable(correction: 'void the payment (VoidPaymentService) — it reverses the GL and re-opens the invoice')]
// ditto, one hop shorter than it used to be
#[PropertyOwned(via: 'invoices')]
// Payment was guarded only on the admin CREATE page, which left the edit form, the
// portal, the mobile API and the console uncovered — moving a captured payment's date
// into a closed month sailed through. On the model, every path is covered at once.
#[PostingDateGuardedBy(guard: Payment::class)]
class Payment extends Model
{
    use AllocatesDocumentNumber, GuardsPostingDate, HasFactory, HasSearchText, LogsActivity, RefusesDeletionOfCommittedRecords, SoftDeletes;
    use RecordsBankAccount;

    /**
     * Receipt reference, the cheque it came on, and the gateway's own transaction id —
     * the last is what an operator has in hand when reconciling a bank statement.
     *
     * @return array<int, string|int|float|null>
     */
    public function searchTextSources(): array
    {
        return [
            $this->reference,
            $this->cheque_number,
            $this->gateway_transaction_id,
        ];
    }

    /** The column this document's GL entry is dated from (LedgerRealtimeSync::SOURCE_DATE_COLUMNS). */
    public static function postingDateColumn(): string
    {
        return 'payment_date';
    }

    public function getActivitylogOptions(): LogOptions
    {
        return ActivityLogging::for($this, 'payment');
    }

    /** Payment initiation channels — keep the online link + in-app flows separate. */
    public const CHANNEL_MOBILE = 'mobile_api';

    public const CHANNEL_PORTAL = 'portal';

    public const CHANNEL_LINK = 'payment_link';

    public const CHANNEL_ADMIN = 'admin';

    /**
     * Statuses where the money has actually been received and belongs on the books:
     * `captured` (cleared), `reconciled` (matched in accounting), `settled` (final).
     * These are the SINGLE source for "is this payment real" — every AR / GL / collections
     * consumer keys off this set, so a captured→reconciled→settled move never un-pays an
     * invoice or voids its cash entry (the portal AccountBalance widget already grouped them;
     * the core AR/GL had drifted to `captured`-only, which this consolidates).
     * Reversals (`refunded`/`failed`/`bounced`) are NOT here — they correctly re-open AR, and
     * are reached only through the reason-gated Void action, never a bare status edit.
     */
    public const RECEIVED_STATUSES = ['captured', 'reconciled', 'settled'];

    /**
     * The statuses in which this receipt's money is NOT on the books — it was reversed, and the
     * journalizer returns no effect for every one of them.
     *
     * **Four values, and they are four different events, which is the whole reason for the list.**
     * `voided` is a receipt that should never have existed (keyed in error, keyed against the wrong
     * tenant); `refunded` is a receipt that was right and whose money went back; `bounced` is a
     * cheque the bank returned; `failed` is a gateway attempt that never became money. Yardi, MRI
     * and Entrata all separate the first two, because a tenant statement showing *refunded* is a
     * claim that money reached them.
     *
     * **`voided` was added 2026-08-28.** `VoidPaymentService` set `refunded` for the whole of its
     * life, so a cashier who keyed 69,674.50 against the wrong tenant and voided it left a receipt
     * that the tenant's statement, the general ledger and the audit trail all called *refunded* —
     * money returned to someone who never received any. Existing rows are deliberately NOT migrated:
     * nobody can now say which historical reversals were genuine refunds, and inventing that answer
     * for them would be worse than the ambiguity.
     *
     * A LIST, not four literals: `['refunded', 'failed', 'bounced']` was written out in six places
     * — the void service's already-reversed check, the form's read-only condition, the receipt PDF's
     * watermark, and three table colour maps — so adding a fifth event would have been six edits,
     * five of which fail silently by simply not colouring a row.
     */
    public const REVERSED_STATUSES = ['voided', 'refunded', 'failed', 'bounced'];

    /** True when this receipt has been reversed and its money is off the books (see REVERSED_STATUSES). */
    public function isReversed(): bool
    {
        return in_array($this->status, self::REVERSED_STATUSES, true);
    }

    /** True when this payment's money is on the books (see RECEIVED_STATUSES). */
    public function isReceived(): bool
    {
        return in_array($this->status, self::RECEIVED_STATUSES, true);
    }

    /** Scope to payments whose money has been received (captured / reconciled / settled). */
    public function scopeReceived($query)
    {
        return $query->whereIn('status', self::RECEIVED_STATUSES);
    }

    protected $fillable = [
        'bank_account_id',
        'reference',
        'tenant_id',
        'amount',
        'currency',
        'method',
        'status',
        'payment_date',
        'gateway',
        'channel',
        'gateway_transaction_id',
        'gateway_response',
        'cheque_number',
        'cheque_clearance_date',
        'notes',
        'received_by',
        'receipt_notified_at',
    ];

    protected $casts = [
        'payment_date' => 'date',
        'cheque_clearance_date' => 'date',
        'amount' => 'decimal:2',
        'gateway_response' => 'array',
        'receipt_notified_at' => 'datetime',
    ];

    /**
     * Fire the "payment received" tenant notification exactly once — when the
     * payment is captured AND has at least one allocated invoice. Called from
     * the saved() hook (gateway flips, where allocations precede the status
     * change) and from the Create/Edit pages after they sync the pivot (where
     * allocations follow it). Idempotent via receipt_notified_at.
     */
    public function notifyReceiptOnce(): void
    {
        if (! $this->isReceived() || $this->receipt_notified_at) {
            return;
        }

        $this->load('tenant', 'invoices');
        if (! $this->tenant || $this->invoices->isEmpty()) {
            return;
        }

        try {
            $this->tenant->notifyPortal(new PaymentReceivedNotification($this));
            $this->forceFill(['receipt_notified_at' => now()])->saveQuietly();
        } catch (\Throwable $e) {
            \Log::warning('Payment received notification failed', [
                'payment_id' => $this->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function invoices(): BelongsToMany
    {
        return $this->belongsToMany(Invoice::class)
            ->withPivot('allocated_amount')
            ->withTimestamps();
    }

    public function receiver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'received_by');
    }

    /** The post-dated cheque whose clearing produced this payment, if one did. */
    public function clearedCheque(): HasOne
    {
        return $this->hasOne(PostDatedCheque::class, 'cleared_payment_id');
    }

    /**
     * The property this receipt belongs to when its ALLOCATIONS cannot say.
     *
     * `payments` carries no `asset_id` — the books dimension is normally derived from the invoices
     * the receipt settles, which is right: a receipt belongs to the property whose debt it clears.
     * That derivation has one hole, and it is reachable through the ordinary screens.
     *
     * **The case (2026-08-19).** A post-dated cheque may be recorded with no invoice — the form
     * requires a tenant and not an invoice, deliberately, because a cheque often arrives before the
     * invoice it will eventually settle. Clearing one produces a captured `Payment` with **zero
     * allocations**, and `PaymentJournalizer` then had nothing to derive a property from. Measured:
     * Dr bank 50,000 / Cr unearned revenue 50,000, with `asset_id` NULL on the entry **and on both
     * lines** — so the receipt showed on every mall's list and reached **no** owner statement, since
     * `GenerateOwnerStatementRunService` scopes `where('asset_id', $asset->id)`. The landlord's own
     * cash was invisible on the landlord's own statement, and the books tied out throughout.
     *
     * The property was never unknown. It is on the cheque.
     *
     * Only for a receipt with NO allocations at all. A receipt allocated across two properties is a
     * genuinely consolidated one and its entry stays property-less on purpose — that is a different
     * situation with a different right answer, and collapsing the two would file a cross-property
     * receipt under whichever mall happened to come first.
     */
    public function originatingAssetId(): ?int
    {
        return $this->clearedCheque()->value('asset_id');
    }

    /**
     * The prefix a payment receipt's reference is allocated within (EG-10).
     *
     * One method, because the prefix is also the LOCK KEY that serialises allocation and the `LIKE`
     * that finds the last reference in the series — three hand-built copies of it is how a series
     * and the lock guarding it drift apart.
     */
    public static function referencePrefix(?\DateTimeInterface $receivedAt = null): string
    {
        return sprintf(
            '%s-%s',
            DocumentNumbering::prefixFor('payment'),
            DocumentNumbering::periodSegment($receivedAt),
        );
    }

    public static function generateReference(): string
    {
        $prefix = static::referencePrefix();

        $last = static::withTrashed()
            ->where('reference', 'like', $prefix.'%')
            // LENGTH first: a plain string sort puts `…-9999` above `…-10000`, so once a
            // series passes its zero-padding MAX returns the wrong row (EG-10).
            ->orderByRaw('LENGTH(reference) DESC, reference DESC')
            ->value('reference');

        $next = $last
            ? ((int) substr($last, strlen($prefix))) + 1
            : 1;

        return sprintf('%s%04d', $prefix, $next);
    }

    /**
     * Recompute every invoice this payment is allocated to.
     * Call this after attaching, detaching, syncing pivot rows, or changing the payment status.
     */
    public function recomputeAllocatedInvoices(): void
    {
        $this->invoices()->get()->each->recomputeTotals();
    }

    /**
     * Concurrency-safe over-allocation guard. Call INSIDE the same transaction
     * that syncs the pivot (after recompute): it locks each invoice and re-checks
     * that captured allocations + applied credits don't exceed the invoice total.
     * The lock serialises parallel payment saves, so two captures that each fit
     * the balance alone but together over-allocate are caught (the second rolls
     * back). Form-level caps handle the common case; this is the race backstop.
     *
     * @param  array<int>  $invoiceIds
     *
     * @throws \DomainException
     */
    public function assertInvoicesNotOverAllocated(array $invoiceIds): void
    {
        if (empty($invoiceIds)) {
            return;
        }

        foreach (Invoice::whereIn('id', $invoiceIds)->lockForUpdate()->get() as $invoice) {
            // Whose AR is still live, before asking how much of it is left. The pickers narrow to
            // this and a narrowed picker is a UI truth: the ids arrive in a Livewire payload, and
            // `CreatePayment`'s `?invoice=` deep-link prefills from the resource query with no
            // status test at all. Without this the one status the registry argues hardest about —
            // `draft`, where nothing was ever posted — was refused in the dropdown and accepted on
            // save, and the amount check below could not see it: a draft has nothing written off,
            // so its full total fits.
            if (! InvoiceSettlement::accepts($invoice)) {
                throw new \DomainException(__('admin.refusals.invoice_ar_already_relieved', [
                    'number' => $invoice->number,
                    'status' => Translate::orHumanized("admin.statuses.invoice.{$invoice->status}", (string) $invoice->status),
                ]));
            }

            // LOCKING reads, all four. Locking the INVOICE row serialises two concurrent
            // allocations, but it does not make the sums below authoritative: under MySQL
            // REPEATABLE READ a plain read is served from the snapshot this transaction took at its
            // first read, which is BEFORE it waited for the lock — so the second writer sums a
            // pivot that does not yet contain the first writer's allocation and concludes there is
            // room. Measured with two processes on two connections (pre-staging QA, F-09): the
            // guard passed on a fully-settled invoice; what actually refused the second receipt was
            // the UNIQUE index on `payments.reference`, which is not a guarantee anyone chose.
            //
            // A locking read bypasses the snapshot and sees the latest committed rows. All four
            // channels take it, because all four settle the same invoice and the guard is only as
            // strong as its weakest term.
            $allocated = round(
                (float) $invoice->payments()->whereIn('payments.status', self::RECEIVED_STATUSES)->lockForUpdate()->sum('invoice_payment.allocated_amount')
                + (float) $invoice->credit_applied_amount
                + (float) TenantCreditApplication::where('invoice_id', $invoice->id)->lockForUpdate()->sum('amount')
                + (float) DepositApplication::where('invoice_id', $invoice->id)->lockForUpdate()->sum('amount'),
                2,
            );

            // Net of anything already written off, for the reason `refitAllocationsToBalance()`
            // states below: the forgiven part of a partial write-off is no longer receivable, and
            // this guard compared against the raw total.
            // A LOCKING read, like the four sibling terms above it and for the identical reason:
            // under REPEATABLE READ a plain select inside this transaction answers from the snapshot
            // taken BEFORE we waited on the invoice lock, so a write-off another writer committed
            // while we waited would be invisible and this guard would pass on stale data. The full
            // write-off is caught anyway (the invoice row itself is read under a lock, so the status
            // is fresh) — the PARTIAL one lives entirely in `invoice_write_offs` and would not be.
            $writtenOff = round((float) $invoice->writeOffs()->lockForUpdate()->sum('amount'), 2);

            if ($allocated > round((float) $invoice->total, 2) - $writtenOff + 0.01) {
                // Name what THIS payment may allocate, not the invoice total. The refusal used to
                // quote the total — so an operator over-allocating an invoice already part-settled
                // by a credit note was told the cap was 240,300 when 60,200 was left, and the number
                // they were given was one they had just been refused for exceeding. The form-level
                // rule has always quoted the fittable figure; only this backstop disagreed with it.
                $mine = round((float) $this->invoices()
                    ->wherePivot('invoice_id', $invoice->id)
                    ->sum('invoice_payment.allocated_amount'), 2);

                $fittable = round(max((float) $invoice->total - $writtenOff - ($allocated - $mine), 0), 2);

                throw new \DomainException(
                    __('admin.payment.allocation_exceeds_balance', [
                        'invoice' => $invoice->number,
                        'max' => 'EGP '.number_format($fittable, 2),
                    ])
                );
            }
        }
    }

    /**
     * Re-fit this payment's invoice allocations so none exceeds the invoice's
     * currently-fittable amount (total − applied credits − OTHER captured
     * allocations). Any excess stays UNALLOCATED on the payment, which the
     * journalizer books to unearned revenue.
     *
     * Used by the GATEWAY capture path (Paymob callback): the card money is
     * already collected, so — unlike the form guard, which throws — we accept the
     * payment and clamp the allocation. This prevents a credit applied to the
     * invoice between session-init and the callback from over-allocating it.
     *
     * The fittable figure MUST mirror the FOUR AR settlement channels that
     * Invoice::recomputeTotals() and assertInvoicesNotOverAllocated() both count —
     * captured payments, applied credit NOTES (credit_applied_amount), applied
     * on-account tenant CREDIT (TenantCreditApplication) and a netted security
     * DEPOSIT (DepositApplication). Omitting the third let the gateway over-settle
     * an invoice whose balance a tenant credit had reduced between session-init and
     * callback (pre-go-live sweep, HIGH): the card money cleared full while the
     * credit also settled AR, burying the excess as negative AR. Now the surplus
     * stays unallocated (a recoverable overpayment), exactly as the form path's
     * throw-guard would have forced.
     *
     * The fourth (MF-03) was added with that lesson in hand: a tenant paying an
     * invoice their move-out deposit had already settled is the identical bug.
     *
     * Call INSIDE the capture transaction, BEFORE flipping status to captured
     * (so this payment is still excluded from the "captured" sum).
     */
    public function refitAllocationsToBalance(): void
    {
        foreach ($this->invoices()->lockForUpdate()->get() as $invoice) {
            if (! InvoiceSettlement::accepts($invoice)) {
                // An invoice whose AR is no longer live can hold no receivable — the whole payment
                // becomes a tenant overpayment (unearned), never AR against it.
                //
                // This tested `cancelled` alone. `written_off` is the case that mattered and it
                // fell straight through, because a write-off deliberately leaves both `balance` and
                // `total` standing — and the arithmetic below computes from `total`, which
                // cancelling does not zero either, so a cancelled invoice would have produced
                // `fittable = total` rather than 0. Neither was safe by accident here.
                $fittable = 0.0;
            } else {
                // Locking reads for the same reason as the throw-guard above (F-09): this runs
                // inside the capture transaction, so a plain read here would clamp against a
                // snapshot that predates a concurrent settlement and let the card money
                // over-settle the invoice anyway.
                $otherCaptured = (float) $invoice->payments()
                    ->whereIn('payments.status', self::RECEIVED_STATUSES)
                    ->where('payments.id', '!=', $this->getKey())
                    ->lockForUpdate()
                    ->sum('invoice_payment.allocated_amount');

                $appliedTenantCredit = (float) TenantCreditApplication::where('invoice_id', $invoice->getKey())->lockForUpdate()->sum('amount');
                $appliedDeposit = (float) DepositApplication::where('invoice_id', $invoice->getKey())->lockForUpdate()->sum('amount');

                // A PARTIAL write-off nets off here too. `total` is what was billed; the part
                // already relieved to bad debt is no longer receivable, and capping at the raw
                // total let a 20,000 receipt fit a 20,000 invoice with 5,000 written off — AR
                // relieved 25,000 for a 20,000 debt. `WriteOffInvoiceService` nets prior write-offs
                // when it caps a SECOND write-off; this is the same netting on the settlement side,
                // which is the half that was never done.
                // Locking, for the same reason as its three neighbours.
                $writtenOff = (float) $invoice->writeOffs()->lockForUpdate()->sum('amount');

                $fittable = max(0.0, round(
                    (float) $invoice->total
                        - $writtenOff
                        - (float) $invoice->credit_applied_amount
                        - $appliedTenantCredit
                        - $appliedDeposit
                        - $otherCaptured,
                    2,
                ));
            }

            if (round((float) $invoice->pivot->allocated_amount, 2) > $fittable) {
                $this->invoices()->updateExistingPivot($invoice->getKey(), ['allocated_amount' => $fittable]);
            }
        }
    }

    /**
     * Throw if any of the given invoice IDs belongs to a tenant different
     * from this payment. Used by the admin Create/Edit pages before they
     * sync the invoice_payment pivot — the form's tenant filter already
     * prevents cross-tenant picks in normal use, but a stale repeater row
     * or an API client could bypass that, so we guard at the model layer
     * too (audit M06 F-26 / D-19).
     *
     * @param  array<int>  $invoiceIds
     *
     * @throws \DomainException
     */
    public function assertInvoicesShareTenant(array $invoiceIds): void
    {
        if (empty($invoiceIds)) {
            return;
        }

        $offending = Invoice::whereIn('id', $invoiceIds)
            ->where('tenant_id', '!=', $this->tenant_id)
            ->first();

        if ($offending) {
            throw new \DomainException(
                __('admin.payment.cross_tenant_allocation', ['invoice' => $offending->number])
            );
        }
    }

    /**
     * When a payment that CLEARED a post-dated cheque is voided/refunded, the clearing was
     * reversed (the bank returned the cheque) — move the cheque back to `bounced` so the PDC
     * register stops showing it collected and the matured-uncleared scan/card/filter re-surface
     * it. Without this the cheque stays permanently `cleared` pointing at a refunded payment while
     * the invoice's AR (correctly) re-opens, and its own terminal-immutability guard blocks any
     * later correction (audit M33 F-3). The cheque's `updating` hook carves out exactly this
     * cleared→bounced reversal. Called from the saved() hook, gated on a real reversal.
     */
    public function reconcileClearedChequeOnReversal(): void
    {
        $cheques = PostDatedCheque::query()
            ->where('cleared_payment_id', $this->getKey())
            ->where('status', PostDatedCheque::STATUS_CLEARED)
            ->get();

        foreach ($cheques as $cheque) {
            $cheque->update(['status' => PostDatedCheque::STATUS_BOUNCED]);
        }
    }

    protected static function booted(): void
    {
        static::creating(function (self $payment) {
            // Always (re)generate at save time to avoid stale references cached
            // in form state by the time the request reaches the DB.
            //
            // Under the DOCUMENT-NUMBER LOCK since 2026-08-19. `Payment` was the one money model
            // that carried a UNIQUE reference and did not use `AllocatesDocumentNumber`: it had a
            // retry loop, but the loop's existence check is a plain read, so two receipts taken in
            // the same second both computed the same number and one died on the unique index.
            // Reproduced with two processes on two connections (pre-staging QA, F-10): both
            // computed `PAY-202608-0195`, and the loser got a duplicate-key 500 rather than a
            // number of its own. The lock spans the INSERT, so the second writer waits and takes
            // the next number instead of colliding.
            $payment->reference = $payment->allocateDocumentNumber(
                static::referencePrefix(),
                fn (): string => static::generateUniqueReference(),
            );

            if (empty($payment->currency)) {
                $payment->currency = 'EGP';
            }
        });

        // A captured payment's cash movement is immutable — no system path rewrites its
        // amount/date once captured (the gateway callback only flips status, and returns
        // early on an already-captured payment). Guard on the ORIGINAL status so the
        // initiated→captured capture is never blocked. Defense-in-depth behind the form
        // lock — covers the API / tinker / bulk-edit paths a UI lock can't.
        // (GL integrity hardening — Phase 1.)
        static::updating(function (self $payment) {
            if (! in_array($payment->getOriginal('status'), self::RECEIVED_STATUSES, true)) {
                return;
            }
            // `tenant_id` joined the list on 2026-08-24. It was classified DERIVED — "the entry is
            // voided and re-posted to match" — and could not be: `LedgerPoster::matches()` compares
            // the line signature, the date and the asset, and a line's tenant is none of those. So
            // re-pointing a captured receipt moved the sub-ledger and left the GL credit against
            // the original tenant silently, for ever. The receipt is evidence of who paid; a
            // mis-addressed one is corrected by voiding and re-recording, like its amount and date.
            foreach (['amount', 'payment_date', 'tenant_id'] as $field) {
                if ($payment->isDirty($field)) {
                    throw new \DomainException(__('admin.refusals.immutable_payment', ['field' => Translate::orHumanized("admin.fields.{$field}", $field)]));
                }
            }
        });

        static::saved(function (self $payment) {
            // Status change (e.g. captured ↔ failed) must roll forward to invoices.
            $payment->recomputeAllocatedInvoices();

            // If this payment CLEARED a post-dated cheque and has just been voided/refunded
            // (left the received set), the bank reversed the cheque — reconcile the cheque back
            // to `bounced` so the PDC register stops reporting it collected and the
            // matured-uncleared surfaces re-catch it. Guarded to a real status change into a
            // non-received state, so the common capture/recompute path adds no query (audit M33 F-3).
            if ($payment->wasChanged('status') && ! $payment->isReceived()) {
                $payment->reconcileClearedChequeOnReversal();
            }

            // Receipt notification — fires once the payment is captured AND has
            // allocations. The gateway path allocates before flipping to
            // captured (this hook delivers it); the Create/Edit pages allocate
            // AFTER save and re-trigger via notifyReceiptOnce() in their
            // after-hooks. Idempotent through receipt_notified_at.
            $payment->notifyReceiptOnce();
        });

        static::deleted(function (self $payment) {
            $payment->recomputeAllocatedInvoices();
        });
    }

    /**
     * Generate a reference that is guaranteed unique at the moment of save.
     * Falls back to incrementing if a race created a sibling record between
     * lookup and insert.
     */
    protected static function generateUniqueReference(): string
    {
        $candidate = static::generateReference();

        $attempts = 0;
        while (static::withTrashed()->where('reference', $candidate)->exists()) {
            $attempts++;
            if ($attempts > 100) {
                throw new \RuntimeException('Unable to allocate a unique payment reference after 100 attempts.');
            }
            // `referencePrefix()`, never a fourth hand-built copy — a hardcoded period here would
            // take the substring at the wrong offset the moment the reset scheme is not monthly.
            $prefix = static::referencePrefix();
            $n = ((int) substr($candidate, strlen($prefix))) + 1;
            $candidate = sprintf('%s%04d', $prefix, $n);
        }

        return $candidate;
    }

    /**
     * Only money actually received is permanent. An initiated/failed row — including the orphan
     * CreatePayment rolls back when allocation fails — never became money.
     */
    public function isCommittedForDeletionPurposes(): bool
    {
        return $this->isReceived();
    }
}
