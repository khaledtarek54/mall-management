<?php

namespace App\Models;

use App\Support\DocumentNumbering;
use App\Models\Concerns\HasSearchText;
use App\Models\Concerns\RefusesDeletionOfCommittedRecords;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * قيد يومية — a journal entry header. Its `lines` must balance
 * (Σ debit = Σ credit). Posted entries are immutable; to undo one you `void`
 * it (which posts a balanced reversing entry).
 */
class JournalEntry extends Model
{
    use RefusesDeletionOfCommittedRecords, \App\Models\Concerns\AllocatesDocumentNumber;

    use HasFactory, HasSearchText, LogsActivity, SoftDeletes;

    /**
     * The statuses that represent real, reportable movement — **`posted` AND `void`**.
     *
     * This is the single most counter-intuitive rule in the ledger, and getting it wrong does not
     * look like a bug. `JournalPostingService::void()` does **not** erase an entry: it posts a
     * sign-flipped **reversal** (status `posted`) and marks the original `void`, leaving the
     * original's lines in `journal_lines`, dated in their original period. That is deliberate — an
     * auditor must be able to see both the mistake and its correction.
     *
     * The consequence is that the pair nets to zero **only if both are counted**. Filter to
     * `posted` alone and you keep the reversal while dropping the original, so every correction
     * reads as `(new − original)` and every plain cancellation reads as a NEGATIVE.
     *
     * And this is not an edge case: `LedgerPoster::sync()` calls `void()` on every re-derive, so it
     * is the normal operating mode of a derived ledger. A cancelled 100,000 vendor bill drove the
     * CAM recovery basis to −100,000 and would have issued every tenant in the pool a credit note
     * for a share of money the landlord never over-collected.
     *
     * The rule lived as a private constant inside `LedgerReportService` while four other services
     * summed journal lines with their own `where('status', 'posted')`. It lives here now because
     * this is the model whose statuses they are, and because a rule that four callers need cannot
     * be private to one of them.
     *
     * **Not every `posted`-only filter is wrong.** "Has anything ever posted?", "find THE live
     * entry for this source", and the year-end close's own scan are all legitimately about posted
     * entries specifically. The test is whether you are SUMMING MONEY: if so, use this.
     */
    public const REPORTABLE_STATUSES = ['posted', 'void'];

    /**
     * Posted entries carrying no property — real money in no property's books.
     *
     * A null `asset_id` is a deliberate choice, not an accident: the journal-entry form offers it as
     * "consolidated", for an operator-level entry that belongs to no single mall. The problem is
     * what happens next. **Every owner statement is generated per asset**
     * (`GenerateOwnerStatementRunService` scopes `where('asset_id', $asset->id)`), so a consolidated
     * entry appears in NO statement — while the trial balance, which is portfolio-wide, still
     * balances. Revenue posted this way understates the owner's statement and nothing disagrees.
     *
     * Nothing counted them before, on any screen. This scope is what the Action Required card and
     * the journal table's filter both read, so the number, the list and the nag cannot disagree.
     *
     * @param  \Illuminate\Database\Eloquent\Builder<static>  $query
     * @return \Illuminate\Database\Eloquent\Builder<static>
     */
    public function scopeWithoutProperty(\Illuminate\Database\Eloquent\Builder $query): \Illuminate\Database\Eloquent\Builder
    {
        return $query->where('status', 'posted')->whereNull('asset_id');
    }

    protected $fillable = [
        'number',
        'entry_date',
        'accounting_period_id',
        'description_en',
        'description_ar',
        'source_type',
        'source_id',
        'is_manual',
        'is_closing',
        'status',
        'asset_id',
        'posted_by_user_id',
        'posted_at',
        'voided_at',
        'reversal_of_id',
    ];

    protected $casts = [
        'entry_date' => 'date',
        'is_manual' => 'boolean',
        'is_closing' => 'boolean',
        'posted_at' => 'datetime',
        'voided_at' => 'datetime',
    ];

    /**
     * Entry number and its bilingual narration — an accountant searches the narration.
     *
     * @return array<int, string|int|float|null>
     */
    public function searchTextSources(): array
    {
        return [
            $this->number,
            $this->description_en,
            $this->description_ar,
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['number', 'status', 'entry_date', 'asset_id', 'source_type', 'source_id'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges()
            ->useLogName('journal_entry');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(JournalLine::class);
    }

    public function period(): BelongsTo
    {
        return $this->belongsTo(AccountingPeriod::class, 'accounting_period_id');
    }

    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class);
    }

