<?php

namespace App\Models;

use App\Models\Concerns\HasSearchText;
use App\Models\Concerns\RefusesDeletionOfCommittedRecords;
use App\Models\Concerns\GuardsPostingDate;
use App\Notifications\PaymentReceivedNotification;
use App\Support\Attributes\NeverDeletable;
use App\Support\Attributes\PropertyOwned;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

#[NeverDeletable(correction: 'void the payment (VoidPaymentService) — it reverses the GL and re-opens the invoice')]
// ditto, one hop shorter than it used to be
#[PropertyOwned(via: 'invoices')]
class Payment extends Model
{
    use RefusesDeletionOfCommittedRecords, GuardsPostingDate, HasFactory, HasSearchText, LogsActivity, SoftDeletes;

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
        return LogOptions::defaults()
            ->logOnly(['reference', 'tenant_id', 'amount', 'method', 'status', 'payment_date'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges()
            ->useLogName('payment');
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

    public static function generateReference(): string
    {
        $yearMonth = now()->format('Ym');
        $prefix = "PAY-{$yearMonth}-";

        $last = static::withTrashed()
            ->where('reference', 'like', $prefix.'%')
            ->orderByDesc('reference')
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
            $allocated = round(
                (float) $invoice->payments()->whereIn('payments.status', self::RECEIVED_STATUSES)->sum('invoice_payment.allocated_amount')
                + (float) $invoice->credit_applied_amount
                + (float) TenantCreditApplication::where('invoice_id', $invoice->id)->sum('amount')
                + (float) \App\Models\DepositApplication::where('invoice_id', $invoice->id)->sum('amount'),
                2,
            );

            if ($allocated > round((float) $invoice->total, 2) + 0.01) {
                throw new \DomainException(
                    __('admin.payment.allocation_exceeds_balance', [
                        'invoice' => $invoice->number,
                        'max' => 'EGP '.number_format((float) $invoice->total, 2),
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
            if ($invoice->status === 'cancelled') {
                // A cancelled invoice has left the books and can hold no receivable —
                // the whole payment becomes a tenant overpayment (unearned), never AR
                // against a cancelled invoice.
                $fittable = 0.0;
            } else {
                $otherCaptured = (float) $invoice->payments()
                    ->whereIn('payments.status', self::RECEIVED_STATUSES)
                    ->where('payments.id', '!=', $this->getKey())
                    ->sum('invoice_payment.allocated_amount');

                $appliedTenantCredit = (float) TenantCreditApplication::where('invoice_id', $invoice->getKey())->sum('amount');
                $appliedDeposit = (float) \App\Models\DepositApplication::where('invoice_id', $invoice->getKey())->sum('amount');

                $fittable = max(0.0, round(
                    (float) $invoice->total
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
            $payment->reference = static::generateUniqueReference();

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
            foreach (['amount', 'payment_date'] as $field) {
                if ($payment->isDirty($field)) {
                    throw new \DomainException("A captured payment's {$field} is immutable — void and re-record instead.");
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
            $yearMonth = now()->format('Ym');
            $prefix = "PAY-{$yearMonth}-";
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
