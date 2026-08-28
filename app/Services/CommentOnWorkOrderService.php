<?php

namespace App\Services;

use App\Models\FacilityWorkOrder;
use App\Models\FacilityWorkOrderComment;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

/**
 * **Add one message to a work order's thread.**
 *
 * A single-action service rather than a `create()` at the call site, for the reason the vendor
 * portal makes concrete: there will be **two authors and two panels** — staff in `/admin` and, at
 * step 3 of `docs/modules/12b-VENDOR-PORTAL-DESIGN.md`, a contractor in `/vendor`. Two code paths
 * writing the same row is how the two come to disagree about who may write and when, which §9 of
 * that design names as the risk worth avoiding ("a second place to be wrong").
 *
 * **A terminal job's thread is closed**, mirroring `TenantRequestService::comment()`. A done or
 * cancelled work order is immutable — CLAUDE.md states it for the module — and a thread that
 * accepted messages afterwards would be the one mutable part of a record an auditor reads as
 * settled. Raised as a `ValidationException` rather than a `DomainException` so it renders on the
 * field in a Filament modal, which is what its tenant-side twin does.
 */
class CommentOnWorkOrderService
{
    public function comment(
        FacilityWorkOrder $order,
        Model $author,
        string $body,
        bool $isInternal = false,
    ): FacilityWorkOrderComment {
        if ($order->isTerminal()) {
            throw ValidationException::withMessages([
                'body' => [__('admin.facility.comments.closed')],
            ]);
        }

        $body = trim($body);

        if ($body === '') {
            throw ValidationException::withMessages([
                'body' => [__('admin.facility.comments.empty')],
            ]);
        }

        return FacilityWorkOrderComment::create([
            'facility_work_order_id' => $order->getKey(),
            // The morph ALIAS, from the model itself — never `$author::class`. `MorphMap` enforces
            // an alias for every mapped class and throws on an unmapped one, which is the check
            // that keeps a renamed class from stranding rows.
            'author_type' => $author->getMorphClass(),
            'author_id' => $author->getKey(),
            'body' => $body,
            'is_internal' => $isInternal,
        ]);
    }
}
