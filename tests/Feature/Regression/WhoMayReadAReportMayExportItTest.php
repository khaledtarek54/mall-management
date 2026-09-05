<?php

/*
|--------------------------------------------------------------------------
| Whoever may READ a report may export it (SW-177)
|--------------------------------------------------------------------------
| `ExportsReport::mayExport()` hardcoded `reports.view` while the page's own read gate can be
| deliberately different: the Vendor Scorecard admits `operations` through `vendors.view` — its
| docblock says why, the `vendor` role holds facility keys and must never read penalty totals —
| so that role could READ every figure on the screen and was refused the CSV of the same figures.
| And the refusal protected nothing: scheduling the report to themselves produced the identical
| file. Pure friction, on the role whose job the register serves.
|
| The Exports doctrine (CLAUDE.md): an export returns the page's own scoped query, so it can never
| show a row the screen would not — the gate IS the read gate. `mayExport()` now answers
| `static::canAccess()`, which is behaviour-identical on every page whose read gate is
| `reports.view`, i.e. most of them; those controls are asserted too, so the widening cannot
| silently become "everyone exports everything".
*/

use App\Filament\Admin\Pages\RentRoll;
use App\Filament\Admin\Pages\VendorScorecard;
use Database\Seeders\RolesPermissionsSeeder;
use Filament\Facades\Filament;

beforeEach(function () {
    $this->seed(RolesPermissionsSeeder::class);
    ensureAllPropertiesAsset();
    $this->asset = makeAsset(['code' => 'EXG']);
    Filament::setCurrentPanel(Filament::getPanel('admin'));
});

it('lets operations export the scorecard it can already read', function () {
    $this->actingAs(makeUser('operations', [$this->asset->id]));

    // The pair is the point: readable AND exportable, or the refusal protects nothing while the
    // scheduled-delivery door hands over the identical file.
    expect(VendorScorecard::canAccess())->toBeTrue()
        ->and(VendorScorecard::mayExport())->toBeTrue();
});

it('still refuses the scorecard export to a role that cannot read it', function () {
    // `marketing` holds neither vendors.view nor reports.view — the control that proves the gate
    // did not become "everyone".
    $this->actingAs(makeUser('marketing', [$this->asset->id]));

    expect(VendorScorecard::canAccess())->toBeFalse()
        ->and(VendorScorecard::mayExport())->toBeFalse();
});

it('is behaviour-identical on a page whose read gate IS reports.view', function () {
    $this->actingAs(makeUser('accounting', [$this->asset->id]));
    expect(RentRoll::canAccess())->toBeTrue()->and(RentRoll::mayExport())->toBeTrue();

    $this->actingAs(makeUser('technician', [$this->asset->id]));
    expect(RentRoll::canAccess())->toBeFalse()->and(RentRoll::mayExport())->toBeFalse();
});

it('keeps the two gates one predicate, so they cannot drift', function () {
    // The concern's own docblock names the invariant; this pins it structurally — a later edit
    // reintroducing a permission literal into mayExport() is the original defect back again.
    $source = file_get_contents(app_path('Filament/Admin/Pages/Concerns/ExportsReport.php'));

    expect($source)->toContain('return static::canAccess();')
        ->and(preg_match("/function mayExport[^}]*can\('/s", $source))->toBe(0);
});
