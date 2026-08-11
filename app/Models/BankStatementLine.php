<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One row of a bank statement, as the bank wrote it.
 *
 * `amount` is SIGNED — positive in, negative out — rather than an amount plus a direction flag,
 * because two columns can contradict each other and a signed number cannot.
 */
class BankStatementLine extends Model
{
    use HasFactory;

    protected $fillable = [
        'bank_statement_id',
        'value_date',
        'description',
        'reference',
        'amount',
        'running_balance',
        'row_hash',
    ];

    protected $casts = [
        'value_date' => 'date',
        'amount' => 'decimal:2',
        'running_balance' => 'decimal:2',
    ];

    /**
     * Days since the bank moved this money. Only meaningful while the line is UNMATCHED — a matched
     * line is explained, and how long that took is not a question anyone is asking.
     *
     * The number the ageing exists for: a line unexplained for a month is not a backlog item, it is
     * a question nobody has asked. Money left the account and the books still cannot say why.
     */
    public function ageInDays(): int
    {
        return (int) $this->value_date->startOfDay()->diffInDays(now()->startOfDay());
    }

    /** Unmatched, and older than $days. The worklist an operator should be shown, not a filter. */
    public function scopeUnmatchedOlderThan(\Illuminate\Database\Eloquent\Builder $query, int $days): \Illuminate\Database\Eloquent\Builder
    {
        return $query
            ->whereDoesntHave('matches')
            ->whereDate('value_date', '<=', now()->subDays($days)->toDateString());
    }

    public function statement(): BelongsTo
    {
        return $this->belongsTo(BankStatement::class, 'bank_statement_id');
    }

    /** Several, because a bank can show one line for two cheques banked together. */
    public function matches(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(BankMatch::class, 'bank_statement_line_id');
    }

    /**
     * The identity of a statement row, for import idempotency: date + amount + reference +
     * description, plus an OCCURRENCE number.
     *
     * Re-importing an overlapping export is what operators actually do, so the same row arriving
     * twice must land on the same record. But a bank can legitimately issue two identical rows on
     * one day — two identical card fees, the same transfer sent twice — and hashing only the
     * content would silently collapse them into one, quietly losing money from the statement. The
     * occurrence number is what keeps a genuine duplicate importable while a re-import stays a
     * no-op: the Nth identical row in a file is always the Nth, run after run.
     */
    public static function hashFor(
        string $valueDate,
        float $amount,
        ?string $reference,
        ?string $description,
        int $occurrence = 1,
    ): string {
        return hash('sha256', implode('|', [
            $valueDate,
            number_format($amount, 2, '.', ''),
            mb_strtolower(trim((string) $reference)),
            mb_strtolower(trim((string) $description)),
            $occurrence,
        ]));
    }
}
