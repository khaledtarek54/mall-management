<?php

/*
|--------------------------------------------------------------------------
| Regression — the Save on Property Overrides is offered to whoever can save
|--------------------------------------------------------------------------
| `PropertyOverrides::canAccess()` is `settings.view`; the write is `settings.manage`. Three roles
| hold the first without the second — measured on the dev database 2026-09-04: `manager`, `viewer`
| and `mall_admin` — so the screen is deliberately readable by people who may not write it.
|
| The Blade rendered `<x-filament::button type="submit">` unconditionally, and the gated
| `getFormActions()` sitting beside it in the PHP was called by nothing: `Filament\Pages\Page` does
| not use `InteractsWithFormActions`, and `grep -rn getCachedFormActions app resources` found no
| caller. So the gate was written, reviewed and dead, and every one of those three roles was shown
| a Save button whose only possible outcome is the raw 403 from `save()`.
|
| The fix moves the act into the header strip — where `Settings`, this screen's deliberate twin,
| has always kept it — with ONE predicate (`canSave()`) read by the button and by the write.
*/

use App\Filament\Admin\Pages\PropertyOverrides;
use App\Models\PropertySetting;
use App\Support\PropertySettings;
use Database\Seeders\RolesPermissionsSeeder;
use Filament\Facades\Filament;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed(RolesPermissionsSeeder::class);
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $this->asset = makeAsset();
});

afterEach(fn () => Filament::setTenant(null, isQuiet: true));

it('offers no Save to an operator who may read the screen but not write it', function () {
    $manager = makeUser('manager');

    // The premise, stated rather than assumed: this operator really can open the page.
    expect($manager->can('settings.view'))->toBeTrue()
        ->and($manager->can('settings.manage'))->toBeFalse();

    $this->actingAs($manager);
    Filament::setTenant($this->asset);

    $page = Livewire::test(PropertyOverrides::class)->assertOk();

    $page->assertActionHidden('save');

    // And the Blade's own button is gone with it. Nothing on the page this operator receives
    // submits the form; before the fix a raw submit button sat under the fields.
    expect($page->html())->not->toContain('type="submit"');
});

it('still offers Save to an operator who holds settings.manage, and writes the override', function () {
    $this->actingAs(makeUser('super_admin'));
    Filament::setTenant($this->asset);

    Livewire::test(PropertyOverrides::class)
        ->assertActionVisible('save')
        ->fillForm(['billing__late_fee_percent' => 3])
        // `call('save')` rather than `callAction`: the header action dispatches this very method,
        // and calling it directly keeps the page's own form state — which is what the write reads.
        // The action's VISIBILITY is asserted above and its authorisation in the refusal below.
        ->call('save');

    expect(PropertySettings::get('billing.late_fee_percent', $this->asset->id))->toEqual(3.0);
});

it('refuses the write itself, not only the button', function () {
    // A hidden control is still dispatchable over Livewire — a crafted payload never opens the page.
    $this->actingAs(makeUser('manager'));
    Filament::setTenant($this->asset);

    Livewire::test(PropertyOverrides::class)
        ->fillForm(['billing__late_fee_percent' => 99])
        ->call('save')
        ->assertForbidden();

    expect(PropertySetting::query()->count())->toBe(0);
});
