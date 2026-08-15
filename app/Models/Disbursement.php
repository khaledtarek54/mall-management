<?php

namespace App\Models;

use App\Models\Concerns\HasSearchText;
use App\Models\Concerns\RefusesDeletionOfCommittedRecords;
use App\Support\Attributes\NeverDeletable;
use App\Support\Attributes\PostingDateGuardedBy;
use App\Support\Attributes\PropertyOwned;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * صرف مستحقات المالك — a payout against a finalised owner statement. Paying an owner clears
 * the Due-to-Owner liability the statement accrued (Dr Due to Owner / Cr Bank). Partial
 * payouts are allowed; the running total is capped at the owner's share in the service.
 * A paid or cancelled disbursement is terminal-immutable.
 */
#[NeverDeletable(correction: 'cancel the disbursement — it is a GL source and an owner payout')]
// owner payout; asset_id denormalized (journalizer reads own row)
#[PropertyOwned]
#[PostingDateGuardedBy(guard: \App\Services\OwnerAccounting\DisbursementService::class)]
class Disbursement extends Model
{
    use RefusesDeletionOfCommittedRecords, HasFactory, HasSearchText, LogsActivity, SoftDeletes;

    public const STATUS_SCHEDULED = 'scheduled';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_PAID = 'paid';
    public const STATUS_CANCELLED = 'cancelled';
    public const STATUSES = [self::STATUS_SCHEDULED, self::STATUS_APPROVED, self::STATUS_PAID, self::STATUS_CANCELLED];

    public const METHOD_BANK_TRANSFER = 'bank_transfer';
    public const METHOD_CHEQUE = 'cheque';
    public const METHOD_CASH = 'cash';
    public const METHODS = [self::METHOD_BANK_TRANSFER, self::METHOD_CHEQUE, self::METHOD_CASH];

    protected $fillable = [
        'reference',
        'owner_statement_id',
        'asset_id',
        'user_id',
        'amount',
        'method',
        'required_permission',
        'status',
        'approved_by_user_id',
        'approved_at',
        'paid_on',
        'external_reference',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'approved_at' => 'datetime',
        'paid_on' => 'date',
    ];

    protected $attributes = [
        'amount' => 0,
        'method' => self::METHOD_BANK_TRANSFER,
        'status' => self::STATUS_SCHEDULED,
    ];

    /**
     * Our payout reference and the bank's external reference.
     *
     * @return array<int, string|int|float|null>
     */
    public function searchTextSources(): array
    {
        return [
            $this->reference,
            $this->external_reference,
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (self $d) {
            if (! in_array($d->status, self::STATUSES, true)) {
                throw new \InvalidArgumentException("Invalid disbursement status '{$d->status}'.");
            }
            if (! in_array($d->method, self::METHODS, true)) {
                throw new \InvalidArgumentException("Invalid disbursement method '{$d->method}'.");
            }
        });

        // Terminal-immutable: a paid/cancelled disbursement's money-material fields never change
        // (soft-delete/restore, which only touch deleted_at, are still allowed).
        static::updating(function (self $d) {
            $original = $d->getOriginal('status');
            if (in_array($original, [self::STATUS_PAID, self::STATUS_CANCELLED], true)
                && ($d->isDirty('status') || $d->isDirty('amount') || $d->isDirty('paid_on'))) {
                throw new \DomainException("A {$original} disbursement is immutable.");
            }
        });
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['reference', 'owner_statement_id', 'user_id', 'amount', 'status', 'paid_on', 'external_reference'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges()
            ->useLogName('disbursement');
    }

    public static function generateReference(): string
    {
        $year = now()->format('Y');
        $count = static::whereYear('created_at', $year)->withTrashed()->count() + 1;

        $candidate = sprintf('DISB-%s-%04d', $year, $count);
        $attempts = 0;
        while (static::withTrashed()->where('reference', $candidate)->exists() && $attempts < 50) {
            $candidate = sprintf('DISB-%s-%04d', $year, ++$count);
            $attempts++;
        }

        return $candidate;
    }

    public function isPaid(): bool
    {
        return $this->status === self::STATUS_PAID;
    }

    public function ownerStatement(): BelongsTo
    {
        return $this->belongsTo(OwnerStatement::class);
    }

    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class);
    }

    public function payee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by_user_id');
    }
}
