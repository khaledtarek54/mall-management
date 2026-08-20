<?php

namespace App\Models;

use App\Support\Attributes\DeletionAllowed;
use App\Support\Attributes\PropertyOwned;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * محطة في جولة — one machine on a service plan's route.
 *
 * **Maximo §6.** A route is an ordered list of machines covered by one visit: *"inspect all 42 fire
 * extinguishers on level 2"*. Before this a plan targeted ONE machine, so an operator either created
 * 42 plans or one plan whose checklist had 42 lines — and a line reading "Extinguisher 2-17 — fail"
 * is a string, so no report could say which devices were overdue and 2-17's own history stayed empty
 * however many times it failed.
 *
 * A stop is a MACHINE specifically. A round over areas is a different shape and the plan already
 * carries `area_id` for it; three nullable targets here would repeat an ambiguity rather than
 * resolve one.
 */
#[DeletionAllowed(reason: 'a stop is the route itself — removing a machine from a round is ordinary maintenance of the plan, and the work orders it already generated keep their own items')]
#[PropertyOwned(via: 'servicePlan')]
class ServicePlanStop extends Model
{
    use HasFactory;

    protected $fillable = ['service_plan_id', 'equipment_id', 'sort_order', 'note'];

    protected $casts = ['sort_order' => 'integer'];

    public function servicePlan(): BelongsTo
    {
        return $this->belongsTo(ServicePlan::class);
    }

    public function equipment(): BelongsTo
    {
        return $this->belongsTo(Equipment::class);
    }
}
