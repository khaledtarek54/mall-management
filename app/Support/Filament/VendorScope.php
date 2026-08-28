<?php

namespace App\Support\Filament;

use App\Models\FacilityWorkOrder;
use App\Models\VendorContact;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;

/**
 * **The one design rule of the vendor portal, in one place.**
 *
 * > A contractor may only ever see or touch a job that has been DISPATCHED to them.
 *
 * Not their vendor record, not the property, not other contractors' work. Everything in the portal
 * is a consequence of this, and it is what the security model is tested against
 * (`docs/modules/12b-VENDOR-PORTAL-DESIGN.md` §2, §5).
 *
 * **One narrowing does the work today, and the second is a floor for tomorrow — say which.**
 *
 *  - `vendor_id` is the whole of the enforcement: a job cannot carry this contractor's id without
 *    having been dispatched to them.
 *  - {@see VISIBLE_STATUSES} is an ALLOWLIST that currently equals every status
 *    `FacilityWorkOrder::STATUSES` defines, so **it narrows nothing at present.** The design (§5)
 *    asked for "never a draft" — and the model has no pre-dispatch status to exclude, which is worth
 *    knowing rather than implying. Its value is directional: a status added later is invisible to
 *    contractors until somebody adds it here deliberately, which is the safe default for a list a
 *    third party reads. `AContractorSeesOnlyTheirOwnJobsTest` pins that property, because a constant
 *    that merely restates another constant is one a future reader will delete.
 *
 * The first draft of this docblock called both narrowings "load-bearing". Only one is.
 *
 * **`asset_id` is deliberately absent.** `docs/PROPERTY-ISOLATION.md` does not apply here: a
 * contractor is not scoped to a property, they are scoped to their DISPATCHES, which may span malls.
 * Adding a property scope would silently hide half a contractor's work the day they are used at a
 * second mall.
 *
 * **A narrowed query is not a gate.** Every action must re-check ownership server-side, because the
 * Livewire payload still carries an id — the same rule the admin panel states for `visible()`. Use
 * {@see owns()} for that, and 404 rather than 403: a 403 confirms the job exists.
 */
class VendorScope
{
    /**
     * The statuses a contractor may see. `done` and `cancelled` are included deliberately — a
     * contractor must be able to read back what they did, which is what makes the thread and the
     * evidence useful after the fact.
     *
     * Equal to every status the model defines TODAY. See the class docblock: it narrows nothing yet
     * and exists so a NEW status is hidden from contractors until someone decides otherwise.
     *
     * @var array<int, string>
     */
    public const VISIBLE_STATUSES = ['open', 'in_progress', 'done', 'cancelled'];

    /** The signed-in contractor's contact, or null when nobody is signed in on the vendor guard. */
    public static function contact(): ?VendorContact
    {
        $user = Auth::guard('vendor')->user();

        return $user instanceof VendorContact ? $user : null;
    }

    /**
     * Narrow a work-order query to the signed-in contractor's dispatches.
     *
     * With nobody signed in this returns a query that matches NOTHING rather than everything —
     * `whereRaw('1 = 0')` — because the failure direction matters: a scope that silently widens when
     * the guard is empty is how a portal leaks its whole table to an unauthenticated request.
     */
    public static function jobs(?Builder $query = null): Builder
    {
        $query ??= FacilityWorkOrder::query();
        $contact = self::contact();

        if (! $contact) {
            return $query->whereRaw('1 = 0');
        }

        return $query
            ->where('vendor_id', $contact->vendor_id)
            ->whereIn('status', self::VISIBLE_STATUSES);
    }

    /**
     * **The gate**, asked of one record. A narrowed list is UI; this is what a crafted Livewire
     * payload meets.
     */
    public static function owns(?FacilityWorkOrder $order): bool
    {
        $contact = self::contact();

        return $order !== null
            && $contact !== null
            && (int) $order->vendor_id === (int) $contact->vendor_id
            && in_array($order->status, self::VISIBLE_STATUSES, true);
    }

    /**
     * Refuse a record that is not this contractor's — **404, never 403**, per the `/api/v1` rule: a
     * 403 confirms the job exists, which is itself a disclosure to someone who should not know.
     */
    public static function assertOwned(?FacilityWorkOrder $order): FacilityWorkOrder
    {
        abort_unless(self::owns($order), 404);

        return $order;
    }
}
