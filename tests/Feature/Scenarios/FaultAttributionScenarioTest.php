<?php

use App\Models\MaintenanceWorkOrder;
use App\Models\MaintenanceWorkOrderPart;
use App\Services\AttributeWorkOrderFaultService;
use Database\Seeders\RolesPermissionsSeeder;

/**
 * FR-CM-12 / FR-CM-13 — who caused the failure, and who therefore bears the cost.
 *
 * The FRD's verbs are **determine** and **record**. Nothing here bills anyone: no requirement in
 * the FRD asks the system to invoice a tenant for a repair, and Khaled confirmed record-only
 * (2026-07-16). The recharge seam is documented-but-unbuilt in the service footer.
 */
beforeEach(function () {
    $this->seed(RolesPermissionsSeeder::class);
    $this->svc = app(AttributeWorkOrderFaultService::class);
    $this->asset = makeAsset(['code' => 'FLT']);
    $this->unit = makeUnit($this->asset, ['code' => 'U-1']);
    $this->tenant = makeTenant();
    makeLease($this->unit, $this->tenant);
    $this->manager = makeUser('manager', [$this->asset->id]);
});

function faultOrder(array $attrs = []): MaintenanceWorkOrder
{
    return MaintenanceWorkOrder::create(array_merge([
        'asset_id' => test()->asset->id,
        'unit_id' => test()->unit->id,
        'work_order_type' => 'cm',
        'execution_type' => 'internal',
        'description' => 'Aircon dripping into the shop',
        'title' => 'Fix aircon',
        'category' => 'hvac',
        'scheduled_for' => '2026-07-01',
    ], $attrs));
}

/* ---- FR-CM-13: the bearer is DERIVED from the cause ---------------------- */

it('makes the tenant responsible only when the tenant caused it', function () {
    // "based on who caused the damage" — the whole of FR-CM-13 in one assertion.
    $order = $this->svc->attribute(faultOrder(), MaintenanceWorkOrder::FAULT_TENANT, 'Staff put grease down it.', null, $this->manager);

    expect($order->fault_party)->toBe('tenant');
    expect($order->cost_bearer)->toBe(MaintenanceWorkOrder::BEARER_TENANT);
    expect($order->tenantBearsCost())->toBeTrue();
});

it('leaves every other cause with the mall', function () {
    foreach ([
        MaintenanceWorkOrder::FAULT_WEAR,
        MaintenanceWorkOrder::FAULT_VENDOR,
        MaintenanceWorkOrder::FAULT_THIRD_PARTY,
        MaintenanceWorkOrder::FAULT_FORCE_MAJEURE,
        MaintenanceWorkOrder::FAULT_UNDETERMINED,
    ] as $cause) {
        $order = $this->svc->attribute(faultOrder(), $cause, null, null, $this->manager);
        expect($order->cost_bearer)->toBe(MaintenanceWorkOrder::BEARER_MALL, "cause '{$cause}' must not land on the tenant");
    }
});

it('does not bill the vendor through this field', function () {
    // Tempting to read "the vendor broke it" as "the vendor pays". FR-CM-13 offers only
    // mall|tenant; recovering from a contractor is the SLA penalty (FR-CM-08), a different
    // mechanism. Between these two parties, the vendor's mistake is the mall's problem.
    $order = $this->svc->attribute(faultOrder(), MaintenanceWorkOrder::FAULT_VENDOR, null, null, $this->manager);

    expect($order->cost_bearer)->toBe(MaintenanceWorkOrder::BEARER_MALL);
});

it('records who ruled and when', function () {
    // This record will one day be waved at a tenant. It needs a name and a date on it.
    $order = $this->svc->attribute(faultOrder(), MaintenanceWorkOrder::FAULT_TENANT, 'Grease in the condensate line.', null, $this->manager);

    expect($order->fault_recorded_by_user_id)->toBe($this->manager->id);
    expect($order->fault_recorded_at)->not->toBeNull();
    expect($order->fault_notes)->toBe('Grease in the condensate line.');
});

/* ---- who may rule ------------------------------------------------------- */

