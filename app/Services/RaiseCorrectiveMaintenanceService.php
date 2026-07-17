<?php

namespace App\Services;

use App\Models\MaintenanceWorkOrder;
use App\Models\MaintenanceWorkOrderItem;
use App\Models\TenantRequest;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * Raises corrective-maintenance work orders (module 26, FR-CM-01/14/15).
 *
 * Two ways a CM comes into existence, and both produce a *linked* order rather than
 * mutating an existing one:
 *
 *   fromFailedCheck()  A PPM visit found a fault (FR-CM-01). The failed checklist item is
 *                      the trigger, and stays linked as the CM's provenance.
 *   asFollowUp()       An earlier order was closed but the fix was incomplete (FR-CM-14).
 *                      The client explicitly prefers a NEW linked order over reopening, so
 *                      the original's closure record survives for audit — which also keeps
 *                      the project's terminal-immutability rule intact rather than bending it.
 *
 * An ad-hoc CM with no trigger is just a work order created through the resource with
 * work_order_type = cm; it needs no service.
 */
class RaiseCorrectiveMaintenanceService
{
    /**
     * Raise a CM from a failed PPM check (FR-CM-01).
     *
     * @param  array{execution_type:string, description:string, title?:string, priority?:string,
     *               vendor_id?:int|null, assigned_to_user_id?:int|null, scheduled_for?:string|null}  $data
     *
     * @throws DomainException if the item did not fail, or already raised a CM
     */
    public function fromFailedCheck(MaintenanceWorkOrderItem $item, array $data): MaintenanceWorkOrder
    {
        return DB::transaction(function () use ($item, $data) {
            // Lock the parent order: the same aggregate root the checklist gate uses, so a
            // concurrent re-mark of this item can't race the CM being raised off it.
            /** @var MaintenanceWorkOrder $origin */
            $origin = MaintenanceWorkOrder::whereKey($item->maintenance_work_order_id)->lockForUpdate()->firstOrFail();
            $item->refresh();

            if (! $item->hasFailed()) {
                throw new DomainException(__('admin.preventive_maintenance.errors.cm_needs_failed_item'));
            }

            // One CM per failed check. Without this, every click of the action raises
            // another duplicate job for the same fault — and the action is on a table row,
            // which is exactly where a double-click lands.
            if (MaintenanceWorkOrder::where('source_item_id', $item->getKey())->exists()) {
                throw new DomainException(__('admin.preventive_maintenance.errors.cm_already_raised'));
            }

            return $this->create($origin, $data, [
                'source_item_id' => $item->getKey(),
                // filled(), not ??: the title field is optional, and a cleared TextInput
                // sends '' rather than null — which `??` passes straight through into a
                // NOT-NULL column, giving a CM with a blank title. Same bug class CLAUDE.md
                // flags for null (meter_readings.cost, leases.has_percentage_rent).
                'title' => filled($data['title'] ?? null) ? $data['title'] : $item->label,
            ]);
        });
    }

    /**
     * Raise a follow-up because an earlier order's fix was incomplete (FR-CM-14/15).
     *
     * Deliberately allowed on a *terminal* order — that is the whole point: the original
     * stays closed and immutable, and the new order carries the remaining work. It is also
     * allowed on a PPM order, since an incomplete preventive visit needs the same recourse.
     *
     * @param  array{execution_type:string, description:string, title?:string, priority?:string,
     *               vendor_id?:int|null, assigned_to_user_id?:int|null, scheduled_for?:string|null}  $data
     */
    public function asFollowUp(MaintenanceWorkOrder $original, array $data): MaintenanceWorkOrder
    {
        return DB::transaction(function () use ($original, $data) {
            /** @var MaintenanceWorkOrder $locked */
            $locked = MaintenanceWorkOrder::whereKey($original->getKey())->lockForUpdate()->firstOrFail();

            return $this->create($locked, $data, [
                'parent_work_order_id' => $locked->getKey(),
                'title' => filled($data['title'] ?? null) ? $data['title'] : $locked->title,
            ]);
        });
    }

