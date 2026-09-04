<?php

namespace App\Models;

use App\Support\ActivityLogging;
use App\Support\Attributes\DeletionAllowed;
use App\Support\Attributes\PortfolioShared;
use DomainException;
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
#[DeletionAllowed(reason: 'parent-managed: effective-dated rates on a tax code, edited from the code — except the LAST rung of a live standard code, which the model refuses because emptying the ladder silently re-rates billing to the VAT floor')]
// a rung on a TaxCode's dated ladder; shared for the same reason as its parent
#[PortfolioShared]
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
        return ActivityLogging::for($this, 'tax_rate');
    }

    protected static function booted(): void
    {
        // A LIVE code must keep at least one rung, and nothing had ever asked the question in this
        // direction. Measured 2026-09-04 against HEAD, with the seeded catalogue: delete the only
        // rung of `STAMP_20` (active, standard, 20%) and `TaxCode::rateOn()` answers null, so
        // `Vat::rateForType()` falls past its own floor into `standardRate()` — every supply under
        // that code originates at the 14% VAT rate instead of 20% stamp, from the next document on.
        // No error, no toast, and the code still reads "active" on the screen it was emptied from.
        //
        // `TaxCode::assertCanBeActivated()` is the same rule at the other end, and both now ask
        // `needsARateLadder()` so the pair cannot drift.
        //
        // **Excluding this row is the whole guard.** During `deleting` the rung is still in the
        // table, so a bare `$code->rates()->doesntExist()` is false every time and refuses nothing.
        static::deleting(function (self $rung): void {
            $code = $rung->taxCode;

            if ($code === null || ! $code->needsARateLadder()) {
                return;
            }

            if ($code->rates()->whereKeyNot($rung->getKey())->doesntExist()) {
                throw new DomainException(__('admin.validation.tax_code_last_rate', ['code' => $code->code]));
            }
        });

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