    public function postedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'posted_by_user_id');
    }

    public function source(): MorphTo
    {
        return $this->morphTo();
    }

    public function reversalOf(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reversal_of_id');
    }

    public function totalDebit(): float
    {
        return round((float) $this->lines->sum('debit'), 2);
    }

    public function totalCredit(): float
    {
        return round((float) $this->lines->sum('credit'), 2);
    }

    public function isBalanced(): bool
    {
        return abs($this->totalDebit() - $this->totalCredit()) < 0.005;
    }

    public function displayDescription(): string
    {
        $ar = (string) $this->description_ar;
        $en = (string) $this->description_en;

        return app()->getLocale() === 'ar'
            ? ($ar !== '' ? $ar : $en)
            : ($en !== '' ? $en : $ar);
    }

    /**
     * The number prefix for this document's sequence — ONE definition, used by generateNumber()
     * and by the allocation lock key (see AllocatesDocumentNumber). Two copies would drift, and a
     * lock keyed on a prefix that no longer matches the sequence it guards protects nothing.
     */
    public static function numberPrefix(?\DateTimeInterface $entryDate = null): string
    {
        $entryDate = $entryDate ? Carbon::instance($entryDate) : now();

        return sprintf('%s-%s-', DocumentNumbering::prefixFor('journal_entry'), $entryDate->format('Ym'));
    }

    public static function generateNumber(?\DateTimeInterface $entryDate = null): string
    {
        $prefix = static::numberPrefix($entryDate);

        $lastNumber = static::withTrashed()
            ->where('number', 'like', $prefix.'%')
            ->orderByDesc('number')
            ->value('number');

        $next = $lastNumber
            ? ((int) substr($lastNumber, strlen($prefix))) + 1
            : 1;

        return $prefix.str_pad((string) $next, 4, '0', STR_PAD_LEFT);
    }

    /**
     * What a POSTED entry may never change — see the `updating` guard in {@see booted()}.
     *
     * `status`, `voided_at`, `void_reason` and `updated_at` are deliberately absent: voiding is the
     * documented correction and must stay possible. Everything that decides what the books say is
     * here.
     *
     * @var array<int, string>
     */
    public const FROZEN_ONCE_POSTED = [
        'entry_date', 'asset_id', 'source_type', 'source_id',
        'is_manual', 'number', 'reversal_of_id', 'accounting_period_id',
    ];

    protected static function booted(): void
    {
        // ── A posted entry is permanent ────────────────────────────────────────────────────────
        // `JournalPostingService` validates an entry when it POSTS it — every line carries a debit
        // or a credit, the total is non-zero, debits equal credits to the half-piastre. Nothing
        // re-validated afterwards, and "a posted entry is permanent" was enforced by
        // `EditJournalEntry::getSaveFormAction()->visible(fn () => status === 'draft')` — a hidden
        // Save button. So the general ledger protected itself at layer 3, weakly, while every
        // module that posts into it was being made to protect its own documents at layer 1.
        //
        // `entry_date` is the one that travels furthest: it decides the PERIOD an entry belongs to,
        // so moving it walks the amount into another month — including one that has been closed,
        // reported and shown to an owner. That is the divergence `PostingDate` exists to stop,
        // arriving from inside the ledger rather than from a source document.
        //
        // Voiding stays available: it posts a balanced reversing entry (قيد عكسي) and is the
        // correction the module documents, so this refusal never traps anyone.
        // (Module 21 close-out, 2026-08-11.)
        static::updating(function (self $entry) {
            if (! in_array($entry->getOriginal('status'), ['posted', 'void'], true)) {
                return;
            }

            foreach (self::FROZEN_ONCE_POSTED as $field) {
                if ($entry->isDirty($field)) {
                    throw new \DomainException(__('admin.journal_entries.errors.posted_immutable'));
                }
            }

            // A posted entry may only ever move ON to void — never back to draft, and never
            // out of void.
            if ($entry->isDirty('status')
                && ! ($entry->getOriginal('status') === 'posted' && $entry->status === 'void')) {
                throw new \DomainException(__('admin.journal_entries.errors.posted_immutable'));
            }
        });

        static::creating(function (self $entry) {
            if (empty($entry->number)) {
                $entry->number = $entry->allocateDocumentNumber(
                    static::numberPrefix($entry->entry_date),
                    fn (): string => static::generateNumber($entry->entry_date),
                );
            }
            // is_manual is fully derived from the source link (no source = manual),
            // set in one place so the various creation paths can never disagree.
            $entry->is_manual = empty($entry->source_type);
        });
    }
    /**
     * A draft entry has not been posted, so nothing is on the books yet. Anything else — posted or
     * void — is permanent; correct it with a reversing entry.
     */
    public function isCommittedForDeletionPurposes(): bool
    {
        return $this->status !== 'draft';
    }

}