    /**
     * Raise a CM from a tenant's reported fault (module 11 → 26 seam).
     *
     * The request supplies WHERE the work is — its unit (and thereby its property), category and
     * department are facts about the fault, not the engineer's choices — and the work order links
     * back to it. That link is what lets the request later be evidenced by "a linked work order"
     * (FR-USR-06), and what lets an operator trace a facility job to the tenant who reported it.
     *
     * A tenant request always has a unit (it is tenant-facing), so the property is never
     * ambiguous — unlike a common-area CM, which is exactly why work orders carry their own
     * asset_id rather than reading it through a request.
     *
     * @param  array{execution_type:string, description?:string, title?:string, priority?:string,
     *               vendor_id?:int|null, assigned_to_user_id?:int|null, scheduled_for?:string|null}  $data
     *
     * @throws DomainException if the request has no unit to locate the work against
     */
    public function fromTenantRequest(TenantRequest $request, array $data): MaintenanceWorkOrder
    {
        return DB::transaction(function () use ($request, $data) {
            /** @var TenantRequest $locked */
            $locked = TenantRequest::whereKey($request->getKey())->lockForUpdate()->firstOrFail();
            $locked->loadMissing('unit');

            // A request with no unit has no property to raise the work against. Tenant-facing
            // requests always have one; this guards the staff-channel case the schema now allows.
            if ($locked->unit === null) {
                throw new DomainException(__('admin.preventive_maintenance.errors.wo_needs_unit'));
            }

            $executionType = $data['execution_type'] ?? null;

            return MaintenanceWorkOrder::create([
                'work_order_type' => MaintenanceWorkOrder::TYPE_CM,
                'execution_type' => $executionType,
                'tenant_request_id' => $locked->getKey(),
                'asset_id' => $locked->unit->asset_id,
                'unit_id' => $locked->unit_id,
                'category' => $locked->category,
                'department_id' => $locked->department_id,
                'status' => 'open',
                // filled(), not ??, throughout — a cleared field sends '' which `??` waves past
                // into a NOT-NULL column (title/priority) or into Carbon::parse('') === now()
                // (scheduled_for). The description defaults to the tenant's own words.
                'title' => filled($data['title'] ?? null) ? $data['title'] : $locked->title,
                'priority' => filled($data['priority'] ?? null) ? $data['priority'] : ($locked->priority ?: 'medium'),
                'scheduled_for' => filled($data['scheduled_for'] ?? null) ? $data['scheduled_for'] : now()->toDateString(),
                'description' => filled($data['description'] ?? null) ? $data['description'] : $locked->description,
                'vendor_id' => $executionType === MaintenanceWorkOrder::EXECUTION_EXTERNAL ? ($data['vendor_id'] ?? null) : null,
                'assigned_to_user_id' => $executionType === MaintenanceWorkOrder::EXECUTION_INTERNAL ? ($data['assigned_to_user_id'] ?? null) : null,
            ]);
        });
    }

    /**
     * The shared shape of a raised CM: it inherits WHERE the work is (property, unit,
     * machine, category) from the order it came out of — those are facts about the fault,
     * not choices — while the caller supplies who/what/when.
     *
     * @param  array<string,mixed>  $data
     * @param  array<string,mixed>  $link
     */
    private function create(MaintenanceWorkOrder $origin, array $data, array $link): MaintenanceWorkOrder
    {
        $executionType = $data['execution_type'] ?? null;

        return MaintenanceWorkOrder::create(array_merge([
            'work_order_type' => MaintenanceWorkOrder::TYPE_CM,
            'execution_type' => $executionType,
            'asset_id' => $origin->asset_id,
            'unit_id' => $origin->unit_id,
            'equipment_id' => $origin->equipment_id,
            'category' => $origin->category,
            'department_id' => $origin->department_id,
            'status' => 'open',
            // FR-CM-06 — the tier that decides the SLA once the job is accepted. filled(),
            // like title: a blank select would otherwise write '' into a NOT-NULL enum.
            'priority' => filled($data['priority'] ?? null) ? $data['priority'] : 'medium',
            // filled(), for the same reason as title: a cleared DatePicker sends '', and
            // Carbon::parse('') resolves to *now* — so `??` would have silently produced
            // "today" while looking like it honoured the field.
            'scheduled_for' => filled($data['scheduled_for'] ?? null) ? $data['scheduled_for'] : now()->toDateString(),
            'description' => $data['description'] ?? null,
            // The XOR is enforced in the model; null the other side here so a caller passing
            // both doesn't just hit an exception it can't easily explain to the user.
            'vendor_id' => $executionType === MaintenanceWorkOrder::EXECUTION_EXTERNAL
                ? ($data['vendor_id'] ?? null)
                : null,
            'assigned_to_user_id' => $executionType === MaintenanceWorkOrder::EXECUTION_INTERNAL
                ? ($data['assigned_to_user_id'] ?? null)
                : null,
        ], $link));
    }
}
