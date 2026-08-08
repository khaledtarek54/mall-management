<?php

use App\Filament\Admin\Resources\Leases\Pages\CreateLease;
use App\Support\FormTab;
use Database\Seeders\RolesPermissionsSeeder;
use Filament\Facades\Filament;
use Filament\Schemas\Components\Tabs\Tab;
use Livewire\Livewire;

/**
 * A tabbed form must tell you WHICH tab the error is on.
 *
 * Splitting a thirty-field form into tabs introduces a failure the long scroll did not have:
 * submit from tab 1 with a required field blank on tab 4 and the form refuses with the error
 * rendered on a panel nobody can see. **Filament v4.11.8 ships no validation-error indicator on
 * `Tabs`** — grep `Tabs.php`, `Tab.php` and their Blade and the word "error" does not appear — so
 * `App\Support\FormTab` adds one, deriving the count from the tab's own fields at render time.
 *
 * These tests exist because that derivation reaches into Filament's schema traversal
 * (`getChildComponentContainers()` → `Schema::getFlatFields()` → `getStatePath()`). If an upgrade
 * changes that API the helper degrades to no badge — silently, and correctly, so the form still
 * renders. This file is what makes the degradation loud instead.
 */
beforeEach(function () {
    $this->seed(RolesPermissionsSeeder::class);
    $this->asset = makeAsset();
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $this->actingAs(makeUser('super_admin', [$this->asset->id]));
    Filament::setTenant($this->asset);
});

afterEach(fn () => Filament::setTenant(null, isQuiet: true));

/** @return array<string, Tab> label => tab, from the mounted lease form. */
function leaseFormTabs(mixed $livewire): array
{
    $tabs = [];

    foreach ($livewire->instance()->getSchema('form')->getFlatComponents(withHidden: true) as $component) {
        if ($component instanceof Tab) {
            $tabs[$component->getLabel()] = $component;
        }
    }

    return $tabs;
}

it('splits the lease form into tabs', function () {
    $component = Livewire::test(CreateLease::class);

    expect(array_keys(leaseFormTabs($component)))->toBe([
        __('admin.sections.lease_details'),
        __('admin.sections.term'),
        __('admin.sections.financial_terms'),
        __('admin.sections.percentage_rent'),
        __('admin.sections.documents'),
    ]);
});

it('badges the tab that actually holds the invalid field, and only that tab', function () {
    $component = Livewire::test(CreateLease::class)
        // unit_id and tenant_id live on the FIRST tab; commencement_date on the second.
        // Submitting empty must attribute each error to its own tab.
        ->fillForm([])
        ->call('create')
        ->assertHasFormErrors();

    $tabs = leaseFormTabs($component);
    $livewire = $component->instance();

    $details = FormTab::errorCount($tabs[__('admin.sections.lease_details')], $livewire);
    $term = FormTab::errorCount($tabs[__('admin.sections.term')], $livewire);
    $pctRent = FormTab::errorCount($tabs[__('admin.sections.percentage_rent')], $livewire);

    // The traversal works at all — a broken traversal returns 0 everywhere and this is the
    // assertion that catches it.
    expect($details)->toBeGreaterThan(0)
        ->and($term)->toBeGreaterThan(0)
        // Percentage rent has no required field, so a blank submit must NOT badge it. A helper
        // that counted the whole error bag instead of its own fields would badge every tab.
        ->and($pctRent)->toBe(0);
});

it('clears the badge once the form is valid', function () {
    $unit = makeUnit($this->asset);
    $tenant = makeTenant();

    $component = Livewire::test(CreateLease::class)->fillForm([
        'unit_id' => $unit->id,
        'tenant_id' => $tenant->id,
        'status' => 'active',
        'commencement_date' => '2026-01-01',
        'term_months' => 12,
        'expiry_date' => '2026-12-31',
        'base_rent_monthly' => 15000,
    ])->call('create')->assertHasNoFormErrors();

    foreach (leaseFormTabs($component) as $label => $tab) {
        expect(FormTab::errorCount($tab, $component->instance()))
            ->toBe(0, "tab [{$label}] should carry no error badge");
    }
});

it('degrades to no badge rather than throwing when handed something that is not a Livewire component', function () {
    $component = Livewire::test(CreateLease::class);
    $tab = leaseFormTabs($component)[__('admin.sections.term')];

    expect(FormTab::errorCount($tab, null))->toBe(0)
        ->and(FormTab::errorCount($tab, 'not a component'))->toBe(0);
});
