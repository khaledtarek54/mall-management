<?php

use App\Filament\Admin\Resources\Leases\Pages\ListLeases;
use App\Filament\Admin\Resources\Leases\LeaseResource;
use App\Support\ResourceGuides;
use Database\Seeders\RolesPermissionsSeeder;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\File;
use Livewire\Livewire;

/**
 * In-app guidance: the guide is the operator DOC, rendered — never a second copy.
 *
 * `docs/business-model/NN-*.md` already explains each module in plain language with worked numbers.
 * Re-typing that into the UI would create a second source of truth that drifts, which is the failure
 * this codebase keeps finding elsewhere. So the screen renders the file, and these assert the file
 * is really there and really renders.
 *
 * **Coverage is deliberately NOT asserted.** Nine screens have a guide; the rest do not, and a
 * registry padded with thirty exemption reasons would be noise pretending to be rigour. Writing the
 * missing guides is a documentation task, tracked as one.
 */
it('points every registered guide at a file that exists', function () {
    $missing = [];

    foreach (ResourceGuides::GUIDES as $resource => $path) {
        if (! File::exists(base_path($path))) {
            $missing[] = class_basename($resource).' → '.$path;
        }
    }

    expect($missing)->toBe([], "Guides pointing at a file that is not there:\n  ".implode("\n  ", $missing));
});

it('renders every guide to real HTML', function () {
    foreach (array_keys(ResourceGuides::GUIDES) as $resource) {
        $html = ResourceGuides::render($resource);

        expect($html)->toBeString("Guide for ".class_basename($resource)." rendered nothing")
            // Markdown that produced no tags means the file is empty or the renderer changed under us.
            ->and($html)->toContain('<');
    }
});

it('offers the guide on a screen that has one', function () {
    $this->seed(RolesPermissionsSeeder::class);
    $this->actingAs(makeUser('super_admin'));
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    Filament::setTenant(makeAsset());

    Livewire::test(ListLeases::class)
        ->assertOk()
        ->assertActionVisible('guide');

    Filament::setTenant(null, isQuiet: true);
});

it('hides the guide where none is written yet', function () {
    // An empty help panel is worse than no help button: it teaches the operator that help is not
    // worth clicking. The action keys on the registry, so a screen without a guide shows nothing.
    expect(ResourceGuides::has(LeaseResource::class))->toBeTrue()
        ->and(ResourceGuides::has(\App\Filament\Admin\Resources\Violations\ViolationResource::class))->toBeFalse()
        ->and(ResourceGuides::render(\App\Filament\Admin\Resources\Violations\ViolationResource::class))->toBeNull();
});
