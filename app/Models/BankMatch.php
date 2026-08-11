<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * One statement line explained by one book posting — slice 3 of bank reconciliation.
 *
 * Annotation only: creating or removing a match posts nothing and changes no balance.
 *
 * @see docs/accounting/BANK-RECONCILIATION-PLAN.md
 */
class BankMatch extends Model
{
    use HasFactory, LogsActivity;

    protected $fillable = [
        'bank_statement_line_id',
        'journal_line_id',
        'matched_by_user_id',
        'matched_at',
        'notes',
    ];

    protected $casts = [
        'matched_at' => 'datetime',
    ];

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['bank_statement_line_id', 'journal_line_id', 'matched_at'])
            ->dontLogEmptyChanges()
            ->useLogName('bank_match');
    }

    public function statementLine(): BelongsTo
    {
        return $this->belongsTo(BankStatementLine::class, 'bank_statement_line_id');
    }

    public function journalLine(): BelongsTo
    {
        return $this->belongsTo(JournalLine::class, 'journal_line_id');
    }
}
