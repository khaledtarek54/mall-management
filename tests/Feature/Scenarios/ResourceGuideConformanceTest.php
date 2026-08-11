<?php

use App\Filament\Admin\Resources\Leases\LeaseResource;
use App\Filament\Admin\Resources\Leases\Pages\ListLeases;
use App\Support\ResourceGuides;
use Database\Seeders\RolesPermissionsSeeder;
use Filament\Facades\Filament;
use Livewire\Livewire;

/**
 * In-app guidance: complete, in BOTH languages, and reachable.
 *
 * The first version of this rendered `docs/business-model/*.md` into a modal, and was wrong three
 * ways: the docs are English only, so an Arabic operator got English help in an RTL panel; it styled
 * itself with `prose` classes this build does not ship, so it rendered as unspaced raw text; and a
 * whole reference document in a dialogue is not guidance — someone who opens help is stuck on one
 * thing, not looking for a chapter.
 *
 * The guide is now short, structured and translated, so THESE are the things worth gating: that
 * every registered screen has all four fields, in both languages, and that the panel actually
 * appears. Coverage across all ~45 resources is still deliberately not asserted — a registry padded
 * with exemption reasons would be noise — but every screen that claims a guide must have a real one.
 */
it('gives every registered screen a complete guide, in English and Arabic', function () {
    $missing = [];

    foreach (ResourceGuides::GUIDES as $resource => $key) {
        foreach (['en', 'ar'] as $locale) {
            app()->setLocale($locale);

            $purpose = ResourceGuides::purpose($key);

            // A missing key returns the key path itself — which is how untranslated help reaches
            // production reading "guides.leases.purpose".
            if ($purpose === "guides.{$key}.purpose" || trim($purpose) === '') {
                $missing[] = "{$key}.purpose [{$locale}]";
            }

            foreach (['steps', 'affects', 'rules'] as $field) {
                $items = ResourceGuides::{$field}($key);

                if ($items === []) {
                    $missing[] = "{$key}.{$field} [{$locale}]";
                }
            }
        }
    }

    app()->setLocale('en');

    expect($missing)->toBe([], "Incomplete guides:\n  ".implode("\n  ", $missing));
});

it('says what changes elsewhere, which is the question nothing else answers', function () {
    // `affects` is the field that earns the panel. A guide that only restates the screen's title is
    // decoration; this asserts every one of them tells the operator what moves downstream.
    foreach (ResourceGuides::GUIDES as $key) {
        expect(ResourceGuides::affects($key))->not->toBeEmpty("'{$key}' does not say what it affects");
    }
});

it('shows the guide on a screen that has one', function () {
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
    // worth clicking.
    expect(ResourceGuides::has(LeaseResource::class))->toBeTrue()
        ->and(ResourceGuides::has(\App\Filament\Admin\Resources\Violations\ViolationResource::class))->toBeFalse()
        ->and(ResourceGuides::keyFor(\App\Filament\Admin\Resources\Violations\ViolationResource::class))->toBeNull();
});
