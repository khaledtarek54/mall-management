<?php

namespace App\Models;

use App\Models\Concerns\AllocatesDocumentNumber;
use App\Models\Concerns\GuardsPostingDate;
use App\Models\Concerns\HasSearchText;
use App\Models\Concerns\RecordsBankAccount;
use App\Models\Concerns\RefusesDeletionOfCommittedRecords;
use App\Support\Attributes\NeverDeletable;
use App\Support\Attributes\PostingDateGuardedBy;
use App\Support\Attributes\PropertyOwned;
use App\Support\CostNature;
use App\Support\DocumentNumbering;
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
// A NULLABLE asset_id, and a null is portfolio-level overhead every property must still see
// — an operator-wide bill is not hidden because someone picked a mall. Declared, not implied:
// scoping this strictly would hide those rows from every screen and nothing would fail loudly.
#[PropertyOwned(portfolioRowsWhenNull: true)]
// Their Filament resource writes the model directly, so the model's save is the only
// choke point every path shares.
#[PostingDateGuardedBy(guard: Expense::class)]
class Expense extends Model
{
    use AllocatesDocumentNumber, RefusesDeletionOfCommittedRecords;
    use GuardsPostingDate, HasFactory, HasSearchText, LogsActivity, SoftDeletes;
    use RecordsBankAccount;

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
        return CostNature::forCategory($this->category);
    }

    /** Constrain a query to expenses of one cost nature (fixed|variable). */
    public function scopeOfNature(Builder $query, string $nature): Builder
    {
        // A never-matching sentinel when the nature is unknown, so a bad filter value shows
        // nothing rather than everything.
        return $query->whereIn('category', CostNature::categoriesOf($nature) ?: ['__none__']);
    }

    protected $fillable = [
        'bank_account_id',
        // Which JOB this cost belongs to — the other road into the service bucket.
        'facility_work_order_id',
        'number',
        'asset_id',
        // Which recurring schedule minted this cost, if any (EG-33). Null for an ad-hoc expense.
        'recurring_expense_id',
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

        return sprintf('%s-%s-%s', DocumentNumbering::prefixFor('expense'), $assetCode, DocumentNumbering::periodSegment($date));
    }

    public static function generateNumber(string $assetCode = 'GEN', ?\DateTimeInterface $date = null): string
    {
        $prefix = static::numberPrefix($assetCode, $date);

        $lastNumber = static::withTrashed()
            ->where('number', 'like', $prefix.'%')
            // LENGTH first: `orderByDesc('number')` alone is a STRING sort, so once a series passes
            // its zero-padding the shorter number sorts higher and MAX returns the wrong row.
            ->orderByRaw('LENGTH(number) DESC, number DESC')
            ->value('number');

        $next = $lastNumber ? ((int) substr($lastNumber, strlen($prefix))) + 1 : 1;

        return $prefix.str_pad((string) $next, 4, '0', STR_PAD_LEFT);
    }

    /**
     * The job this cost belongs to, for {@see FacilityWorkOrder::recomputeCosts()}.
     *
     * Named apart from any display relation so the costing hook cannot be broken by someone
     * renaming or re-scoping a relation that exists for something else.
     */
    public function workOrderForCosting(): ?FacilityWorkOrder
    {
        return $this->facility_work_order_id === null
            ? null
            : FacilityWorkOrder::find($this->facility_work_order_id);
    }

    protected static function booted(): void
    {

        // The work order is the cost object and `recomputeCosts()` is its single source of truth,
        // so every channel that changes what a job cost calls it — the same discipline that makes
        // every AR settlement channel call `Invoice::recomputeTotals()`. Missing one here would
        // leave a job quietly understating its cost, which is the failure nobody notices.
        static::saved(function (self $m) {
            $m->workOrderForCosting()?->recomputeCosts();

            // A document MOVED between jobs leaves the old one overstated, so the previous owner
            // recomputes too. `getOriginal()` still holds it inside `saved`.
            $was = $m->getOriginal('facility_work_order_id');
            if ($was !== null && (int) $was !== (int) $m->facility_work_order_id) {
                FacilityWorkOrder::find($was)?->recomputeCosts();
            }
        });

        static::deleted(fn (self $m) => $m->workOrderForCosting()?->recomputeCosts());
        static::restored(fn (self $m) => $m->workOrderForCosting()?->recomputeCosts());
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

            // `bank_account_id` belongs here with `paid_from`: it chooses the very same account the
            // credit leg lands in, only more precisely. Leaving it editable while `paid_from` is
            // refused would be one decision guarded and its stricter twin waved through.
            //
            // …EXCEPT when the expense is being RE-HOMED. `asset_id` is deliberately editable —
            // re-homing a correctly-keyed expense is a legitimate correction that does not restate
            // what was spent — and a bank account is `#[PropertyOwned]`, so moving the expense to
            // Mall B leaves it naming Mall A's account, which `RecordsBankAccount` then refuses.
            // Refusing the fix as well would make a recorded expense that names a bank account
            // IMPOSSIBLE to re-home: the move throws, and so does the only edit that would let the
            // move through. Two guards that are each right and together lock the door.
            //
            // So when the property moves the account may move with it, and only then — which is
            // not a loophole but the requirement: the credit leg has to land in an account of the
            // mall the expense now stands in. `RecordsBankAccount` still validates the NEW pairing
            // on the same save (it runs on `saving`, ahead of this), so a wrong pick is refused;
            // and clearing it to null is allowed too, which simply hands the choice back to the
            // rail. What stays refused is what was always meant to be: moving the credit between
            // banks on a document that is standing still.
            $reHoming = $expense->isDirty('asset_id');

            foreach (['amount', 'vat_amount', 'category', 'paid_from', 'bank_account_id'] as $field) {
                if ($field === 'bank_account_id' && $reHoming) {
                    continue;
                }

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
