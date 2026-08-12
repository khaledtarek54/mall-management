<?php

use App\Filament\Admin\Pages\Handbook;
use Database\Seeders\RolesPermissionsSeeder;
use Filament\Facades\Filament;
use Livewire\Livewire;

/**
 * The handbook page inside the panel.
 *
 * It frames `/handbook` rather than rendering it inline, because the handbook runs a Vue app and
 * the panel runs Livewire + Alpine — two SPA runtimes in one document would break the interactive
 * components first. So what is worth testing here is the CONTRACT between the two: which URL is
 * framed, that it carries the embed flag, and that it follows the reader's language.
 */
beforeEach(function () {
    $this->seed(RolesPermissionsSeeder::class);
    Filament::setCurrentPanel(Filament::getPanel('admin'));
});

afterEach(function () {
    Filament::setTenant(null, isQuiet: true);
    app()->setLocale('en');
});

it('renders inside the panel for any signed-in user', function () {
    // No permission gates it and none should: it documents how the software works, not any
    // property's numbers. A `{module}.view` check would gate a reader out of the manual for the
    // app they are already signed in to.
    $this->actingAs(makeUser('viewer'));
    Filament::setTenant(makeAsset());

    Livewire::test(Handbook::class)->assertOk();

    expect(Handbook::canAccess())->toBeTrue();
});

it('frames the embed URL, not the full site', function () {
    $this->actingAs(makeUser('viewer'));
    Filament::setTenant(makeAsset());

    // `embed=1` is what makes the handbook drop its own top navigation — the panel already supplies
    // the chrome. Without it the reader gets two stacked headers.
    expect((new Handbook)->getFrameUrl())->toBe('/handbook/?embed=1');
});

it('follows the reader into Arabic', function () {
    // An operator working in Arabic should land on the Arabic handbook, not on English with a
    // language switcher to go and find.
    $this->actingAs(makeUser('viewer'));
    Filament::setTenant(makeAsset());

    app()->setLocale('ar');

    expect((new Handbook)->getFrameUrl())->toBe('/handbook/ar/?embed=1');
});

it('offers an escape hatch to the full site', function () {
    // The frame is a fixed slice of the viewport — right for looking something up, wrong for
    // reading a chapter. The "open in a new tab" action must open the FULL site (no embed flag),
    // so a second window gets its navigation back.
    $this->actingAs(makeUser('viewer'));
    Filament::setTenant(makeAsset());

    Livewire::test(Handbook::class)
        ->assertActionVisible('openInTab')
        ->assertActionVisible('guide');
});
