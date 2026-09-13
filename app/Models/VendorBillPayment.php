<?php

namespace App\Models;

use App\Models\Concerns\AllocatesDocumentNumber;
use App\Models\Concerns\RecordsBankAccount;
use App\Models\Concerns\RefusesDeletionOfCommittedRecords;
use App\Services\VendorBillService;
use App\Services\VoidVendorBillPaymentService;
use App\Support\Attributes\NeverDeletable;
use App\Support\Attributes\PostingDateGuardedBy;
use App\Support\Attributes\PropertyOwned;
use App\Support\DocumentNumbering;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * سداد فاتورة مورد — a payment against a vendor bill (money leaving cash/bank).
 * Saving/deleting one re-derives the parent bill's paid_amount/balance/status.
 *
 * Soft-deletes so a deleted payment self-heals its GL: the sync-ledger sweep voids
 * a trashed source's journal entry, whereas a hard delete would orphan it (F7).
 */
#[NeverDeletable(correction: 'void the payment — money left the bank')]
#[PropertyOwned(via: 'bill')]
#[PostingDateGuardedBy(guard: VendorBillService::class)]
class VendorBillPayment extends Model
{
    use AllocatesDocumentNumber, HasFactory, RefusesDeletionOfCommittedRecords, SoftDeletes;
    use RecordsBankAccount;

    protected $fillable = [
        'bank_account_id',
        'vendor_bill_id',
        // The payment's OWN number (`PMT-AW-0001`), allocated on create — never the bank's.
        'reference',
        // What the BANK printed: the cheque number or the transfer reference the operator typed.
        // It is what a statement line will be matched by, so it is asked for at recording and
        // shown beside each candidate in the reconciliation picker.
        'bank_reference',
        'amount',
        'withholding_amount',
        'method',
        'payment_date',
        'notes',
        'created_by_user_id',
    ];

    /**
     * Cash that actually left the bank: the gross settlement minus tax withheld for the ETA.
     *
     * `amount` is what discharges the vendor's claim; this is what the bank statement shows.
     * They differ by exactly `withholding_amount` (خصم وإضافة, module 12b).
     */
    public function netPaid(): float
    {
        return round((float) $this->amount - (float) $this->withholding_amount, 2);
    }

    /**
     * A voided payment settles nothing and posts nothing — the ONE predicate for that, shared by
     * `VendorBill::recompute()` (which must not count it) and `VendorBillPaymentJournalizer`
     * (which must stop returning a payload so the sweep reverses the entry). Named once so the
     * document and the ledger cannot disagree about whether the money moved.
     *
     * `voided_at` is deliberately not fillable: only {@see VoidVendorBillPaymentService}
     * may set it, so a void always carries its reason and its reversal.
     */
    public function isVoided(): bool
    {
        return $this->voided_at !== null;
    }

    protected $casts = [
        'amount' => 'decimal:2',
        'withholding_amount' => 'decimal:2',
        'payment_date' => 'date',
        'voided_at' => 'datetime',
    ];

