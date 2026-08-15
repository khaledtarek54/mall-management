<?php

use App\Enums\PartyType;
use App\Enums\UnitOwnershipStatus;
use App\Models\Charge;
use App\Models\Invoice;
use App\Models\UnitOwnership;
use App\Services\BillUnitOwnershipsService;
use Carbon\CarbonImmutable;

/**
 * An owner who has not paid his service charge is in arrears, and the system must say so.
 *
 * `Tenant::outstandingBalance()` and `Tenant::isDelinquent()` take an optional property scope, and
 * that scope walked `lease -> unit -> asset`. A unit owner has no lease — so **scoped to the very
 * property he owes money in, his debt read as zero and he read as not delinquent.** Unscoped it was
 * right, which is the worst shape: correct on the tenant's own page, wrong on every property-scoped
 * admin surface that passes the ids.
 */
beforeEach(function () {
    $this->asset = makeAsset(['code' => 'ARR']);
    $this->owner = makeTenant(['party_type' => PartyType::UnitOwner->value]);

    $ownership = UnitOwnership::create([
        'asset_id' => $this->asset->id,
        'unit_id' => makeUnit($this->asset)->id,
        'tenant_id' => $this->owner->id,
        'status' => UnitOwnershipStatus::HandedOver->value,
        'started_at' => '2026-01-01',
        'payment_terms_days' => 0,
    ]);

    Charge::create([
        'unit_ownership_id' => $ownership->id,
        'name' => 'Service charge', 'type' => 'service_charge',
        'amount' => 7000, 'currency' => 'EGP', 'frequency' => 'monthly',
        'vat_applicable' => false, 'is_active' => true, 'start_date' => '2026-01-01',
    ]);

    app(BillUnitOwnershipsService::class)->runForPeriod(CarbonImmutable::parse('2026-01-01'));

    // Overdue: issued in January with zero payment terms, read from today.
    $this->invoice = Invoice::query()->where('unit_ownership_id', $ownership->id)->firstOrFail();
});

it('counts an owner assessment in the outstanding balance for that property', function () {
    // Unscoped has always been right — it is the SCOPED answer that silently dropped him.
    expect(round($this->owner->outstandingBalance(), 2))->toBe(7000.00)
        ->and(round($this->owner->outstandingBalance([$this->asset->id]), 2))->toBe(7000.00);
});

it('finds an owner delinquent in the property he owes money in', function () {
    expect($this->owner->isDelinquent())->toBeTrue()
        ->and($this->owner->isDelinquent([$this->asset->id]))->toBeTrue();
});

it('still keeps another property out of both answers', function () {
    // The control that stops this becoming a leak: scoped to a mall he owes nothing in, he owes
    // nothing and is not delinquent.
    $elsewhere = makeAsset(['code' => 'ELSE']);

    expect(round($this->owner->outstandingBalance([$elsewhere->id]), 2))->toBe(0.00)
        ->and($this->owner->isDelinquent([$elsewhere->id]))->toBeFalse();
});
