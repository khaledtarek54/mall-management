<?php

namespace App\Models;

use App\Support\ActivityLogging;
use App\Support\Attributes\DeletionAllowed;
use App\Support\Attributes\PropertyOwned;
use DomainException;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * One period of one bank account, as the BANK reports it — slice 2 of bank reconciliation.
 *
 * Evidence, not accounting: importing a statement posts nothing and moves no balance. Its value is
 * precisely that it comes from outside the system — `billing:reconcile` re-derives the books from
 * the documents, so it agrees with a wrong document; only the bank can disagree.
 *
 * @see docs/accounting/BANK-RECONCILIATION-PLAN.md
 */
#[DeletionAllowed(reason: 'evidence: re-import the statement')]
// reaches its property through the account it belongs to
#[PropertyOwned(via: 'bankAccount')]
class BankStatement extends Model
{
    use HasFactory, LogsActivity, SoftDeletes;

    protected $fillable = [
        'bank_account_id',
        'period_start',
        'period_end',
        'opening_balance',
        'closing_balance',
        'source_filename',
        'imported_by_user_id',
        'notes',
    ];

    protected $casts = [
        'period_start' => 'date',
        'period_end' => 'date',
        'opening_balance' => 'decimal:2',
        'closing_balance' => 'decimal:2',
    ];

    public function getActivitylogOptions(): LogOptions
    {
        return ActivityLogging::for($this, 'bank_statement');
    }

    /**
     * **A statement whose lines are already MATCHED cannot be moved to another bank account.**
     *
     * `MatchBankStatementLineService::match()` refuses to link a line to a posting that is not on
     * the statement's own bank chart account — *"matching across accounts would reconcile one bank
     * with another bank's money and still balance, the failure the whole module exists to
     * prevent"*. Editing the statement afterwards walked straight past that: `BankStatementForm`
     * is the resource's form on Edit as well as Create, so `bank_account_id` is an ordinary
     * dropdown on a statement that has already been reconciled, and nothing re-asked the question.
     *
     * Measured at HEAD (2026-09-04): with one line matched to a CIB posting,
     * `$statement->update(['bank_account_id' => $nbe->id])` was accepted, and BOTH reconciliations
     * are then wrong in opposite directions and both silently —
     *
     *  - the CIB posting still carries its `BankMatch`, so `ReconcileBankStatementService` drops it
     *    from CIB's *"in the books, not on the statement"* items. Money the books know about that
     *    the bank has not shown disappears from the one list that exists to name it, and the
     *    identity that is supposed to leave the unexplained remainder visible balances without it;
     *  - the line counts as explained on NBE's statement, by a posting that never touched NBE.
     *
     * Only on a DIRTY write, and only while a match exists. An operator who imported a statement
     * under the wrong account and has matched nothing must still be able to correct it — that is
     * the ordinary case, and guarding a row a workflow legitimately touches breaks the workflow
     * instead of protecting it. It is also the escape the refusal names.
     */
    protected static function booted(): void
    {
        static::updating(function (self $statement): void {
            if (! $statement->isDirty('bank_account_id')) {
                return;
            }

            $matched = BankStatementLine::query()
                ->where('bank_statement_id', $statement->getKey())
                ->whereHas('matches')
                ->count();

            if ($matched === 0) {
                return;
            }

            throw new DomainException(__('admin.refusals.bank_statement_rehomed_after_matching', [
                'count' => $matched,
                'account' => BankAccount::withTrashed()
                    ->find($statement->getOriginal('bank_account_id'))?->name ?? '',
            ]));
        });
    }

    public function bankAccount(): BelongsTo
    {
        return $this->belongsTo(BankAccount::class);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(BankStatementLine::class);
    }

    /** Σ of the imported lines. */
    public function movement(): float
    {
        return round((float) $this->lines()->sum('amount'), 2);
    }

    /**
     * Does the bank's own arithmetic hold — opening + Σ lines = closing?
     *
     * The first thing to check after an import and the cheapest possible signal that a CSV was
     * partially mapped, truncated, or had its sign convention read backwards. It says nothing about
     * the books; it says the statement was ingested faithfully, which is the precondition for
     * everything in slice 3.
     */
    public function isSelfConsistent(): bool
    {
        return abs(round((float) $this->opening_balance + $this->movement() - (float) $this->closing_balance, 2)) < 0.005;
    }

    /** How many of this statement's lines have gone unexplained for longer than $days. */
    public function agedUnmatchedCount(int $days = 30): int
    {
        // Through the query builder rather than the relation, so static analysis can see the scope
        // — a HasMany forwards it at runtime but does not declare it.
        return BankStatementLine::query()
            ->where('bank_statement_id', $this->getKey())
            ->unmatchedOlderThan($days)
            ->count();
    }

    public function label(): string
    {
        return $this->period_start->format('d/m/Y').' – '.$this->period_end->format('d/m/Y');
    }
}
