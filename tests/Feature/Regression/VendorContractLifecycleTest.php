<?php

use App\Models\Vendor;
use App\Models\VendorBill;
use App\Models\VendorContract;
use App\Notifications\VendorContractRenewalDueNotification;
use Illuminate\Support\Facades\Notification;

/**
 * Vendor contract lifecycle (module 12b).
 *
 * Two gaps made `vendor_contracts` a record of the past rather than something you manage:
 *
 *  1. `vendors:expire-contracts` fired on end_date, by which point every decision has already been
 *     made for you. The date that matters is end_date − notice_period_days.
 *  2. `value` was static, so the over-commitment flag couldn't tell an APPROVED change order from
 *     an uncontrolled over-run — both showed red, which teaches the operator to ignore the flag.
 */
function lifecycleContract(array $overrides = []): VendorContract
{
    $vendor = Vendor::create(['name' => 'Falcon Facilities '.uniqid(), 'status' => Vendor::STATUS_ACTIVE]);

    return VendorContract::create(array_merge([
        'vendor_id' => $vendor->id,
        'asset_id' => makeAsset()->id,
        'name' => 'Annual cleaning',
        'status' => 'active',
        'start_date' => now()->subMonths(10),
        'end_date' => now()->addDays(60)->toDateString(),
        'value' => 100000,
    ], $overrides));
}

it('derives the notice deadline from the term and keeps it in step with edits', function () {
    $contract = lifecycleContract(['end_date' => '2026-12-31', 'notice_period_days' => 90]);

    expect($contract->notice_deadline->toDateString())->toBe('2026-10-02');

    // Re-signing to a later end date must move the deadline — a stale one silently stops the chase.
    $contract->update(['end_date' => '2027-06-30']);
    expect($contract->refresh()->notice_deadline->toDateString())->toBe('2027-04-01');

    // Dropping the notice period clears it entirely.
    $contract->update(['notice_period_days' => null]);
    expect($contract->refresh()->notice_deadline)->toBeNull();
});

it('alerts once when the notice deadline arrives, then never re-nags', function () {
    Notification::fake();
    $this->seed(\Database\Seeders\RolesPermissionsSeeder::class);
    // 60 days of term left, 90 days notice → the deadline passed 30 days ago.
    $contract = lifecycleContract(['notice_period_days' => 90]);

    expect($contract->isNoticeDue())->toBeTrue();

    $this->artisan('vendors:scan-contract-renewals')->assertSuccessful();
    expect($contract->refresh()->renewal_alert_for->toDateString())->toBe($contract->end_date->toDateString());

    Notification::fake();
    $this->artisan('vendors:scan-contract-renewals')->assertSuccessful();
    Notification::assertNothingSent();
});

it('re-arms the alert when the contract is re-signed to a new term', function () {
    $contract = lifecycleContract(['notice_period_days' => 90]);
    $this->artisan('vendors:scan-contract-renewals')->assertSuccessful();
    $firstStamp = $contract->refresh()->renewal_alert_for->toDateString();

    // Renewed for another year. The next notice deadline must chase again by itself.
    $contract->update(['end_date' => now()->addDays(425)->toDateString()]);
    expect($contract->refresh()->isNoticeDue())->toBeFalse();

    // Wind the new term down into its own notice window.
    $contract->update(['end_date' => now()->addDays(30)->toDateString()]);
    $this->artisan('vendors:scan-contract-renewals')->assertSuccessful();

    expect($contract->refresh()->renewal_alert_for->toDateString())
        ->not->toBe($firstStamp)
        ->toBe($contract->end_date->toDateString());
});

it('leaves a contract alone before its notice window and when no notice was agreed', function () {
    // 60 days of term left, only 30 days notice → the deadline is still 30 days away.
    $early = lifecycleContract(['notice_period_days' => 30]);
    // No notice period agreed at all → nothing to chase.
    $none = lifecycleContract(['notice_period_days' => null]);

    $this->artisan('vendors:scan-contract-renewals')->assertSuccessful();

    expect($early->refresh()->renewal_alert_for)->toBeNull()
        ->and($early->isNoticeDue())->toBeFalse()
        ->and($none->refresh()->renewal_alert_for)->toBeNull()
        ->and($none->noticeDeadline())->toBeNull();
});

it('does not chase an already-expired or draft contract', function () {
    $expired = lifecycleContract(['status' => 'expired', 'notice_period_days' => 90]);
    $draft = lifecycleContract(['status' => 'draft', 'notice_period_days' => 90]);

    $this->artisan('vendors:scan-contract-renewals')->assertSuccessful();

    expect($expired->refresh()->renewal_alert_for)->toBeNull()
        ->and($draft->refresh()->renewal_alert_for)->toBeNull();
});

it('moves the commitment by an approved change order instead of flagging an over-run', function () {
    $contract = lifecycleContract(['value' => 100000]);

    VendorBill::create([
        'number' => 'VB-'.uniqid(),
        'vendor_id' => $contract->vendor_id,
        'vendor_contract_id' => $contract->id,
        'asset_id' => $contract->asset_id,
        'category' => 'cleaning_security',
        'status' => 'draft',
        'bill_date' => now(),
        'due_date' => now()->addDays(30),
        'subtotal' => 130000, 'vat_amount' => 0, 'total' => 130000, 'balance' => 130000,
    ]);

    // Billed past the SIGNED value — over-run until the variation is recorded.
    expect($contract->isOverCommitted())->toBeTrue()
        ->and($contract->remainingValue())->toBe(-30000.0);

    $contract->amendments()->create([
        'value_delta' => 50000,
        'effective_on' => now(),
        'reason' => 'Extra food-court deep clean agreed with the operator',
    ]);

    expect($contract->effectiveValue())->toBe(150000.0)
        ->and($contract->remainingValue())->toBe(20000.0)
        ->and($contract->isOverCommitted())->toBeFalse();
});

it('lets a change order reduce the commitment too', function () {
    $contract = lifecycleContract(['value' => 100000]);

    $contract->amendments()->create([
        'value_delta' => -40000,
        'effective_on' => now(),
        'reason' => 'Landscaping descoped — handled in-house from March',
    ]);

    expect($contract->effectiveValue())->toBe(60000.0)
        ->and($contract->remainingValue())->toBe(60000.0);
});
