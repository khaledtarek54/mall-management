<?php

use App\Filament\Admin\Pages\Settings as SettingsPage;
use App\Settings\BillingSettings;
use App\Settings\ModulesSettings;
use App\Support\Modules;
use App\Support\SettingsRegistry;
use Database\Seeders\RolesPermissionsSeeder;
use Filament\Facades\Filament;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;
use Spatie\LaravelSettings\Settings;

/**
 * Every setting the code reads must be reachable from the screen, and pressing Save must move it.
 *
 * A settings class is a property bag that services read on every relevant transaction. A property
 * with no field is a number only a deploy can change — and there is nothing on the screen to say
 * so, which is what makes it worse than a missing feature. When this gate was written it found
 * three: `auto_apply_tenant_credit` (whether tenant credit settles invoices by itself),
 * `holdover_default_rate_pct` (the uplift once a lease runs past its expiry) and
 * `levy_rate_percent` — which CLAUDE.md described as "configurable".
 *
 * The other half is the inert field: rendered, accepts a value, says "Saved ✓", changes nothing.
 * That happened because the page mapped every field by hand in `mount()` and again in `save()`,
 * beside the schema — three places to keep in step. `SettingsRegistry` derives both from the
 * classes, so this gate now only has to prove the remaining link: that a field EXISTS for each
 * property, and that a value put into the form comes back out of the settings object.
 */
beforeEach(function () {
    $this->seed(RolesPermissionsSeeder::class);
    $this->actingAs(makeUser('super_admin'));
    Filament::setCurrentPanel(Filament::getPanel('admin'));
});

/** Every state path the page's schema actually renders. */
function renderedSettingPaths(): array
{
    $page = new SettingsPage;
    $page->mount();

    return collect($page->form->getFlatComponents(withHidden: true))
        ->map(fn ($component) => method_exists($component, 'getStatePath') ? $component->getStatePath() : null)
        ->filter()
        ->map(fn (string $path) => str_replace('data.', '', $path))
        ->values()
        ->all();
}

it('renders a field for every setting the code can read', function () {
    $rendered = renderedSettingPaths();
    $missing = [];

    foreach (SettingsRegistry::classes() as $class) {
        foreach (array_keys(SettingsRegistry::propertiesOf($class)) as $name) {
            $key = $class::group().'.'.$name;

            // An ARRAY setting may legitimately be edited as one field per key —
            // `accounting.document_prefixes.invoice` and friends — which is far better UX than a
            // raw key/value box and still means the operator can change the setting. Accepting a
            // nested path is accurate rather than a loosening: the question this gate asks is
            // "can it be changed from a screen", and it can.
            $covered = in_array($key, $rendered, true)
                || collect($rendered)->contains(fn (string $path) => str_starts_with($path, $key.'.'));

            // A FROZEN module's flag is deliberately unreachable from the screen
            // (App\Support\Modules::FROZEN). `Modules::enabled()` answers false before the row is
            // consulted, so the toggle could not change anything — and a switch that does nothing
            // is worse than an absent one, because it advertises unfinished work as a feature the
            // operator is choosing to leave off. This is the ONE shape of unreachable setting that
            // is correct, and it is derived from the registry rather than listed here.
            if (! $covered && $class === ModulesSettings::class && Modules::frozen($name)) {
                continue;
            }

            if (! $covered) {
                $missing[] = $key;
            }
        }
    }

    expect($missing)->toBe([], implode("\n", array_merge(
        ['These settings are read by the application and cannot be changed from any screen:'],
        $missing,
        ['', 'Add a field to App\Filament\Admin\Pages\Settings. A setting with no field is a'],
        ['number only a deploy can change, with nothing on the screen to say so.'],
    )));
});

