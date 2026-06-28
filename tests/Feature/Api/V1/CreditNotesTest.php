<?php

use App\Models\CreditNote;

function makeCreditNote($tenant, array $attrs = []): CreditNote
{
    static $seq = 0;
    $seq++;

    return CreditNote::create(array_merge([
        'number' => 'CN-'.uniqid(),
        'tenant_id' => $tenant->id,
        'status' => 'issued',
        'issue_date' => now(),
        'reason' => 'adjustment',
        'subtotal' => 1000,
        'vat_amount' => 0,
        'total' => 1000,
        'applied_amount' => 0,
        'balance' => 1000,
        'currency' => 'EGP',
    ], $attrs));
}

it('lists only the authenticated tenant\'s credit notes', function () {
    $tenant = makeTenant();
    makeCreditNote($tenant);
    makeCreditNote($tenant);
    makeCreditNote(makeTenant()); // another tenant's — must not leak

    $this->getJson('/api/v1/me/credit-notes', apiHeaders($tenant))
        ->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonStructure(['data' => [['id', 'number', 'status', 'total', 'balance', 'appliedAmount']]]);
});

it('filters credit notes by status', function () {
    $tenant = makeTenant();
    makeCreditNote($tenant, ['status' => 'issued']);
    makeCreditNote($tenant, ['status' => 'applied', 'balance' => 0, 'applied_amount' => 1000]);

    $this->getJson('/api/v1/me/credit-notes?status=applied', apiHeaders($tenant))
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.status', 'applied');
});

it('shows a single credit note', function () {
    $tenant = makeTenant();
    $cn = makeCreditNote($tenant);

    $this->getJson("/api/v1/me/credit-notes/{$cn->id}", apiHeaders($tenant))
        ->assertOk()
        ->assertJsonPath('data.id', $cn->id)
        ->assertJsonPath('data.number', $cn->number);
});

it('returns 404 for another tenant\'s credit note', function () {
    $tenant = makeTenant();
    $cn = makeCreditNote(makeTenant());

    $this->getJson("/api/v1/me/credit-notes/{$cn->id}", apiHeaders($tenant))
        ->assertNotFound();
});
