<?php

it('returns the authenticated tenant profile', function () {
    $tenant = makeTenant(['name' => 'Acme Co']);

    $this->getJson('/api/v1/me', apiHeaders($tenant))
        ->assertOk()
        ->assertJsonPath('data.id', $tenant->id)
        ->assertJsonPath('data.name', 'Acme Co');
});

it('rejects an unauthenticated profile request', function () {
    $this->getJson('/api/v1/me')->assertUnauthorized();
});

it('updates only the editable contact fields', function () {
    $tenant = makeTenant(['phone' => '0100', 'name' => 'Original']);

    $this->patchJson('/api/v1/me', [
        'phone' => '0111222333',
        'contact_person' => 'Sara',
        'name' => 'Hacked Name', // must be ignored — admin-managed
    ], apiHeaders($tenant))
        ->assertOk()
        ->assertJsonPath('data.phone', '0111222333')
        ->assertJsonPath('data.contactPerson', 'Sara')
        ->assertJsonPath('data.name', 'Original');

    expect($tenant->fresh()->name)->toBe('Original');
});

it('returns the account balance with overdue and open count', function () {
    $tenant = makeTenant();
    $lease = makeLease(makeUnit(makeAsset()), $tenant);

    // One overdue (past due, unpaid) and one current.
    makeInvoice($lease, ['status' => 'overdue', 'due_date' => now()->subDays(5), 'balance' => 5000, 'total' => 5000]);
    makeInvoice($lease, ['status' => 'issued', 'due_date' => now()->addDays(5), 'balance' => 3000, 'total' => 3000]);

    $this->getJson('/api/v1/me/balance', apiHeaders($tenant))
        ->assertOk()
        ->assertJsonPath('data.outstanding', 8000)
        ->assertJsonPath('data.overdue', 5000)
        ->assertJsonPath('data.openCount', 2)
        ->assertJsonPath('data.currency', 'EGP');
});

it('returns active leases with unit and asset context', function () {
    $tenant = makeTenant();
    $asset = makeAsset(['name' => 'Haya Walk']);
    makeLease(makeUnit($asset, ['code' => 'A-01']), $tenant, ['status' => 'active']);
    makeLease(makeUnit($asset), $tenant, ['status' => 'terminated']); // excluded

    $response = $this->getJson('/api/v1/me/leases', apiHeaders($tenant))->assertOk();

    expect($response->json('data'))->toHaveCount(1);
    $response->assertJsonPath('data.0.unit.code', 'A-01')
        ->assertJsonPath('data.0.unit.asset.name', 'Haya Walk');
});
