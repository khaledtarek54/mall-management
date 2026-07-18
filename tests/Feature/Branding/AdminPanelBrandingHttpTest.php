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
    expect($html)->toContain('--primary-500: #0F766E');
});

it('does not expose the All-Properties pseudo-tenant as a reachable panel URL', function () {
    // Property-first UX: "All Properties" is no longer a selectable/accessible
    // operational tenant, so a crafted /admin/ALL URL 404s (Filament's
    // no-enumeration behavior when canAccessTenant() is false) rather than
    // dropping the user into the removed portfolio context. The Atriom-branding
    // fallback for the pseudo-asset stays in AdminPanelProvider as a defensive
    // guard, but is no longer reachable over HTTP.
    $admin = makeUser('super_admin');

    $this->actingAs($admin)->get('/admin/ALL')->assertNotFound();
});
