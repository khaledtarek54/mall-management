<?php

use App\Enums\PartyType;
use App\Enums\UnitOwnershipStatus;
use App\Models\Invoice;
use App\Models\UnitOwnership;
use Carbon\CarbonImmutable;

/**
 * An invoice answers "which unit?" through whichever agreement raised it.
 *
 * An invoice is raised against a lease OR an ownership — never both, never neither (enforced on
 * save) — and each holds the unit differently. Both invoice tables reached `lease.unit.code`
 * directly, so every owner assessment rendered a **blank unit**: to the operator in admin, and to
 * the owner himself in the portal, on his own bill. The unit FILTER had the same shape and was
 * worse than blank — filtering a mall by an owner-occupied unit returned nothing, which reads as
 * "no invoices" rather than "this filter cannot see him".
 *
 * The rule now lives once on the model (`unitCode()` + `scopeForUnit()`) because two surfaces ask
 * it, and a rule stated twice is a rule that drifts.
 *
 * Null stays possible and stays correct: a multi-unit lease has no single unit.
 */
function invoiceForAgreement(int $tenantId, int $assetId, ?int $leaseId, ?int $ownershipId): Invoice
{
    return Invoice::create([
        'number' => 'INV-UC-'.uniqid(),
        'asset_id' => $assetId,
        'lease_id' => $leaseId,
        'unit_ownership_id' => $ownershipId,
        'tenant_id' => $tenantId,
        'issue_date' => CarbonImmutable::now()->toDateString(),
        'period_start' => CarbonImmutable::now()->startOfMonth()->toDateString(),
        'period_end' => CarbonImmutable::now()->endOfMonth()->toDateString(),
        'due_date' => CarbonImmutable::now()->addDays(10)->toDateString(),
        'status' => 'issued',
        'subtotal' => 1000, 'vat_amount' => 0, 'total' => 1000,
        'paid_amount' => 0, 'balance' => 1000, 'currency' => 'EGP',
    ]);
}

beforeEach(function () {
    $this->asset = makeAsset(['code' => 'UC']);
});

it('names the unit of a LEASE invoice — the control', function () {
    $unit = makeUnit($this->asset, ['code' => 'A-101']);
    $tenant = makeTenant();
    $lease = makeLease($unit, $tenant, ['status' => 'active']);

    expect(invoiceForAgreement($tenant->id, $this->asset->id, $lease->id, null)->unitCode())
        ->toBe('A-101');
});

it('names the unit of an OWNER assessment', function () {
    $unit = makeUnit($this->asset, ['code' => 'B-202']);
    $owner = makeTenant(['party_type' => PartyType::UnitOwner->value]);
    $ownership = UnitOwnership::create([
        'asset_id' => $this->asset->id,
        'unit_id' => $unit->id,
        'tenant_id' => $owner->id,
        'status' => UnitOwnershipStatus::HandedOver->value,
        'started_at' => '2026-01-01',
        'payment_terms_days' => 10,
    ]);

    expect(invoiceForAgreement($owner->id, $this->asset->id, null, $ownership->id)->unitCode())
        ->toBe('B-202');
});

it('finds invoices by unit through either agreement, and excludes another unit', function () {
    $leasedUnit = makeUnit($this->asset, ['code' => 'A-101']);
    $ownedUnit = makeUnit($this->asset, ['code' => 'B-202']);

    $tenant = makeTenant();
    $lease = makeLease($leasedUnit, $tenant, ['status' => 'active']);
    $leaseInvoice = invoiceForAgreement($tenant->id, $this->asset->id, $lease->id, null);

    $owner = makeTenant(['party_type' => PartyType::UnitOwner->value]);
    $ownership = UnitOwnership::create([
        'asset_id' => $this->asset->id,
        'unit_id' => $ownedUnit->id,
        'tenant_id' => $owner->id,
        'status' => UnitOwnershipStatus::HandedOver->value,
        'started_at' => '2026-01-01',
        'payment_terms_days' => 10,
    ]);
    $ownerInvoice = invoiceForAgreement($owner->id, $this->asset->id, null, $ownership->id);

    // Each unit finds its own invoice...
    expect(Invoice::query()->forUnit($leasedUnit->id)->pluck('id')->all())->toBe([$leaseInvoice->id])
        ->and(Invoice::query()->forUnit($ownedUnit->id)->pluck('id')->all())->toBe([$ownerInvoice->id]);

    // ...and a unit with neither finds nothing, so the scope narrows rather than merely widening.
    expect(Invoice::query()->forUnit(makeUnit($this->asset, ['code' => 'C-303'])->id)->count())->toBe(0);
});
