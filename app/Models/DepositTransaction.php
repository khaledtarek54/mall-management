<?php

namespace App\Models;

use App\Support\Attributes\NeverDeletable;
use App\Support\DocumentNumbering;
use App\Models\Concerns\HasSearchText;
use App\Models\Concerns\RefusesDeletionOfCommittedRecords;
use App\Models\Concerns\GuardsPostingDate;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * حركة تأمين — one security-deposit event (receipt / refund / forfeit). Each is a
 * standalone GL posting; the tenant/asset are derived from the lease. amount is
 * coerced from blank on save (NOT-NULL). isPostable → status 'recorded'.
 */
#[NeverDeletable(correction: 'reverse the deposit transaction')]
class DepositTransaction extends Model
{
    use RefusesDeletionOfCommittedRecords, \App\Models\Concerns\AllocatesDocumentNumber;

    use GuardsPostingDate, HasFactory, HasSearchText, LogsActivity, SoftDeletes;

    /**
     * The transaction number.
     *
     * @return array<int, string|int|float|null>
     */
    public function searchTextSources(): array
    {
        return [
            $this->number,
        ];
    }

    /** The column this document's GL entry is dated from (LedgerRealtimeSync::SOURCE_DATE_COLUMNS). */
    public static function postingDateColumn(): string
    {
        return 'transaction_date';
    }

    public const TYPES = ['receipt', 'refund', 'forfeit'];

    protected $fillable = [
        'number',
        'lease_id',
        'tenant_id',
        'asset_id',
        'type',
        'amount',
        'transaction_date',
        'method',
        'status',
        'notes',
        'created_by_user_id',
    ];

    protected $casts = [
        'transaction_date' => 'date',
        'amount' => 'decimal:2',
    ];

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['number', 'status', 'lease_id', 'asset_id', 'type', 'amount', 'method'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges()
            ->useLogName('deposit_transaction');
    }