it('refuses an engineer ruling that a tenant is liable', function () {
    // Recording what you found is engineering; ruling that a tenant owes money is commercial.
    $engineer = makeUser('operations', [$this->asset->id]);
    expect($engineer->can(AttributeWorkOrderFaultService::PERMISSION))->toBeFalse();

    expect(fn () => $this->svc->attribute(faultOrder(), MaintenanceWorkOrder::FAULT_TENANT, null, null, $engineer))
        ->toThrow(DomainException::class);
});

it('refuses a viewer and an unauthenticated actor', function () {
    expect(fn () => $this->svc->attribute(faultOrder(), MaintenanceWorkOrder::FAULT_TENANT, null, null, makeUser('viewer', [$this->asset->id])))
        ->toThrow(DomainException::class);
    expect(fn () => $this->svc->attribute(faultOrder(), MaintenanceWorkOrder::FAULT_TENANT))
        ->toThrow(DomainException::class);
});

/* ---- the cases that would later produce an invoice addressed to nobody --- */

it('refuses to hold a tenant responsible for a common-area job', function () {
    // A work order has a NULLABLE unit_id — a common-area chiller has no occupier. This is the
    // case that would otherwise produce a claim against nobody.
    $common = faultOrder(['unit_id' => null, 'title' => 'Lobby chiller']);

    expect(fn () => $this->svc->attribute($common, MaintenanceWorkOrder::FAULT_TENANT, null, null, $this->manager))
        ->toThrow(DomainException::class);
    expect($common->fresh()->fault_party)->toBeNull(); // and nothing was half-written
});

it('refuses to hold a tenant responsible for a vacant unit', function () {
    // The unit exists but nobody leases it.
    $vacant = makeUnit($this->asset, ['code' => 'U-EMPTY']);
    $order = faultOrder(['unit_id' => $vacant->id]);

    expect($order->bearingTenant())->toBeNull();
    expect(fn () => $this->svc->attribute($order, MaintenanceWorkOrder::FAULT_TENANT, null, null, $this->manager))
        ->toThrow(DomainException::class);
});

it('still records a mall-borne cause on a common-area job', function () {
    // No tenant is needed to say "this one is ours" — the guard must not block the normal case.
    $common = faultOrder(['unit_id' => null, 'title' => 'Lobby chiller']);
    $order = $this->svc->attribute($common, MaintenanceWorkOrder::FAULT_WEAR, null, null, $this->manager);

    expect($order->cost_bearer)->toBe(MaintenanceWorkOrder::BEARER_MALL);
});

/* ---- lifecycle ---------------------------------------------------------- */

it('records the cause on a completed job', function () {
    // Deliberate: the cause is usually only known once the machine is open, and FR-CM-12 wants it
    // "as recorded on the work order". Terminal immutability protects the record of the WORK;
    // refusing this after closure would mean the finding could never be recorded at all.
    $order = faultOrder();
    $order->update(['status' => 'done', 'completed_at' => now()]);

    expect($this->svc->attribute($order, MaintenanceWorkOrder::FAULT_TENANT, 'Found on closing.', null, $this->manager)->cost_bearer)
        ->toBe(MaintenanceWorkOrder::BEARER_TENANT);
});

it('refuses to apportion the cost of a cancelled job', function () {
    // That work never happened, so there is no cost to apportion.
    $order = faultOrder();
    $order->update(['status' => 'cancelled']);

    expect(fn () => $this->svc->attribute($order, MaintenanceWorkOrder::FAULT_TENANT, null, null, $this->manager))
        ->toThrow(DomainException::class);
});

