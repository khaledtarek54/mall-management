<?php

namespace App\Models;

use App\Support\Attributes\DeletionAllowed;
use App\Support\Attributes\PortfolioShared;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Which saved view one operator's list opens on — see the migration for why it is a row.
 *
 * `table_view_id` is where they start; **NULL is an answer, not an absent one**: "open this list
 * plainly, whatever the team default says". That distinction is the whole reason this table
 * exists rather than a flag, because a person with no view of their own previously had nowhere to
 * record it and could not escape a team default at all.
 *
 * Not activity-logged, exactly like `ReportPreference` which it is modelled on: this changes what
 * one person SEES, never what the system does or what anyone is charged, and logging every
 * preference change would bury the money trail the activity log exists for.
 */
#[DeletionAllowed(reason: 'preference: which saved view one operator\'s list opens on')]
// Belongs to the USER, not a property. The stored view may itself be property-scoped, but where
// somebody's list opens is not an ownership claim about a mall.
#[PortfolioShared]
class TableViewDefault extends Model
{
    protected $fillable = ['user_id', 'resource', 'table_view_id'];

    /**
     * Any write invalidates the request memo behind {@see TableView::defaultFor()}.
     *
     * Not an optimisation detail — in Livewire the action that sets a default and the re-render
     * that draws the menu are the SAME request, so a memo that survived the write would show the
     * operator their previous answer immediately after they changed it.
     */
    protected static function booted(): void
    {
        $flush = fn () => TableView::flushDefaultMemo();

        static::saved($flush);
        static::deleted($flush);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<TableView, $this> */
    public function tableView(): BelongsTo
    {
        return $this->belongsTo(TableView::class);
    }
}