    public function lease(): BelongsTo
    {
        return $this->belongsTo(Lease::class);
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class);
    }

    public function isPostable(): bool
    {
        return $this->status === 'recorded';
    }

    /**
     * The number prefix for this document's sequence — ONE definition, used by generateNumber()
     * and by the allocation lock key (see AllocatesDocumentNumber). Two copies would drift, and a
     * lock keyed on a prefix that no longer matches the sequence it guards protects nothing.
     */
    public static function numberPrefix(string $assetCode = 'GEN', ?\DateTimeInterface $date = null): string
    {
        $date = $date ? Carbon::instance($date) : now();

        return sprintf('%s-%s-%s-', DocumentNumbering::prefixFor('deposit'), $assetCode, $date->format('Ym'));
    }

    public static function generateNumber(string $assetCode = 'GEN', ?\DateTimeInterface $date = null): string
    {
        $prefix = static::numberPrefix($assetCode, $date);

        $lastNumber = static::withTrashed()
            ->where('number', 'like', $prefix.'%')
            ->orderByDesc('number')
            ->value('number');

        $next = $lastNumber ? ((int) substr($lastNumber, strlen($prefix))) + 1 : 1;

        return $prefix.str_pad((string) $next, 4, '0', STR_PAD_LEFT);
    }

    /**
     * Bump the suffix until the number is free — the bare max+1 above is race-prone
     * under concurrent creates (mirrors the hardened Invoice/Payment number helpers).
     */
    protected static function generateUniqueNumber(string $assetCode = 'GEN', ?\DateTimeInterface $date = null): string
    {
        $candidate = static::generateNumber($assetCode, $date);
        $prefix = substr($candidate, 0, (int) strrpos($candidate, '-') + 1);

        $attempts = 0;
        while (static::withTrashed()->where('number', $candidate)->exists()) {
            if (++$attempts > 100) {
                throw new \RuntimeException('Unable to allocate a unique deposit number after 100 attempts.');
            }
            $n = ((int) substr($candidate, strlen($prefix))) + 1;
            $candidate = $prefix.str_pad((string) $n, 4, '0', STR_PAD_LEFT);
        }

        return $candidate;
    }

    /**
     * Has anything been drawn against this lease's deposit — netted onto an invoice, refunded or
     * forfeited?
     *
     * The predicate behind the receipt freeze in `booted()`. Deliberately asked of the LEASE rather
     * than of this row: the deposit is a single pot per lease (`depositHeld()` sums every recorded
     * receipt against every draw), so a second receipt cannot be reduced either once the pot it
     * joined has been spent from. Keyed on the ORIGINAL lease so re-pointing a used receipt is
     * judged against the tenant it actually belongs to, not the one it is being moved to.
     */
    public function hasBeenDrawnOn(): bool
    {
        $leaseId = $this->getOriginal('lease_id') ?? $this->lease_id;

        if ($leaseId === null) {
            return false;
        }

        return DepositApplication::where('lease_id', $leaseId)->exists()
            || static::query()
                ->where('lease_id', $leaseId)
                ->whereIn('type', ['refund', 'forfeit'])
                ->where('status', 'recorded')
                ->exists();
    }

    protected static function booted(): void
    {
        static::saving(function (self $deposit) {
            $raw = $deposit->getAttributes()['amount'] ?? null;
            if ($raw === null || $raw === '') {
                $deposit->amount = 0;
            }

            // `method` is NOT NULL with a DB default of 'bank'. An explicit null OVERRIDES that
            // default and the insert fails — the recurring class of bug CLAUDE.md names (blank
            // optional field → null → NOT-NULL column), so coerce here rather than in each caller.
            // A forfeit has no payment method at all, and 'bank' is the harmless truth for it.
            if (blank($deposit->method)) {
                $deposit->method = 'bank';
            }

            // ── A receipt is fixed once the deposit has been DRAWN ON ────────────────────────
            // The held balance is derived (receipts − refunds − forfeits − applications), so this
            // module has no stored figure to drift. What it had instead was an editable window
            // that never closed: applying a deposit to an invoice writes a `DepositApplication`
            // and leaves the receipt `recorded`, and the comment below records the intent that
            // was only ever enforced on the form.
            //
            //   receive 10,000 → net 8,000 against the tenant's arrears
            //   edit the receipt down to 2,000
            //   depositHeld = 2,000 − 8,000 = −6,000
            //
            // The tenant's AR was settled by money the landlord no longer records receiving, the
            // move-out statement owes them a NEGATIVE deposit, and the receipt's GL entry
            // (Dr Cash / Cr Deposits Held) re-derives at the new figure while the application's
            // Dr Deposits Held does not move.
            //
            // Frozen only once something DEPENDS on it — a receipt keyed wrongly must stay fixable
            // until then, the same rule as the عهدة in module 25. `notes` carries no money and no
            // dimension, so it stays editable. (Deposits close-out, 2026-08-11.)
            if ($deposit->exists
                && $deposit->type === 'receipt'
                && $deposit->isDirty(['amount', 'lease_id', 'tenant_id', 'asset_id', 'transaction_date', 'type', 'status'])
                && $deposit->hasBeenDrawnOn()) {
                throw new \DomainException(__('admin.deposits.errors.receipt_in_use'));
            }

            // Derive tenant + asset from the lease so they can't drift — on create AND
            // when the lease is re-pointed on an existing deposit (the edit form leaves
            // lease_id editable while status is 'recorded').
            if ($deposit->lease_id && ($deposit->isDirty('lease_id') || $deposit->tenant_id === null || $deposit->asset_id === null)) {
                $lease = Lease::with('unit')->find($deposit->lease_id);
                $deposit->tenant_id = $lease?->tenant_id;
                $deposit->asset_id = $lease?->unit?->asset_id;
            }
        });

        static::creating(function (self $deposit) {
            if (empty($deposit->number)) {
                $assetCode = $deposit->asset_id
                    ? Asset::whereKey($deposit->asset_id)->value('code')
                    : null;
                $deposit->number = $deposit->allocateDocumentNumber(
                    static::numberPrefix($assetCode ?: 'GEN', $deposit->transaction_date),
                    fn (): string => static::generateUniqueNumber($assetCode ?: 'GEN', $deposit->transaction_date),
                );
            }
        });
    }
}
