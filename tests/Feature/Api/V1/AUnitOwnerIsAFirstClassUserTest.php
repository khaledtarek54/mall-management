<?php

use App\Enums\UnitOwnershipStatus;
use App\Models\Invoice;
use App\Models\UnitOwnership;

/**
 * **Module 37's rule is that a unit owner IS a `tenants` row.** Same credentials, same portal, same
 * invoices — and every surface treats them as one except this API, where the word `unitOwnership`
 * appeared nowhere at all.
 *
 * So an owner could sign in (to an empty lease list, correctly — they hold none), be billed a
 * monthly assessment by `billing:run-assessments`, and read that invoice with `lease: null`: no
 * unit, no floor, no property. Nothing crashed, because `whenLoaded()` guards a null relation. An
 * owner of three shops simply saw three identical-looking bills.
 */
beforeEach(function () {
    $this->asset = makeAsset();
    $this->owner = makeTenant();
    $this->unit = makeUnit($this->asset, ['code' => 'B-07']);

    $this->ownership = UnitOwnership::create([
        'asset_id' => $this->asset->id,
        'unit_id' => $this->unit->id,
        'tenant_id' => $this->owner->id,
        'status' => UnitOwnershipStatus::HandedOver->value,
        'ownership_share_pct' => 100,
        'purchase_date' => '2025-01-01',
        'handover_date' => '2025-02-01',
        'started_at' => '2025-02-01',
        'currency' => 'EGP',
    ]);
});

it('lists the shops a party owns', function () {
    $row = $this->getJson('/api/v1/me/unit-ownerships', apiHeaders($this->owner))
        ->assertOk()->json('data.0');

    expect($row['unit']['code'])->toBe('B-07')
        ->and($row['status'])->toBe('handed_over')
        ->and($row['property']['id'])->toBe($this->asset->id)
        // The WHY behind the assessment figure: an owner charged on area and one charged on a
        // stated participation share are being billed by different rules.
        ->and($row['assessmentBasis'])->toBe('area')
        ->and($row['handoverDate'])->toBe('2025-02-01');
});

it('does not hand one owner another owner\'s shop', function () {
    $stranger = makeTenant();

    // Paired with the control above — an empty list would satisfy this alone.
    expect($this->getJson('/api/v1/me/unit-ownerships', apiHeaders($stranger))->assertOk()->json('data'))
        ->toBe([]);
});

it('says WHICH shop a maintenance assessment is for', function () {
    $invoice = Invoice::create([
        'number' => 'INV-'.uniqid(),
        'tenant_id' => $this->owner->id,
        'asset_id' => $this->asset->id,
        // An owner has no lease. This is exactly why `invoices.lease_id` was made nullable.
        'lease_id' => null,
        'unit_ownership_id' => $this->ownership->id,
        'status' => 'issued',
        'issue_date' => now(),
        'due_date' => now()->addDays(7),
        'period_start' => now()->startOfMonth(),
        'period_end' => now()->endOfMonth(),
        'subtotal' => 5000, 'total' => 5000, 'balance' => 5000,
        'currency' => 'EGP',
    ]);

    $data = $this->getJson("/api/v1/me/invoices/{$invoice->id}", apiHeaders($this->owner))
        ->assertOk()->json('data');

    // `lease` is legitimately null and must stay so — the app branches on it.
    expect($data['lease'])->toBeNull()
        // …and the shop now comes from the ownership instead of nowhere.
        ->and($data['unitOwnership']['unit']['code'])->toBe('B-07')
        ->and($data['unitOwnership']['reference'])->toBe($this->ownership->reference);
});

it('lets an owner report a fault in the shop they own, naming it explicitly', function () {
    // The SERVICE learnt to resolve a handed-over shop on 2026-09-02; the API validator did not,
    // so an owner naming their own unit was refused with "the selected unit id is invalid" while
    // the same request with unit_id omitted succeeded. Two halves of one rule, one shipped.
    $this->postJson('/api/v1/me/requests', [
        'requestType' => 'maintenance',
        'title' => 'Shutter jammed',
        'description' => 'It will not close.',
        'category' => 'structural',
        'unitId' => $this->unit->id,
    ], apiHeaders($this->owner))
        ->assertCreated()
        ->assertJsonPath('data.unit.code', 'B-07');
});

it('still refuses a unit that is neither leased nor owned', function () {
    $someoneElses = makeUnit($this->asset, ['code' => 'C-99']);

    // The clamp is unchanged in KIND — the unit is checked against the tenant's own rows, never
    // trusted from the client. Widening it to ownerships must not have widened it to everything.
    $this->postJson('/api/v1/me/requests', [
        'requestType' => 'maintenance',
        'title' => 'Not my shop',
        'description' => '...',
        'category' => 'structural',
        'unitId' => $someoneElses->id,
    ], apiHeaders($this->owner))
        ->assertStatus(422)
        ->assertJsonPath('errors.unitId.0', fn (string $m) => $m !== '');
});

it('refuses a shop the owner has not been handed yet', function () {
    $notYet = makeUnit($this->asset, ['code' => 'D-01']);
    UnitOwnership::create([
        'asset_id' => $this->asset->id,
        'unit_id' => $notYet->id,
        'tenant_id' => $this->owner->id,
        // Bought, not yet received. The assessment run does not bill it and the request form must
        // not accept it — the two read the same predicate so they cannot disagree.
        'status' => UnitOwnershipStatus::Contracted->value,
        'ownership_share_pct' => 100,
        'purchase_date' => '2026-01-01',
        'currency' => 'EGP',
    ]);

    $this->postJson('/api/v1/me/requests', [
        'requestType' => 'maintenance',
        'title' => 'Not handed over yet',
        'description' => '...',
        'category' => 'structural',
        'unitId' => $notYet->id,
    ], apiHeaders($this->owner))->assertStatus(422);
});