it('registers every settings class that exists', function () {
    // The Settings page derives its tabs from `config/settings.php`, so a class missing from that
    // list is a whole tab that does not exist. `TaxSettings` was in exactly that state — read on
    // every taxable supply, absent from the registry.
    $declared = collect(glob(app_path('Settings/*.php')))
        ->map(fn (string $file) => 'App\\Settings\\'.basename($file, '.php'))
        ->filter(fn (string $class) => is_subclass_of($class, Settings::class))
        ->values()
        ->all();

    expect(array_diff($declared, SettingsRegistry::classes()))->toBe([],
        'These settings classes are not registered in config/settings.php');
});

it('writes every setting back through the real page', function () {
    // The inert-field killer, driven end to end: put a changed value into the form for EVERY
    // property, press the real Save, and read the settings objects back. A field the page renders
    // but never persists fails here — which a structural check alone would go green over.
    $state = SettingsRegistry::currentState();
    $expected = [];

    foreach (SettingsRegistry::classes() as $class) {
        foreach (SettingsRegistry::propertiesOf($class) as $name => $property) {
            $current = $state[$class::group()][$name];

            // A value that is valid for the field and different from the current one. Strings are
            // left alone: a time picker, a tax code and a registration number all have their own
            // shape, and feeding them nonsense would test the validator rather than the wiring.
            $new = match ($property->getType()?->getName()) {
                'bool' => ! $current,
                'int' => (int) $current + 1,
                'float' => (float) $current + 1.0,
                default => null,
            };

            if ($new === null) {
                continue;
            }

            // Same exemption as above: no field, so nothing to fill and nothing to persist.
            if ($class === ModulesSettings::class && Modules::frozen($name)) {
                continue;
            }

            $state[$class::group()][$name] = $new;
            $expected[$class][$name] = $new;
        }
    }

    Livewire::test(SettingsPage::class)
        ->fillForm($state)
        ->call('save')
        ->assertHasNoFormErrors();

    $inert = [];

    foreach ($expected as $class => $properties) {
        $settings = app($class)->refresh();

        foreach ($properties as $name => $value) {
            if ($settings->{$name} != $value) {
                $inert[] = $class::group().'.'.$name.' stayed '.var_export($settings->{$name}, true)
                    .' after the form was saved with '.var_export($value, true);
            }
        }
    }

    expect($inert)->toBe([], implode("\n", array_merge(
        ['These fields render and accept a value, and saving does nothing with it:'],
        $inert,
    )));
});

it('leaves a setting alone when the submitted state does not mention it', function () {
    // Absent must not mean "blank it". `getState()` returns what the form holds, so a tab a role
    // cannot see, or a field hidden behind a toggle, would otherwise be wiped by opening the page
    // and pressing Save.
    $billing = app(BillingSettings::class);
    $billing->late_fee_percent = 7.5;
    $billing->save();

    SettingsRegistry::persist(['billing' => ['late_fee_grace_days' => 3]]);

    expect(app(BillingSettings::class)->refresh()->late_fee_percent)->toBe(7.5)
        ->and(app(BillingSettings::class)->late_fee_grace_days)->toBe(3);
});

it('records who changed a setting, and from what', function () {
    // `settings.manage` gates who MAY. Nothing recorded who DID — which, in a system where money
    // records are undeletable and the charge-code catalogue is activity-logged, left these numbers
    // as the one place a figure could move leaving no history.
    Livewire::test(SettingsPage::class)
        ->fillForm(array_replace_recursive(
            SettingsRegistry::currentState(),
            ['billing' => ['late_fee_percent' => 9.5]],
        ))
        ->call('save');

    $entry = Activity::where('log_name', 'settings')->latest('id')->first();

    expect($entry)->not->toBeNull()
        ->and($entry->properties['changes'])->toHaveKey('billing.late_fee_percent')
        ->and($entry->properties['changes']['billing.late_fee_percent']['new'])->toBe(9.5);
});

it('writes no audit entry when nothing changed', function () {
    // An audit trail that logs every visit is one nobody reads.
    Livewire::test(SettingsPage::class)
        ->fillForm(SettingsRegistry::currentState())
        ->call('save');

    expect(Activity::where('log_name', 'settings')->count())->toBe(0);
});
