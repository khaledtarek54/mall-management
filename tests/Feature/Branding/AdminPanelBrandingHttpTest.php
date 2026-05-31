<?php

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    seedRoles();
    ensureAllPropertiesAsset();
    Storage::fake('public');
});

it('renders the tenant brand name + theme override in the panel HTML', function () {
    $hw = makeAsset([
        'code' => 'HW',
        'name' => 'Heliopolis West',
        'primary_color' => '#0F766E',
    ]);
    $hw->addMedia(UploadedFile::fake()->image('hw-logo.png', 200, 80))
        ->toMediaCollection('logo');

    $admin = makeUser('super_admin');

    $response = $this->actingAs($admin)->get('/admin/HW');

    $response->assertOk();
    $html = $response->getContent();

    // Brand label resolves from the active tenant
    expect($html)->toContain('Heliopolis West');
    // Per-tenant logo URL is in the rendered img tag (MediaLibrary public URL contains the filename)
    expect($html)->toContain('hw-logo');
    // CSS-var override is injected via the HEAD_END render hook
    expect($html)->toContain('--primary-500: 15, 118, 110');
});

it('falls back to Atriom branding for the All-Properties pseudo-tenant', function () {
    $admin = makeUser('super_admin');

    $response = $this->actingAs($admin)->get('/admin/ALL');

    $response->assertOk();
    $html = $response->getContent();

    expect($html)->toContain('Atriom');
    // No CSS override on the synthetic tenant
    expect($html)->not->toContain('--primary-500: ');
});
