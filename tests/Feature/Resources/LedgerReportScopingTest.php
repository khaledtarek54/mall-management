<?php

use App\Support\TenantScope;
use Database\Seeders\RolesPermissionsSeeder;
use Filament\Facades\Filament;

beforeEach(function () {
    $this->seed(RolesPermissionsSeeder::class);
    // No active Filament tenant → visibleAssetIds() falls back to the user's
    // assigned set (or null for an unrestricted user).
    Filament::setTenant(null, isQuiet: true);
});

// PDF-export review fix: the report PDF header label must derive from the CLAMPED
// scope, never the raw client-bound assetId — else a restricted user tampering the
// picker would leak another property's name in the header.
it('clamps the report PDF header label to the visible property (no name leak)', function () {
    $a = makeAsset();
    $b = makeAsset();
    $this->actingAs(makeUser('accounting', [$a->id])); // visible: A only

    $page = new \App\Filament\Admin\Pages\TrialBalance();
    $page->assetId = $b->id; // tamper to a property they cannot see

    $method = new ReflectionMethod($page, 'propertyLabel');
    $method->setAccessible(true);
    $label = $method->invoke($page);

    expect($label)->toBe($a->name);       // clamped to their own property
    expect($label)->not->toBe($b->name);  // does NOT leak B's name
});

it('clamps a property-restricted user to their assigned properties', function () {
    $a = makeAsset();
    $b = makeAsset();
    $this->actingAs(makeUser('accounting', [$a->id])); // assigned to A only

    // Tampering the picked property to B is ignored — clamped to the allowed set.
    expect(TenantScope::reportAssetIds($b->id))->toBe([$a->id]);
    // Picking their own property is honored.
    expect(TenantScope::reportAssetIds($a->id))->toBe([$a->id]);
    // "Consolidated" for a restricted user means consolidated across THEIR set.
    expect(TenantScope::reportAssetIds(null))->toBe([$a->id]);
});

it('lets an unrestricted user pick any property or truly consolidate', function () {
    $a = makeAsset();
    $b = makeAsset();
    $this->actingAs(makeUser('super_admin'));

    expect(TenantScope::reportAssetIds($b->id))->toBe([$b->id]);
    expect(TenantScope::reportAssetIds(null))->toBeNull(); // null = all properties
});

it('clamps the PDF header label on every report page, not just Trial Balance', function () {
    $a = makeAsset();
    $b = makeAsset();
    $this->actingAs(makeUser('accounting', [$a->id])); // visible: A only

    foreach ([
        \App\Filament\Admin\Pages\IncomeStatement::class,
        \App\Filament\Admin\Pages\BalanceSheet::class,
        \App\Filament\Admin\Pages\CashFlow::class,
        \App\Filament\Admin\Pages\GeneralLedger::class,
    ] as $pageClass) {
        $page = new $pageClass();
        $page->assetId = $b->id; // tamper to a property they cannot see
        $m = new ReflectionMethod($page, 'propertyLabel');
        $m->setAccessible(true);
        expect($m->invoke($page))->toBe($a->name); // clamped — no leak of B
    }
});
