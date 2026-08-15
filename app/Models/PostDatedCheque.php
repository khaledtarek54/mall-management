<?php

namespace App\Models;

use App\Models\Concerns\HasSearchText;
use App\Models\Concerns\RefusesDeletionOfCommittedRecords;
use App\Support\DocumentNumbering;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * شيك آجل — a post-dated cheque lodged by a tenant, tracked in a forward register with a maturity
 * date and a bounce lifecycle. v1 is register-only: the tenant's invoice stays open until the
 * cheque CLEARS, at which point a normal cheque Payment is recorded (so AR stays correct). A
 * cleared or cancelled cheque is terminal-immutable.
 */
class PostDatedCheque extends Model
{
    use HasFactory, HasSearchText, LogsActivity, RefusesDeletionOfCommittedRecords, SoftDeletes;

    public const STATUS_HELD = 'held';           // received, awaiting maturity

    public const STATUS_DEPOSITED = 'deposited';  // presented to the bank

    public const STATUS_CLEARED = 'cleared';      // funds received → a Payment was recorded

    public const STATUS_BOUNCED = 'bounced';      // returned unpaid

    public const STATUS_CANCELLED = 'cancelled';  // voided before clearing

    public const STATUSES = [self::STATUS_HELD, self::STATUS_DEPOSITED, self::STATUS_CLEARED, self::STATUS_BOUNCED, self::STATUS_CANCELLED];

    protected $fillable = [
        'reference',
        'asset_id',
        'tenant_id',
        'lease_id',
        'invoice_id',
        'cheque_number',
        'bank_name',
        'amount',
        'currency',
        'cheque_date',
        'received_date',
        'status',
        'cleared_payment_id',
        'nsf_fee_invoice_id',
        'notes',
    ];

