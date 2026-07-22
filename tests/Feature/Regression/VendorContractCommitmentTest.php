<?php

use App\Models\Vendor;
use App\Models\VendorBill;
use App\Models\VendorContract;

/**
 * `vendor_contracts.value` was a decorative number (module 12).
 *
 * A bill was never tied to the contract it was incurred under, so nothing in the system compared
 * what was committed against what the vendor actually invoiced — a EGP 500k contract could quietly
 * absorb EGP 5m of bills. These pin committed-vs-actual and the over-run flag.
 */
function commitmentContract(float $value = 100000): VendorContract
{
    $vendor = Vendor::create(['name' => 'Falcon Facilities', 'status' => Vendor::STATUS_ACTIVE]);

    return VendorContract::create([
        'vendor_id' => $vendor->id,
        'asset_id' => makeAsset()->id,
        'name' => 'Annual cleaning',
        'status' => 'active',
        'start_date' => now()->subMonths(2),
        'end_date' => now()->addMonths(10),
        'value' => $value,
    ]);
}

function commitmentBill(VendorContract $contract, float $total, string $status = 'draft'): VendorBill
{
    return VendorBill::create([
        'number' => 'VB-'.str()->random(6),
        'vendor_id' => $contract->vendor_id,
        'vendor_contract_id' => $contract->id,
        'asset_id' => $contract->asset_id,
        'category' => 'cleaning_security',
        'status' => $status,
        'bill_date' => now(),
        'due_date' => now()->addDays(30),
        'subtotal' => $total,
        'vat_amount' => 0,
        'total' => $total,
        'balance' => $total,
    ]);
}

it('reports committed, billed-to-date and remaining for a contract', function () {
    $contract = commitmentContract(100000);
    commitmentBill($contract, 30000);
    commitmentBill($contract, 25000);

    expect($contract->billedToDate())->toBe(55000.0)
        ->and($contract->remainingValue())->toBe(45000.0)
        ->and($contract->isOverCommitted())->toBeFalse();
});

it('flags a contract the vendor has invoiced past', function () {
    $contract = commitmentContract(50000);
    commitmentBill($contract, 60000);

    expect($contract->remainingValue())->toBe(-10000.0)
        ->and($contract->isOverCommitted())->toBeTrue();
});

it('does not let a cancelled bill consume the commitment', function () {
    $contract = commitmentContract(100000);
    commitmentBill($contract, 40000);
    commitmentBill($contract, 90000, 'cancelled'); // withdrawn, never incurred

    expect($contract->billedToDate())->toBe(40000.0)
        ->and($contract->isOverCommitted())->toBeFalse();
});

it('leaves an ad-hoc bill with no contract out of every commitment figure', function () {
    $contract = commitmentContract(100000);
    commitmentBill($contract, 20000);

    // A call-out billed against the vendor but under no contract.
    VendorBill::create([
        'number' => 'VB-ADHOC',
        'vendor_id' => $contract->vendor_id,
        'asset_id' => $contract->asset_id,
        'category' => 'maintenance',
        'status' => 'draft',
        'bill_date' => now(),
        'due_date' => now()->addDays(30),
        'subtotal' => 75000,
        'vat_amount' => 0,
        'total' => 75000,
        'balance' => 75000,
    ]);

    expect($contract->billedToDate())->toBe(20000.0);
});
