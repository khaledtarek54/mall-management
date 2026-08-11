<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * طرف القيد — one debit (مدين) or credit (دائن) line of a journal entry.
 * Exactly one of debit/credit is > 0. Carries optional analytical dimensions.
 */
class JournalLine extends Model
{
    use HasFactory;

    protected $fillable = [
        'journal_entry_id',
        'ledger_account_id',
        'debit',
        'credit',
        'description',
        'asset_id',
        'tenant_id',
        'lease_id',
    ];

    protected $casts = [
        'debit' => 'decimal:2',
        'credit' => 'decimal:2',
    ];

    /**
     * True only while {@see JournalPostingService} is assembling an entry.
     *
     * `post()` inserts the entry ALREADY `posted` and then writes its lines, so the immutability
     * guard below would refuse the very act of posting. Rather than relax the rule to "created
     * lines are fine" — which would leave "add a line to a posted entry", the whole
     * unbalanced-ledger case, wide open — the one component allowed to write them says so.
     *
     * Deliberately narrow and greppable: `JournalLine::withinPostingEngine()` has exactly one
     * caller, and anything else writing a line onto a posted entry is refused.
     */
    protected static bool $withinPostingEngine = false;

    /**
     * Run `$build` with the posted-entry line guard suspended — for the posting engine only.
     *
     * @template T
     *
     * @param  callable(): T  $build
     * @return T
     */
    public static function withinPostingEngine(callable $build)
    {
        self::$withinPostingEngine = true;

        try {
            return $build();
        } finally {
            self::$withinPostingEngine = false;
        }
    }

    protected static function booted(): void
    {
        // ── A line on a POSTED entry is permanent ──────────────────────────────────────────────
        // This is the unbalanced-ledger case, and the reason it is worse here than anywhere else
        // in the 2026-08-11 sweep: re-price a line, add one, or remove one, and debits stop
        // equalling credits. The trial balance stops balancing and every report built on it — the
        // balance sheet, the P&L, the owner statements — is wrong at once, with nothing naming
        // which entry did it. `JournalPostingService::assertBalanced()` runs when the entry is
        // POSTED and never again; the only thing standing here was a hidden Save button on
        // `EditJournalEntry`.
        //
        // Re-homing a line (`ledger_account_id`) is the quieter half: the entry still balances
        // while an amount has moved between accounts with nothing recording that it happened.
        //
        // Draft entries stay fully editable — a draft is not on the books, which is the whole
        // distinction the status carries. Correct a posted entry by voiding it (a balanced
        // reversing entry) and posting a fresh one. (Module 21 close-out.)
        $assertEntryIsDraft = function (self $line) {
            if (self::$withinPostingEngine) {
                return;
            }

            $entry = $line->entry;

            if ($entry !== null && $entry->status !== 'draft') {
                throw new \DomainException(__('admin.journal_entries.errors.posted_line_immutable'));
            }
        };

        static::saving($assertEntryIsDraft);
        static::deleting($assertEntryIsDraft);

        // debit/credit are NOT-NULL with a default of 0; a cleared form field can
        // send '' / null. Coerce to 0 so a blank line never violates the constraint
        // (the meter_readings.cost / leases.has_percentage_rent bug class).
        static::saving(function (self $line) {
            foreach (['debit', 'credit'] as $column) {
                $value = $line->getAttribute($column);
                if ($value === null || $value === '') {
                    $line->setAttribute($column, 0);
                }
            }
        });
    }

    /**
     * The bank-reconciliation match, if this posting has been explained by a statement line.
     * At most one — matching a book posting twice would report the same money verified twice.
     */
    public function bankMatch(): \Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(BankMatch::class, 'journal_line_id');
    }

    public function entry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class, 'journal_entry_id');
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(LedgerAccount::class, 'ledger_account_id');
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
}
