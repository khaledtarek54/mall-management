<?php

namespace App\Models;

use App\Support\Attributes\DeletionAllowed;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * One rung of a {@see TaxCode}'s rate ladder — a rate, and the day it came into force.
 *
 * A rung stays in force until the next one starts; there is no end date, because a from/to pair
 * makes overlapping and missing windows representable and this system has already been bitten by
 * exactly that on charge schedules (see the migration).
 *
 * **Editing a rung that is already in force is allowed and safe.** Issued documents carry their own
 * `vat_rate` and are never re-rated, so an edit changes what is billed NEXT and nothing that has
 * been billed. That is the same rule the whole money core runs on, and it is why this is not a
 * `NEVER_DELETABLE` record: a rung posts nothing and settles nothing.
 *
 * It is activity-logged all the same. "Who moved the VAT rate, when, and from what" is the first
 * question an auditor asks about a tax figure, and until this table existed the answer was that
 * nobody recorded it.
 */
#[DeletionAllowed(reason: 'parent-managed: effective-dated rates on a tax code, edited from the code')]
class TaxRate extends Model
{
    use LogsActivity;

    protected $fillable = [
        'tax_code_id',
        'rate',
        'effective_from',
        'note',
    ];

    protected $casts = [
        'rate' => 'decimal:3',
        'effective_from' => 'immutable_date',
    ];

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['tax_code_id', 'rate', 'effective_from', 'note'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges()
            ->useLogName('tax_rate');
    }

    protected static function booted(): void
    {
        // The parent memoizes the whole ladder, and a rung written here fires no event on the
        // parent — so without this a rate change would keep billing the old figure for the rest of
        // the request.
        static::saved(fn () => TaxCode::flushLookupCaches());
        static::deleted(fn () => TaxCode::flushLookupCaches());
    }

    /** @return BelongsTo<TaxCode, $this> */
    public function taxCode(): BelongsTo
    {
        return $this->belongsTo(TaxCode::class);
    }
}
