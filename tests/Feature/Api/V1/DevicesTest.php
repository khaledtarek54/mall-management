<?php

it('registers a device token', function () {
    $tenant = makeTenant();

    $this->postJson('/api/v1/me/devices', [
        'platform' => 'ios',
        'token' => 'apns-token-abc',
        'device_name' => 'Khaled iPhone',
    ], apiHeaders($tenant))
        ->assertCreated()
        ->assertJsonPath('data.platform', 'ios')
        ->assertJsonPath('data.deviceName', 'Khaled iPhone');

    $this->assertDatabaseHas('device_tokens', [
        'tenant_id' => $tenant->id, 'platform' => 'ios', 'token' => 'apns-token-abc',
    ]);
});

it('upserts on the same device rather than stacking tokens', function () {
    $tenant = makeTenant();
    $payload = ['platform' => 'android', 'token' => 'fcm-1', 'device_name' => 'Pixel'];

    $this->postJson('/api/v1/me/devices', $payload, apiHeaders($tenant))->assertCreated();
    $this->postJson('/api/v1/me/devices', [...$payload, 'token' => 'fcm-2'], apiHeaders($tenant))->assertCreated();

    expect($tenant->deviceTokens()->count())->toBe(1);
    expect($tenant->deviceTokens()->first()->token)->toBe('fcm-2');
});

it('validates the platform', function () {
    $tenant = makeTenant();

    $this->postJson('/api/v1/me/devices', [
        'platform' => 'windows-phone', 'token' => 'x',
    ], apiHeaders($tenant))
        ->assertStatus(422)
        ->assertJsonValidationErrors(['platform']);
});

it('unregisters a device the tenant owns', function () {
    $tenant = makeTenant();
    $device = $tenant->deviceTokens()->create(['platform' => 'ios', 'token' => 't', 'device_name' => 'd']);

    $this->deleteJson("/api/v1/me/devices/{$device->id}", [], apiHeaders($tenant))->assertOk();

    $this->assertDatabaseMissing('device_tokens', ['id' => $device->id]);
});

it('returns 404 unregistering another tenant\'s device', function () {
    $tenant = makeTenant();
    $foreign = makeTenant()->deviceTokens()->create(['platform' => 'ios', 'token' => 't', 'device_name' => 'd']);

    $this->deleteJson("/api/v1/me/devices/{$foreign->id}", [], apiHeaders($tenant))->assertNotFound();
});
