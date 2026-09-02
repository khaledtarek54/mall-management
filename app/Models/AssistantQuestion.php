<?php

namespace App\Models;

use App\Support\Attributes\DeletionAllowed;
use App\Support\Attributes\PropertyOwned;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One question typed into the assistant, and what it matched.
 *
 * **Written by the machine, read by us.** Nobody edits a row here and no screen lists them in A0 —
 * the value is the aggregate: which questions were asked most, and which matched nothing. It is
 * deliberately NOT on the activity trail: that trail records what an operator CHANGED, and a
 * question changes nothing. Putting reads there would bury the writes it exists to show.
 *
 * **Not audited, for the same reason.** `ActivityLogging::for()` is for records a person edits.
 */
#[PropertyOwned]
#[DeletionAllowed(reason: 'Operational: a log of what was typed into a search box. It records no decision, no money and no obligation — it exists to be counted and then pruned, and `HousekeepingSettings` gives it a retention period like every other transient table.')]
class AssistantQuestion extends Model
{
    /** @use HasFactory<\Database\Factories\AssistantQuestionFactory> */
    use HasFactory;

    protected $fillable = [
        'conversation_id',
        'asset_id',
        'user_id',
        'question',
        'question_folded',
        'locale',
        'matched',
        'top_kind',
        'top_key',
        'top_score',
        'result_count',
        // Phase B. `create()` drops an unfillable key SILENTLY — the model answer vanished and the
        // spend ceiling read zero for ever, which is the same defect this codebase already records
        // for `recurring_expenses.recurring_expense_id`. Three tests caught it at once because the
        // budget is derived from these columns rather than from a counter.
        'model_answer',
        'model_input_tokens',
        'model_output_tokens',
        'was_helpful',
    ];

    /**
     * `matched` is NOT NULL with a default, and the cast is what keeps a blank away from it —
     * the class of bug this project has already been bitten by twice.
     */
    protected $attributes = [
        'matched' => false,
        'top_score' => 0,
        'result_count' => 0,
        'model_input_tokens' => 0,
        'model_output_tokens' => 0,
    ];

    protected function casts(): array
    {
        return [
            'matched' => 'boolean',
            'top_score' => 'integer',
            'result_count' => 'integer',
            'model_input_tokens' => 'integer',
            'model_output_tokens' => 'integer',
            'was_helpful' => 'boolean',
        ];
    }

    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * The questions nothing answered, most-asked first — the whole point of the table.
     *
     * @return \Illuminate\Database\Eloquent\Builder<static>
     */
    public function scopeUnanswered(\Illuminate\Database\Eloquent\Builder $query): \Illuminate\Database\Eloquent\Builder
    {
        return $query->where('matched', false);
    }
}