    public function bill(): BelongsTo
    {
        return $this->belongsTo(VendorBill::class, 'vendor_bill_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    /**
     * The prefix a supplier payment's number is allocated within — `PMT-{mall}-{period}-`, the
     * shape of the bill it settles. ONE definition: it is also the lock key that serialises the
     * allocation and the `LIKE` that finds the last number in the series (EG-10).
     */
    public static function numberPrefix(string $assetCode = 'GEN', ?\DateTimeInterface $paymentDate = null): string
    {
        $paymentDate = $paymentDate ? Carbon::instance($paymentDate) : now();

        return sprintf('%s-%s-%s', DocumentNumbering::prefixFor('vendor_payment'), $assetCode, DocumentNumbering::periodSegment($paymentDate));
    }

    public static function generateNumber(string $assetCode = 'GEN', ?\DateTimeInterface $paymentDate = null): string
    {
        $prefix = static::numberPrefix($assetCode, $paymentDate);

        $last = static::withTrashed()
            ->where('reference', 'like', $prefix.'%')
            // LENGTH first: a plain string sort puts `…-9999` above `…-10000`, so once a series
            // passes its zero-padding MAX returns the wrong row (EG-10).
            ->orderByRaw('LENGTH(reference) DESC, reference DESC')
            ->value('reference');

        $next = $last ? ((int) substr($last, strlen($prefix))) + 1 : 1;

        return $prefix.str_pad((string) $next, 4, '0', STR_PAD_LEFT);
    }

    /**
     * Number every payment recorded before the series existed — the deploy step behind the
     * migration that added `bank_reference`, and idempotent, so it can be re-run.
     *
     * In id order, each series continued from whatever it already holds: the day one payment
     * was numbered by hand nothing else may collide with it. Written through the BASE query
     * builder, not `save()` — `saved()` would recompute every bill for a change that moves no
     * figure, and the Eloquent builder's `update()` would stamp `updated_at` on history that did
     * not change.
     *
     * @return int how many rows were numbered
     */
    public static function backfillMissingNumbers(): int
    {
        $numbered = 0;
        $nextInSeries = [];

        // `lazyById`, never `each()`: that one pages by OFFSET, and every row numbered here leaves
        // the `whereNull` set, so offset paging would skip every other chunk.
        $rows = static::withTrashed()
            ->whereNull('reference')
            ->with('bill.asset')
            ->lazyById();

        foreach ($rows as $payment) {
            $prefix = static::numberPrefix($payment->bill?->asset?->code ?: 'GEN', $payment->payment_date);

            if (! array_key_exists($prefix, $nextInSeries)) {
                $last = static::generateNumber($payment->bill?->asset?->code ?: 'GEN', $payment->payment_date);
                $nextInSeries[$prefix] = (int) substr($last, strlen($prefix));
            }

            static::withTrashed()->whereKey($payment->getKey())->toBase()->update([
                'reference' => $prefix.str_pad((string) $nextInSeries[$prefix]++, 4, '0', STR_PAD_LEFT),
            ]);
            $numbered++;
        }

        return $numbered;
    }

    protected static function booted(): void
    {
        // The document's own number, under the allocation lock — the same shape as the bill's.
        // Resolved once: the prefix that keys the lock and the series the generator reads must be
        // the same string, or the lock guards nothing.
        static::creating(function (self $payment): void {
            if (empty($payment->reference)) {
                $assetCode = $payment->bill?->asset?->code ?: 'GEN';

                $payment->reference = $payment->allocateDocumentNumber(
                    static::numberPrefix($assetCode, $payment->payment_date),
                    fn (): string => static::generateNumber($assetCode, $payment->payment_date),
                );
            }
        });

        // Coerce a blank amount to 0 (NOT-NULL column) and keep the parent bill's
        // derived totals in lockstep with its payments. Read the RAW attribute — a
        // decimal:2 cast throws MathException if '' is read through the getter.
        static::saving(function (self $payment) {
            $raw = $payment->getAttributes()['amount'] ?? null;
            if ($raw === null || $raw === '') {
                $payment->amount = 0;
            }
        });

        // A recorded payment is committed the moment it exists — the cash left the bank — so its
        // money and counterparty fields are immutable. The AP mirror of the guards on Invoice,
        // Payment, CreditNote and VendorBill.
        //
        // **Why this could not be written until now.** Locking these fields without a reversal path
        // would have trapped an operator holding a wrong cheque with no way out, which is worse
        // than a mutable row: the refusal has to name a correction that exists. The void
        // (VoidVendorBillPaymentService) shipped first, deliberately, and this is the promotion it
        // unblocked — surfaced by classifying every field in App\Support\ChangeImpact.
        //
        // Nothing legitimate edits them: the payments relation manager creates and edits nothing,
        // recordPayment() only ever inserts, and the bill's own recompute() is a saveQuietly on the
        // PARENT. `voided_at` is deliberately outside the frozen set, so a void still saves.
        static::updating(function (self $payment) {
            foreach (['amount', 'withholding_amount', 'payment_date', 'vendor_bill_id'] as $field) {
                if ($payment->isDirty($field)) {
                    throw new \DomainException(__('admin.errors.vendor_payment_immutable'));
                }
            }
        });

        static::saved(fn (self $payment) => $payment->bill?->recompute());
        static::deleted(fn (self $payment) => $payment->bill?->recompute());
    }
}
