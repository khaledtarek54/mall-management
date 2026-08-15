<?php

use App\Enums\PartyType;
use App\Enums\UnitOwnershipStatus;
use App\Models\Charge;
use App\Models\UnitOwnership;
use App\Services\BillUnitOwnershipsService;
use App\Services\TransferUnitOwnershipService;
use Carbon\CarbonImmutable;

/**
 * A unit is sold on — Yardi's change-of-ownership and its resale (estoppel) certificate.
 *
 * The certificate is the document the buyer's solicitor holds money back against, so every figure
 * on it is read from the books rather than typed. The transfer closes the seller's tenure and opens
 * the buyer's; it never deletes the seller, because his assessments, CAM shares and statements all
 * point at that row.
 */
beforeEach(function () {
    $this->asset = makeAsset(['code' => 'RSL']);
    $this->unit = makeUnit($this->asset, ['area_sqm' => 80]);
    $this->seller = makeTenant(['party_type' => PartyType::UnitOwner->value, 'name' => 'Seller']);
    $this->buyer = makeTenant(['party_type' => PartyType::UnitOwner->value, 'name' => 'Buyer']);

    $this->ownership = UnitOwnership::create([
        'asset_id' => $this->asset->id, 'unit_id' => $this->unit->id,
        'tenant_id' => $this->seller->id,
        'status' => UnitOwnershipStatus::HandedOver->value,
        'started_at' => '2026-01-01', 'payment_terms_days' => 0,
    ]);

    Charge::create([
        'unit_ownership_id' => $this->ownership->id,
        'name' => 'Service charge', 'type' => 'service_charge',
        'amount' => 4000, 'currency' => 'EGP', 'frequency' => 'monthly',
        'vat_applicable' => false, 'is_active' => true, 'start_date' => '2026-01-01',
    ]);

    app(BillUnitOwnershipsService::class)->runForPeriod(CarbonImmutable::parse('2026-01-01'), $this->asset->id);
    app(BillUnitOwnershipsService::class)->runForPeriod(CarbonImmutable::parse('2026-02-01'), $this->asset->id);
});

it('refuses to transfer over arrears unless that is a deliberate act', function () {
    $svc = app(TransferUnitOwnershipService::class);

    // 8,000 unpaid. Refused rather than warned: the buyer's side holds back against this figure, so
    // letting it through silently is how a debt becomes the wrong person's.
    expect(fn () => $svc->transfer($this->ownership, $this->buyer, CarbonImmutable::parse('2026-03-01')))
        ->toThrow(DomainException::class);

    // Deliberately, it goes through — and the certificate still states the figure.
    $result = $svc->transfer($this->ownership, $this->buyer, CarbonImmutable::parse('2026-03-01'), allowOutstanding: true);

    expect(round($result['certificate']['outstanding'], 2))->toBe(8000.00);
});

it('states the account on the certificate, read from the books', function () {
    $cert = app(TransferUnitOwnershipService::class)
        ->certificate($this->ownership, CarbonImmutable::parse('2026-03-01'));

    expect(round($cert['assessments_billed'], 2))->toBe(8000.00)
        ->and(round($cert['outstanding'], 2))->toBe(8000.00)
        ->and(round($cert['monthly_assessment'], 2))->toBe(4000.00)
        ->and($cert['open_invoices'])->toHaveCount(2)
        ->and($cert['unit'])->toBe($this->unit->code)
        ->and($cert['owner'])->toBe('Seller');
});

it('closes the seller and opens the buyer, keeping both', function () {
    $r = app(TransferUnitOwnershipService::class)
        ->transfer($this->ownership, $this->buyer, CarbonImmutable::parse('2026-03-01'), allowOutstanding: true);

    expect($r['seller']->status)->toBe(UnitOwnershipStatus::Transferred)
        ->and($r['seller']->ended_at->toDateString())->toBe('2026-02-28')
        ->and($r['buyer']->started_at->toDateString())->toBe('2026-03-01')
        ->and($r['buyer']->tenant_id)->toBe($this->buyer->id)
        // Both rows survive — the seller's assessments still have their basis.
        ->and($this->unit->unitOwnerships()->count())->toBe(2)
        ->and($this->unit->ownershipOn('2026-03-15')->is($r['buyer']))->toBeTrue()
        // The buyer inherits the TERMS, not the debt.
        ->and($r['buyer']->tenure_type)->toBe($r['seller']->tenure_type)
        ->and((float) $r['buyer']->ownership_share_pct)->toBe((float) $r['seller']->ownership_share_pct);
});

it('refuses a buyer who is not a unit owner, and a second transfer', function () {
    $svc = app(TransferUnitOwnershipService::class);

    expect(fn () => $svc->transfer($this->ownership, makeTenant(), CarbonImmutable::parse('2026-03-01'), allowOutstanding: true))
        ->toThrow(DomainException::class);

    $svc->transfer($this->ownership, $this->buyer, CarbonImmutable::parse('2026-03-01'), allowOutstanding: true);

    expect(fn () => $svc->transfer($this->ownership->fresh(), $this->buyer, CarbonImmutable::parse('2026-04-01'), allowOutstanding: true))
        ->toThrow(DomainException::class);
});
