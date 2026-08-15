<?php

namespace App\Models;

use App\Support\Attributes\NeverDeletable;
use App\Support\Attributes\PropertyOwned;
use App\Support\DocumentNumbering;
use App\Models\Concerns\HasSearchText;
use App\Models\Concerns\RefusesDeletionOfCommittedRecords;
use App\Models\Concerns\GuardsPostingDate;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * مصروف مباشر — a direct / petty-cash expense paid immediately from cash or bank
 * (no vendor-payable stage). total is DERIVED = amount + VAT, enforced on every
 * write path so no path can persist total=0 (silent GL skip) or vat>total.
 */
#[NeverDeletable(correction: 'cancel the expense')]
#[PropertyOwned]
class Expense extends Model
{
    use RefusesDeletionOfCommittedRecords, \App\Models\Concerns\AllocatesDocumentNumber;

    use GuardsPostingDate, HasFactory, HasSearchText, LogsActivity, SoftDeletes;

    /**
     * Expense number, the external reference on the receipt, and what it was for.
     *
     * @return array<int, string|int|float|null>
     */
    public function searchTextSources(): array
    {
        return [
            $this->number,
            $this->reference,
            $this->description,
        ];
    }

    /** The column this document's GL entry is dated from (LedgerRealtimeSync::SOURCE_DATE_COLUMNS). */
    public static function postingDateColumn(): string
    {
        return 'expense_date';
    }

    public const CATEGORIES = ['maintenance', 'utilities', 'cleaning_security', 'marketing', 'admin', 'other'];

    /** This expense's fixed/variable cost nature (FR-FIN-02) — see App\Support\CostNature. */
    public function costNature(): string
    {
        return \App\Support\CostNature::forCategory($this->category);
    }

    /** Constrain a query to expenses of one cost nature (fixed|variable). */
    public function scopeOfNature(Builder $query, string $nature): Builder
    {
        // A never-matching sentinel when the nature is unknown, so a bad filter value shows
        // nothing rather than everything.
        return $query->whereIn('category', \App\Support\CostNature::categoriesOf($nature) ?: ['__none__']);
    }

    protected $fillable = [
        'number',
        'asset_id',
        'category',
        'reference',
        'description',
        'amount',
        'vat_amount',
        'tax_code',
        'tax_override_reason',
        'total',
        'paid_from',
        'expense_date',
        'status',
        'created_by_user_id',
    ];

    protected $casts = [
        'expense_date' => 'date',
        'amount' => 'decimal:2',
        'vat_amount' => 'decimal:2',
        'total' => 'decimal:2',
    ];

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['number', 'status', 'asset_id', 'category', 'amount', 'vat_amount', 'total', 'paid_from'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges()
            ->useLogName('expense');
    }

    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    /** Recognised on the GL unless cancelled. */
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

        return sprintf('%s-%s-%s-', DocumentNumbering::prefixFor('expense'), $assetCode, $date->format('Ym'));
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

    protected static function booted(): void
    {
        static::saving(function (self $expense) {
            // Coerce blank NOT-NULL money inputs to 0 — read the RAW attribute (a
            // decimal:2 cast throws MathException if '' is read through the getter).
            foreach (['amount', 'vat_amount'] as $column) {
                $raw = $expense->getAttributes()[$column] ?? null;
                if ($raw === null || $raw === '') {
                    $expense->{$column} = 0;
                }
            }

            // total is derived from amount + VAT — enforced on every write path.
            $expense->total = round((float) $expense->amount + (float) $expense->vat_amount, 2);
        });

        // A RECORDED expense is posted (Dr Expense / Cr Cash) the moment it exists — there is no
        // draft state — so its money fields are immutable from birth. The correction is to cancel
        // it and re-enter, which is what `cancel_expense` already does and what the vendor-bill
        // guard already says in almost these words.
        //
        // **Decided on the Yardi standard (2026-08-11).** Voyager does not let a posted payable be
        // edited; you reverse and re-enter. Atriom locked invoices, payments, credit notes and
        // vendor bills at finalisation and left the direct expense open — an asymmetry that was an
        // accident rather than a decision, and the one place where an operator could silently
        // re-derive a posted GL entry by typing over an amount. `App\Support\ChangeImpact` made it
        // visible; this closes it.
        //
        // `expense_date` and `asset_id` stay editable on purpose, exactly as on VendorBill: each
        // already carries its own guard (the posting-date check and `assertAssetInScope`), and
        // re-dating or re-homing a correctly-keyed expense is a legitimate correction that does not
        // restate what was spent. `status` stays open so cancelling still works.
        static::updating(function (self $expense) {
            if ($expense->getOriginal('status') !== 'recorded') {
                return; // an already-cancelled expense is terminal and guarded elsewhere
            }

            foreach (['amount', 'vat_amount', 'category', 'paid_from'] as $field) {
                if ($expense->isDirty($field)) {
                    throw new \DomainException(__('admin.errors.expense_immutable'));
                }
            }
        });

        static::creating(function (self $expense) {
            if (empty($expense->number)) {
                // Resolved once: the prefix that keys the lock and the sequence the
                // generator reads must be the same string, or the lock guards nothing.
                $assetCode = $expense->asset?->code ?: 'GEN';

                $expense->number = $expense->allocateDocumentNumber(
                    static::numberPrefix($assetCode, $expense->expense_date),
                    fn (): string => static::generateNumber($assetCode, $expense->expense_date),
                );
            }
        });
    }
}
