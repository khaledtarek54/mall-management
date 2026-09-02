<?php

namespace App\Http\Controllers\Api\V1\UnitOwnerships;

use App\Http\Controllers\Api\V1\ApiController;
use App\Http\Resources\Api\V1\UnitOwnershipResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * GET /api/v1/me/unit-ownerships — the shops this party OWNS.
 *
 * Not paginated, for the same reason `/me/leases` and `/me/devices` are not: a handful of rows, and
 * a client that has to page to find out whether it is talking to an owner is a client that will not
 * bother.
 *
 * **Every ownership, not only the handed-over ones.** A `contracted` shop is one the party has
 * bought and not yet received, and it is precisely the state they most want a screen for; filtering
 * to `handed_over` would answer an empty list to somebody who is mid-purchase and make the app look
 * broken. `status` says which, and the client renders accordingly. A `transferred` one is history
 * they are entitled to — the same reasoning that keeps a `cancelled` invoice visible.
 */
class ListUnitOwnershipsController extends ApiController
{
    public function __invoke(Request $request): AnonymousResourceCollection
    {
        $ownerships = $request->user()
            ->unitOwnerships()
            ->with(['unit.floor', 'asset'])
            // Newest purchase first — an owner buying a second shop looks for the new one.
            ->orderByDesc('handover_date')
            ->orderByDesc('id')
            ->get();

        return UnitOwnershipResource::collection($ownerships);
    }
}
