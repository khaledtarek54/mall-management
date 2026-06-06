<?php

use App\Models\Asset;
use App\Providers\Filament\AdminPanelProvider;
use Filament\Facades\Filament;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

// Asset is referenced through the helper signatures below; keep the import.

beforeEach(function () {
    Storage::fake('public');
});

/**
 * The static branding resolvers on AdminPanelProvider are protected — bridge
 * via reflection so we can exercise each one without booting the full panel.
 */
function callBrandingResolver(string $method, mixed ...$args): string
{
    $ref = new ReflectionMethod(AdminPanelProvider::class, $method);
    $ref->setAccessible(true);

    return (string) $ref->invoke(null, ...$args);
}

/**
 * Filament::setTenant() fires a TenantSet event whose second arg must be a
 * non-null user. Tests don't have an authenticated user, so go through the
 * `isQuiet: true` overload to skip the event.
 */
function setTenantQuiet(?Asset $tenant): void
{
    Filament::setTenant($tenant, isQuiet: true);
}

it('falls back to Atriom brand name when no tenant is active', function () {
    setTenantQuiet(null);

    expect(callBrandingResolver('resolveBrandName'))->toBe('Atriom');
});

it('falls back to Atriom brand name for the All Properties pseudo-tenant', function () {
    $all = ensureAllPropertiesAsset();
    setTenantQuiet($all);

    expect(callBrandingResolver('resolveBrandName'))->toBe('Atriom');
});

it('returns the tenant property name when a real tenant is active', function () {
    $hw = makeAsset(['code' => 'HW', 'name' => 'Heliopolis West']);
    setTenantQuiet($hw);

    expect(callBrandingResolver('resolveBrandName'))->toBe('Heliopolis West');
});

it('falls back to the light Atriom logo when no tenant-uploaded logo exists', function () {
    $hw = makeAsset(['code' => 'HW']);
    setTenantQuiet($hw);

    expect(callBrandingResolver('resolveBrandLogo'))->toContain('atriom-logo-light.svg');
});

it('falls back to the dark Atriom logo in dark mode', function () {
    $hw = makeAsset(['code' => 'HW']);
    setTenantQuiet($hw);

    expect(callBrandingResolver('resolveBrandLogo', true))->toContain('atriom-logo-dark.svg');
});

it('returns the tenant logo URL when one is uploaded', function () {
    $hw = makeAsset(['code' => 'HW']);
    $hw->addMedia(UploadedFile::fake()->image('mall-logo.png'))->toMediaCollection('logo');
    setTenantQuiet($hw);

    expect(callBrandingResolver('resolveBrandLogo'))->toContain('mall-logo.png');
});

it('falls back to the Atriom favicon when no tenant-uploaded favicon exists', function () {
    $hw = makeAsset(['code' => 'HW']);
    setTenantQuiet($hw);

    expect(callBrandingResolver('resolveFavicon'))->toContain('atriom-favicon.svg');
});

it('emits an empty theme override when no tenant is active', function () {
    setTenantQuiet(null);

    expect(callBrandingResolver('renderPerTenantThemeOverride'))->toBe('');
});

it('emits an empty theme override for the All Properties pseudo-tenant', function () {
    $all = ensureAllPropertiesAsset();
    setTenantQuiet($all);

    expect(callBrandingResolver('renderPerTenantThemeOverride'))->toBe('');
});

it('emits an empty theme override when tenant has no primary_color set', function () {
    $hw = makeAsset(['code' => 'HW', 'primary_color' => null]);
    setTenantQuiet($hw);

    expect(callBrandingResolver('renderPerTenantThemeOverride'))->toBe('');
});

it('emits an empty theme override when primary_color is not a valid 6-digit hex', function () {
    $hw = makeAsset(['code' => 'HW', 'primary_color' => '#XYZ']);
    setTenantQuiet($hw);

    expect(callBrandingResolver('renderPerTenantThemeOverride'))->toBe('');
});

it('emits a style block with hex shades derived via color-mix()', function () {
    $hw = makeAsset(['code' => 'HW', 'primary_color' => '#0F766E']);
    setTenantQuiet($hw);

    $css = callBrandingResolver('renderPerTenantThemeOverride');

    expect($css)->toContain('<style>');
    expect($css)->toContain('</style>');
    // 500 is the raw hex (Filament uses var(--primary-500) directly as a colour)
    expect($css)->toContain('--primary-500: #0F766E');
    // Lighter + darker shades derived via color-mix so hover/focus states vary
    expect($css)->toContain('--primary-50:  color-mix(in oklab, #0F766E 6%,  white)');
    expect($css)->toContain('--primary-600: color-mix(in oklab, #0F766E 88%, black)');
    expect($css)->toContain('--primary-950: color-mix(in oklab, #0F766E 25%, black)');
});

it('accepts hex with or without the leading hash', function () {
    $hw = makeAsset(['code' => 'HW', 'primary_color' => '0F766E']);
    setTenantQuiet($hw);

    expect(callBrandingResolver('renderPerTenantThemeOverride'))->toContain('#0F766E');
});
