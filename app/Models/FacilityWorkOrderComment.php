<?php

namespace App\Models;

use App\Support\ActivityLogging;
use App\Support\Attributes\DeletionAllowed;
use App\Support\Attributes\PropertyOwned;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * **One message on a work order's thread** — the primitive `TenantRequest` has had since module 11
 * and the work order did not.
 *
 * Before this, a job's conversation lived in `facility_work_orders.notes`: a single field, no
 * author, no time, last writer wins. The things people actually need to record about a job —
 * access arranged for Sunday, part on back-order, the tenant refused entry — either overwrote each
 * other or never got written down.
 *
 * **`is_internal` is the column the vendor portal rests on.** It is what lets an operator write
 * something a contractor must not read, and without it the portal's one design rule ("a contractor
 * may only ever see or touch a job dispatched to them") could not extend to the thread. Modelled on
 * `TenantRequestComment` deliberately — same shape, same flag, same failure modes already found.
 *
 * @see docs/modules/12b-VENDOR-PORTAL-DESIGN.md §7
 */
#[DeletionAllowed(reason: 'parent-managed: belongs to its work order, and cascades with it')]
#[PropertyOwned(via: 'workOrder')]
class FacilityWorkOrderComment extends Model
{
    use LogsActivity;

    protected $fillable = [
        'facility_work_order_id',
        'author_type',
        'author_id',
        'body',
        'is_internal',
    ];

    protected $casts = [
        'is_internal' => 'boolean',
    ];

    public function workOrder(): BelongsTo
    {
        return $this->belongsTo(FacilityWorkOrder::class, 'facility_work_order_id');
    }

    /**
     * Three kinds of party write here — staff, and (once the portal ships) a contractor's contact,
     * and potentially a tenant on a job raised from their request. Polymorphic columns store a
     * morph ALIAS, so never compare this against `::class`.
     */
    public function author(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * Audited, and `is_internal` is why. Flipping it PUBLISHES a staff note to someone outside the
     * company — a disclosure, not a cosmetic flag — which is the same reason the tenant thread gates
     * its own toggle rather than treating it as an ordinary edit.
     */
    public function getActivitylogOptions(): LogOptions
    {
        return ActivityLogging::for($this, 'facility_work_order_comment');
    }
}
