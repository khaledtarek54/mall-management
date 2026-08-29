<?php

namespace App\Support;

use App\Contracts\BillableAgreement;
use App\Models\RentableItem;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * The two pickers every rentable-item screen needs: what this agreement COULD take, and what it
 * currently holds.
 *
 * **Shared because the copies drifted.** These lists existed twice — once as private statics on
 * `LeaseActions`, once inside `LeaseRentableItemsRelationManager` — and by 2026-08-18 the two
 * assign forms had diverged: one picked the item with a plain `Select` over a raw column, the other
 * through the registry. The same act searched different things depending on which button you
 * pressed, and only one of them could find a bay by anything but its name. Consolidated here when
 * the holder became an agreement rather than a lease, because a third copy for unit ownerships
 * would have made it three ways to answer one question.
 *
 * Holder-agnostic on purpose: Voyager assigns rentable items to the customer RECORD
 * (`docs/benchmarks/yardi/09-yardi-space-and-parking.md` §2), which for a tenant is the lease and
 * for an owner-occupier is the ownership. Both arrive here as a `BillableAgreement`.
 */
class RentableItemOptions
{
    /**
     * Items this agreement could take: same property, in service, and free on the day.
     *
     * The availability test runs in PHP rather than as a subquery because "held on a date" is a
     * date-ranged predicate the model already owns (`RentableItem::isHeldOn`), and re-expressing it
     * in SQL beside the original is how the two come to disagree about a boundary day.
     *
     * @return array<int, string>
     */
    public static function lettable(BillableAgreement $holder): array
    {
        $assetId = $holder->assetId();

        if (! $assetId) {
            return [];
        }

        return RentableItem::query()
            ->where('asset_id', $assetId)
            ->where('status', '!=', RentableItem::STATUS_OUT_OF_SERVICE)
            ->orderBy('code')
            ->get()
            // The holder's OWN current holdings are excluded from the clash test, so re-assigning
            // a bay it already holds reads as "you have this" rather than "someone has this".
            ->reject(fn (RentableItem $item) => $item->isHeldOn(null, ignore: self::identity($holder)))
            // ── THE OPTION SAYS WHAT KIND OF THING IT IS (2026-08-28) ───────────────────────
            //
            // The label was the code and the rate. A mall lets bays, signage, storage and kiosks
            // from ONE list, so "SGN-A · EGP 8,000.00" told the operator which kind only through a
            // code they had chosen themselves — and a signage licence and a parking bay read
            // identically to anyone who inherited the register.
            //
            // The STATUS is deliberately not shown: this query already offers only what is lettable
            // — out-of-service excluded, anything currently held rejected — so every option is
            // available by construction, and printing "available" on all of them would be a column
            // of one value. Naming a status here would also invite the wrong question, which is
            // whether an occupied bay should be offered at all. It should not, and it is not.
            ->mapWithKeys(fn (RentableItem $item) => [
                $item->id => __('admin.rentable_items.option', [
                    // The SAME group the resource's own table and form label the type from — a second
                    // spelling here would drift from the screen the operator just came off.
                    'type' => __('admin.enums.rentable_item_type.'.$item->type),
                    'item' => $item->label(),
                    'rate' => 'EGP '.number_format((float) $item->monthly_rate, 2),
                ]),
            ])
            ->all();
    }

    /**
     * What this agreement holds right now, labelled with the NEGOTIATED rate.
     *
     * The negotiated figure, not the item's asking rate: what this holder actually pays is the only
     * number that reconciles with the `parking` charge on its schedule.
     *
     * @return array<int, string>
     */
    public static function held(BillableAgreement $holder): array
    {
        $identity = self::identity($holder);

        // Straight off the pivot table: the relation carries no declared pivot model to read
        // through, and it is one query either way.
        $rates = DB::table('rentable_item_holdings')
            ->where('holder_type', $identity['type'])
            ->where('holder_id', $identity['id'])
            ->whereNull('effective_to')
            ->pluck('monthly_rate', 'rentable_item_id');

        return $holder->rentableItems()
            ->wherePivotNull('effective_to')
            ->get()
            ->mapWithKeys(fn (RentableItem $item) => [
                $item->id => $item->label().' · EGP '.number_format((float) ($rates[$item->id] ?? 0), 2),
            ])
            ->all();
    }

    /**
     * The holder as the pivot stores it — a morph ALIAS and an id.
     *
     * Through `getMorphClass()` rather than `::class`, so the value matches what was written and
     * keeps matching if a class moves. `MorphMap` is what guarantees the alias is stable.
     *
     * @return array{type: string, id: int}
     */
    public static function identity(BillableAgreement $holder): array
    {
        /** @var Model $holder */
        return ['type' => $holder->getMorphClass(), 'id' => (int) $holder->getKey()];
    }
}