it('lets a manager revise a cause, and re-stamps who ruled', function () {
    // A cause is often revised once the engineer actually opens the machine. Freezing the first
    // guess would make the record *less* true, so revision is allowed — provenance is what makes
    // that safe. The bearer must move with the cause, not stick at the first answer.
    //
    // Provenance is asserted on the COLUMNS because they are what this service owns. The activity
    // log independently records the before/after diff in its `attribute_changes` column (spatie v5
    // moved it there; `properties` is now only the custom-properties bucket, so it reads `[]` on
    // every row and that is CORRECT, not a broken audit trail — I misread it once).
    $order = $this->svc->attribute(faultOrder(), MaintenanceWorkOrder::FAULT_TENANT, 'Looks like misuse.', null, $this->manager);
    expect($order->cost_bearer)->toBe(MaintenanceWorkOrder::BEARER_TENANT);
    $firstRuling = $order->fault_recorded_at;

    $senior = makeUser('super_admin', [$this->asset->id]);
    $revised = $this->svc->attribute($order, MaintenanceWorkOrder::FAULT_WEAR, 'Opened it up — the seal had perished.', null, $senior);

    expect($revised->cost_bearer)->toBe(MaintenanceWorkOrder::BEARER_MALL); // the claim is dropped
    expect($revised->fault_notes)->toBe('Opened it up — the seal had perished.');
    expect($revised->fault_recorded_by_user_id)->toBe($senior->id); // …and it says who overturned it
    expect($revised->fault_recorded_at->greaterThanOrEqualTo($firstRuling))->toBeTrue();
});

it('rejects a cause outside the vocabulary', function () {
    expect(fn () => $this->svc->attribute(faultOrder(), 'the_gremlins', null, null, $this->manager))
        ->toThrow(InvalidArgumentException::class);
});

/* ---- overriding the derivation ------------------------------------------ */

it('allows an override only with a reason on the record', function () {
    // A lease may make a tenant liable for wear on their own fit-out. That is the operator
    // overruling the FRD's derivation, so it must be argued for rather than clicked.
    $order = faultOrder();

    expect(fn () => $this->svc->attribute($order, MaintenanceWorkOrder::FAULT_WEAR, null, MaintenanceWorkOrder::BEARER_TENANT, $this->manager))
        ->toThrow(DomainException::class);

    $overridden = $this->svc->attribute(
        $order, MaintenanceWorkOrder::FAULT_WEAR, 'Clause 8.2 — tenant maintains their own fit-out.',
        MaintenanceWorkOrder::BEARER_TENANT, $this->manager
    );

    expect($overridden->cost_bearer)->toBe(MaintenanceWorkOrder::BEARER_TENANT);
    expect($overridden->fault_party)->toBe(MaintenanceWorkOrder::FAULT_WEAR); // the cause is unchanged
});

it('still refuses an override that names a tenant who does not exist', function () {
    $common = faultOrder(['unit_id' => null]);

    expect(fn () => $this->svc->attribute($common, MaintenanceWorkOrder::FAULT_WEAR, 'Lease says so.', MaintenanceWorkOrder::BEARER_TENANT, $this->manager))
        ->toThrow(DomainException::class);
});

/* ---- FR-CM-12: outside-sourced parts ------------------------------------ */

it('reads an external part\'s cost responsibility from the job, and leaves internal draws alone', function () {
    // FR-CM-12 is scoped to parts "sourced from outside" and says the cause is read from the work
    // order — so the part must not carry its own copy that could disagree.
    $order = $this->svc->attribute(faultOrder(), MaintenanceWorkOrder::FAULT_TENANT, 'Tenant staff broke it.', null, $this->manager);

    $external = MaintenanceWorkOrderPart::create([
        'maintenance_work_order_id' => $order->id, 'source' => 'external',
        'description' => 'Bespoke gasket', 'quantity' => 1, 'unit_cost' => 750, 'status' => 'recorded',
    ]);

    expect($external->costBearer())->toBe(MaintenanceWorkOrder::BEARER_TENANT);

    // Revising the finding moves the part's answer with it — one source of truth.
    $this->svc->attribute($order, MaintenanceWorkOrder::FAULT_WEAR, 'Actually perished.', null, $this->manager);
    expect($external->fresh()->costBearer())->toBe(MaintenanceWorkOrder::BEARER_MALL);
});

it('gives an unattributed job no cost bearer at all', function () {
    // Nobody has ruled yet — that is different from "the mall pays". Don't default a claim.
    $order = faultOrder();

    expect($order->fault_party)->toBeNull();
    expect($order->cost_bearer)->toBeNull();
    expect($order->faultIsAttributed())->toBeFalse();
    expect($order->tenantBearsCost())->toBeFalse();
});
