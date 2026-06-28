<?php

use Illuminate\Support\Str;

function makeTenantNotification($tenant, bool $read = false, array $data = [])
{
    return $tenant->notifications()->create([
        'id' => (string) Str::uuid(),
        'type' => \App\Notifications\PaymentReceivedNotification::class,
        'data' => array_merge(['title' => 'Payment received'], $data),
        'read_at' => $read ? now() : null,
    ]);
}

it('lists only the authenticated tenant\'s notifications', function () {
    $tenant = makeTenant();
    makeTenantNotification($tenant);
    makeTenantNotification($tenant, read: true);
    makeTenantNotification(makeTenant()); // another tenant's — must not leak

    $this->getJson('/api/v1/me/notifications', apiHeaders($tenant))
        ->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonStructure(['data' => [['id', 'type', 'data', 'read', 'createdAt']]]);
});

it('filters to unread notifications', function () {
    $tenant = makeTenant();
    makeTenantNotification($tenant);               // unread
    makeTenantNotification($tenant, read: true);   // read

    $this->getJson('/api/v1/me/notifications?unread=1', apiHeaders($tenant))
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.read', false);
});

it('returns the unread badge count', function () {
    $tenant = makeTenant();
    makeTenantNotification($tenant);
    makeTenantNotification($tenant);
    makeTenantNotification($tenant, read: true);

    $this->getJson('/api/v1/me/notifications/unread-count', apiHeaders($tenant))
        ->assertOk()
        ->assertJsonPath('data.unreadCount', 2);
});

it('marks a single notification read', function () {
    $tenant = makeTenant();
    $n = makeTenantNotification($tenant);

    $this->postJson("/api/v1/me/notifications/{$n->id}/read", [], apiHeaders($tenant))
        ->assertOk();

    expect($n->fresh()->read_at)->not->toBeNull();
});

it('returns 404 marking another tenant\'s notification read (no enumeration)', function () {
    $tenant = makeTenant();
    $n = makeTenantNotification(makeTenant());

    $this->postJson("/api/v1/me/notifications/{$n->id}/read", [], apiHeaders($tenant))
        ->assertNotFound();

    expect($n->fresh()->read_at)->toBeNull();
});

it('marks all notifications read', function () {
    $tenant = makeTenant();
    makeTenantNotification($tenant);
    makeTenantNotification($tenant);

    $this->postJson('/api/v1/me/notifications/read-all', [], apiHeaders($tenant))
        ->assertOk();

    expect($tenant->unreadNotifications()->count())->toBe(0);
});

it('strips Filament render-only keys from the notification payload', function () {
    $tenant = makeTenant();
    makeTenantNotification($tenant, data: [
        'invoice_number' => 'INV-1', // meaningful — kept
        'icon' => 'heroicon-o-bell', 'color' => 'primary',
        'format' => 'filament', 'duration' => 'persistent', // render-only — stripped
    ]);

    $data = $this->getJson('/api/v1/me/notifications', apiHeaders($tenant))
        ->assertOk()
        ->json('data.0.data');

    // camelCased on output; meaningful keys kept, render hints gone.
    expect($data)->toHaveKey('invoiceNumber')
        ->and($data)->not->toHaveKeys(['icon', 'color', 'format', 'duration']);
});

it('requires authentication', function () {
    $this->getJson('/api/v1/me/notifications')->assertUnauthorized();
});
