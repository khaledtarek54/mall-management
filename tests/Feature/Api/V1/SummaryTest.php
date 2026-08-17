<?php

use App\Notifications\PaymentReceivedNotification;
use Illuminate\Support\Str;

it('returns a home summary scoped to the tenant', function () {
    $tenant = makeTenant();
    // A percentage-rent lease enables the "declare sales" capability flag.
    makeLease(makeUnit(makeAsset()), $tenant, ['has_percentage_rent' => true]);
    $tenant->notifications()->create([
        'id' => (string) Str::uuid(),
        'type' => PaymentReceivedNotification::class,
        'data' => ['title' => 'x'],
        'read_at' => null,
    ]);

    $this->getJson('/api/v1/me/summary', apiHeaders($tenant))
        ->assertOk()
        ->assertJsonStructure(['data' => [
            'outstanding', 'overdue', 'openInvoices', 'creditAvailable',
            'isDelinquent', 'openMaintenance', 'disputedDeclarations',
            'canDeclareSales', 'unreadNotifications', 'currency',
        ]])
        ->assertJsonPath('data.canDeclareSales', true)
        ->assertJsonPath('data.unreadNotifications', 1)
        ->assertJsonPath('data.currency', 'EGP');
});

it('reports can_declare_sales false for a tenant with no percentage-rent lease', function () {
    $tenant = makeTenant();
    makeLease(makeUnit(makeAsset()), $tenant, ['has_percentage_rent' => false]);

    $this->getJson('/api/v1/me/summary', apiHeaders($tenant))
        ->assertOk()
        ->assertJsonPath('data.canDeclareSales', false);
});

it('requires authentication', function () {
    $this->getJson('/api/v1/me/summary')->assertUnauthorized();
});