    /** The invoice carrying this bounce's returned-cheque fee, once one has been raised. */
    public function nsfFeeInvoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class, 'nsf_fee_invoice_id');
    }

    protected $casts = [
        'amount' => 'decimal:2',
        'cheque_date' => 'date',
        'received_date' => 'date',
    ];

    protected $attributes = [
        'amount' => 0,
        'currency' => 'EGP',
        'status' => self::STATUS_HELD,
    ];

    /**
     * Our reference plus what is written on the cheque itself — an operator holding the
     * physical cheque has the cheque number and the bank, not our reference.
     *
     * @return array<int, string|int|float|null>
     */
    public function searchTextSources(): array
    {
        return [
            $this->reference,
            $this->cheque_number,
            $this->bank_name,
        ];
    }

    /**
     * Refuse a second register row for the same physical cheque.
     *
     * A cheque number is unique within a BANK ACCOUNT, so the key is (tenant, bank, number) —
     * two tenants banking with different banks may legitimately hold the same number. CANCELLED
     * cheques are excluded, which is the whole reason this is a model guard rather than a unique
     * index: a mis-keyed cheque must be cancellable and re-lodgeable at the right number.
     *
     * It is money, not tidiness. Two rows for one piece of paper are each independently
     * clearable, and each clear records a captured Payment — the second settles AR that no money
     * backs, or mints an on-account credit the tenant never funded. `lodgeSeries()` makes it easy
     * to hit by accident: re-running it over the same cheque book regenerates the identical
     * sequential numbers by design.
     *
     * DEVIATION FROM YARDI, deliberately: Yardi warns on a duplicate check number and lets the
     * operator through. We refuse — a PDC register that double-counts is a cash forecast wrong in
     * the operator's favour, and cancel-then-re-lodge costs one click.
     */
    public function assertChequeNumberNotAlreadyLodged(): void
    {
        if (blank($this->cheque_number) || $this->status === self::STATUS_CANCELLED) {
            return;
        }

        $clash = static::query()
            ->where('tenant_id', $this->tenant_id)
            ->where('cheque_number', $this->cheque_number)
            ->where('status', '!=', self::STATUS_CANCELLED)
            ->when($this->exists, fn ($q) => $q->whereKeyNot($this->getKey()))
            // A blank bank on either side can't distinguish two cheques, so it collides with
            // anything of that tenant's carrying the same number.
            ->when(filled($this->bank_name), fn ($q) => $q->where(fn ($w) => $w
                ->where('bank_name', $this->bank_name)
                ->orWhereNull('bank_name')))
            ->first();

        if ($clash) {
            throw new \DomainException(__('admin.post_dated_cheques.errors.duplicate_cheque_number', [
                'number' => $this->cheque_number,
                'reference' => $clash->reference,
            ]));
        }
    }

    protected static function booted(): void
    {
        static::saving(function (self $cheque) {
            if (! in_array($cheque->status, self::STATUSES, true)) {
                throw new \InvalidArgumentException("Invalid post-dated cheque status '{$cheque->status}'.");
            }

            // One physical cheque, one register row. Checked on create and on any edit that
            // moves the number / bank / tenant, so the duplicate can't be reached by editing
            // either — see assertChequeNumberNotAlreadyLodged().
            if (! $cheque->exists
                || $cheque->isDirty(['cheque_number', 'bank_name', 'tenant_id', 'status'])) {
                $cheque->assertChequeNumberNotAlreadyLodged();
            }

            // Isolation guard: a linked invoice MUST belong to the same property AND the same tenant
            // as the cheque. The form scopes the picker, but this is the real gate — a crafted
            // request (or editing the tenant AFTER linking) could otherwise attach another party's
            // invoice, and clearing the cheque would settle it. Re-checked on any invoice_id /
            // asset_id / tenant_id change — the `tenant_id` trigger closes the edit-the-tenant path
            // the property-only check missed (audit M33 F-2).
            if ($cheque->invoice_id && ($cheque->isDirty('invoice_id') || $cheque->isDirty('asset_id') || $cheque->isDirty('tenant_id'))) {
                $invoice = Invoice::whereKey($cheque->invoice_id)->first();
                // The invoice's own column. Via the lease chain an owner assessment answered null,
                // and the check below skips a null — so a cheque could be linked across properties
                // to exactly the invoices this guard was written to protect.
                $invoiceAssetId = $invoice?->asset_id;
                if ($invoiceAssetId !== null && (int) $invoiceAssetId !== (int) $cheque->asset_id) {
                    // Property leak: clearing would move another mall's AR + GL.
                    throw new \DomainException('The linked invoice belongs to a different property than the cheque.');
                }
                if ($invoice !== null && (int) $invoice->tenant_id !== (int) $cheque->tenant_id) {
                    // Same-property but cross-TENANT: clearing would settle another tenant's invoice
                    // with this tenant's payment, contaminating the per-tenant AR sub-ledger + owner
                    // statements (the exact class Payment::assertInvoicesShareTenant guards).
                    throw new \DomainException('The linked invoice belongs to a different tenant than the cheque.');
                }
            }
        });

        // A cleared/cancelled cheque is terminal — its money-material fields never change
        // (soft-delete/restore, which touch only deleted_at, are still allowed).
        static::updating(function (self $cheque) {
            $original = $cheque->getOriginal('status');
            if (! in_array($original, [self::STATUS_CLEARED, self::STATUS_CANCELLED], true)) {
                return;
            }

            // The ONE legitimate way out of `cleared`: a reversal to `bounced` when the clearing
            // Payment is voided (the bank returned the cheque after all). Payment::saved drives
            // this (audit M33 F-3) and it mirrors the money reversal — VoidPaymentService re-opens
            // the invoice's AR. Without the carve-out the cheque would be stranded `cleared`
            // forever, pointing at a refunded payment, invisible to the matured-uncleared surfaces.
            $isClearingReversal = $original === self::STATUS_CLEARED
                && $cheque->status === self::STATUS_BOUNCED
                && ! $cheque->isDirty('amount')
                && ! $cheque->isDirty('cleared_payment_id');
            if ($isClearingReversal) {
                return;
            }

            if ($cheque->isDirty('status') || $cheque->isDirty('amount') || $cheque->isDirty('cleared_payment_id')) {
                throw new \DomainException("A {$original} post-dated cheque is immutable.");
            }
        });
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['reference', 'tenant_id', 'invoice_id', 'cheque_number', 'amount', 'cheque_date', 'status'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges()
            ->useLogName('post_dated_cheque');
    }

    public static function generateReference(): string
    {
        $year = now()->format('Y');
        $count = static::whereYear('created_at', $year)->withTrashed()->count() + 1;

        $candidate = sprintf('%s-%s-%04d', DocumentNumbering::prefixFor('post_dated_cheque'), $year, $count);
        $attempts = 0;
        while (static::withTrashed()->where('reference', $candidate)->exists() && $attempts < 50) {
            $candidate = sprintf('%s-%s-%04d', DocumentNumbering::prefixFor('post_dated_cheque'), $year, ++$count);
            $attempts++;
        }

        return $candidate;
    }

    /** Still outstanding (not yet cleared/cancelled) — the amount the register still expects to collect. */
    public function isOutstanding(): bool
    {
        return in_array($this->status, [self::STATUS_HELD, self::STATUS_DEPOSITED, self::STATUS_BOUNCED], true);
    }

    /** Awaiting maturity/clearing — the states that still owe cash into the register. */
    public const AWAITING_STATUSES = [self::STATUS_HELD, self::STATUS_DEPOSITED];

    /**
     * Cheques matured (post-date reached) but not yet cleared — money the operator should already
     * have collected. Shared by `pdc:scan-maturing`, the Action Required card and the table filter
     * so the nightly report, the live count and the list can never disagree.
     */
    public function scopeMaturedUncleared(Builder $query, ?CarbonInterface $on = null): Builder
    {
        return $query->whereIn('status', self::AWAITING_STATUSES)
            ->whereDate('cheque_date', '<=', ($on ?? Carbon::today())->toDateString());
    }

    /** Cheques maturing within the next `$days` (the forward slice of the maturity schedule). */
    public function scopeMaturingWithin(Builder $query, int $days, ?CarbonInterface $on = null): Builder
    {
        $on = ($on ?? Carbon::today());

        return $query->whereIn('status', self::AWAITING_STATUSES)
            ->whereDate('cheque_date', '>', $on->toDateString())
            ->whereDate('cheque_date', '<=', $on->copy()->addDays($days)->toDateString());
    }

    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class);
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function lease(): BelongsTo
    {
        return $this->belongsTo(Lease::class);
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function clearedPayment(): BelongsTo
    {
        return $this->belongsTo(Payment::class, 'cleared_payment_id');
    }
}
