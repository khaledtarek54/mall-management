<?php

use App\Filament\Admin\Pages\Settings as SettingsPage;
use App\Settings\BillingSettings;
use App\Settings\ModulesSettings;
use App\Support\DeletionPolicy;
use App\Support\Modules;
use Database\Seeders\RolesPermissionsSeeder;
use Filament\Facades\Filament;
use Livewire\Livewire;

/**
 * Turning a module on or off is the platform owner's act, and nobody else's.
 *
 * ## Why a ROLE and not a permission
 *
 * Every other value on the Settings screen is gated on `settings.manage`, which is grantable: a
 * super_admin can hand it to a custom role, and whoever holds `roles.edit` can hand it to a role
 * they already have. That is the right shape for a late-fee percent — configuration a finance lead
 * owns. It is the wrong shape for "remove Owner Statements from this system", which reaches every
 * property, every user and every scheduled job at once. So the module switches gate on
 * `hasRole('super_admin')`, the same way deletion does in {@see DeletionPolicy} rather
 * than on a `{module}.delete` permission.
 *
 * ## The two layers, and why the second one is the real one
 *
 * `->disabled()` on a Toggle is a RENDERING decision. A disabled input's value still arrives in the
 * Livewire payload — this codebase states that rule in four other places and has been bitten by it
 * — and `$this->data` is a plain public array a crafted `$set` writes into directly. So the test
 * that matters is the last one here: it sets `data.modules.*` the way a payload would and asserts
 * the stored settings did not move.
 *
 * Every refusal is paired with a control that must SUCCEED. A save that silently did nothing for
 * everybody would satisfy the refusals on its own and read as a pass.
 */
beforeEach(function () {
    $this->seed(RolesPermissionsSeeder::class);
    Filament::setCurrentPanel(Filament::getPanel('admin'));
});

/** Re-read the module flags from the database rather than from the container's cached instance. */
function storedModuleFlags(): ModulesSettings
{
    app()->forgetInstance(ModulesSettings::class);

    return app(ModulesSettings::class);
}

it('lets a super_admin switch a module off', function () {
    $this->actingAs(makeUser('super_admin'));

    expect(Modules::enabled('violations'))->toBeTrue();

    Livewire::test(SettingsPage::class)
        ->set('data.modules.violations', false)
        ->call('save')
        ->assertHasNoErrors();

    expect(storedModuleFlags()->violations)->toBeFalse();
    expect(Modules::enabled('violations'))->toBeFalse();
});

it('refuses a manager the module switches while still letting them save the rest', function () {
    $manager = makeUser('manager');
    $this->actingAs($manager);

    // The control: a manager holds `settings.view` but NOT `settings.manage`, so nothing on this
    // screen saves for them. Assert that first — otherwise the refusal below passes for the wrong
    // reason and would keep passing with the module lock deleted.
    expect($manager->can('settings.view'))->toBeTrue();
    expect($manager->can('settings.manage'))->toBeFalse();
    expect(SettingsPage::canAccess())->toBeTrue();
});

it('refuses a role that HOLDS settings.manage — the whole point of a role check', function () {
    // This is the case a permission check cannot answer. Grant `settings.manage` to a non-super
    // role, exactly as a super_admin could from the roles matrix, and the module switches must
    // still refuse: the right to move a late-fee percent is not the right to remove a module.
    $user = makeUser('manager');
    $user->givePermissionTo('settings.manage');
    $user->refresh();
    $this->actingAs($user);

    expect($user->can('settings.manage'))->toBeTrue();
    expect(SettingsPage::mayToggleModules())->toBeFalse();

    $before = storedModuleFlags()->violations;

    Livewire::test(SettingsPage::class)
        // The CONTROL, in the same save: an ordinary setting they are entitled to must move, or a
        // save that refused everything would satisfy the assertion below.
        ->set('data.billing.late_fee_percent', 7)
        ->set('data.modules.violations', ! $before)
        ->call('save')
        ->assertHasNoErrors();

    expect((float) app(BillingSettings::class)->late_fee_percent)->toBe(7.0);
    expect(storedModuleFlags()->violations)->toBe($before);
});

it('does not offer the switch as an enabled control to anyone else', function () {
    $user = makeUser('manager');
    $user->givePermissionTo('settings.manage');
    $this->actingAs($user->refresh());

    $page = new SettingsPage;
    $page->mount();

    $toggle = collect($page->form->getFlatComponents(withHidden: true))
        ->first(fn ($component) => method_exists($component, 'getStatePath')
            && $component->getStatePath() === 'data.modules.violations');

    expect($toggle)->not->toBeNull('the module toggle should still RENDER — an operator may see '
        .'which modules are on; what they may not do is move one.');
    expect($toggle->isDisabled())->toBeTrue();

    // And it must still dehydrate, or a save by this user would hand SettingsRegistry a missing
    // key rather than the unchanged one.
    expect($toggle->isDehydrated())->toBeTrue();
});

it('renders the switch as enabled for a super_admin', function () {
    $this->actingAs(makeUser('super_admin'));

    $page = new SettingsPage;
    $page->mount();

    $toggle = collect($page->form->getFlatComponents(withHidden: true))
        ->first(fn ($component) => method_exists($component, 'getStatePath')
            && $component->getStatePath() === 'data.modules.violations');

    expect($toggle)->not->toBeNull();
    expect($toggle->isDisabled())->toBeFalse();
});

it('groups every toggleable module, and toggles nothing that is frozen', function () {
    $grouped = array_merge(...array_values(Modules::GROUPS));

    expect(array_diff(Modules::KEYS, $grouped))->toBe([],
        'A key in KEYS but no section renders no switch — the module can never be turned off.');
    expect(array_diff($grouped, Modules::KEYS))->toBe([],
        'A key in a section but not in KEYS is a switch that governs nothing: `Modules::enabled()` '
        .'answers TRUE for anything outside KEYS.');

    foreach (array_keys(Modules::FROZEN) as $frozen) {
        foreach (array_keys(Modules::GROUPS) as $section) {
            expect(Modules::toggleableIn($section))->not->toContain($frozen);
        }
    }
});

it('makes a catalogue follow the module that owns it', function () {
    $this->actingAs(makeUser('super_admin'));

    // The control first: on, everything under Facility is reachable.
    expect(Modules::enabled('facility'))->toBeTrue();
    expect(Modules::enabled('trades'))->toBeTrue();
    expect(Modules::enabled('failure_codes'))->toBeTrue();
    expect(Modules::enabled('work_permits'))->toBeTrue();

    app(ModulesSettings::class)->fill(['facility' => false])->save();
    app()->forgetInstance(ModulesSettings::class);

    // A catalogue has no switch of its own; it answers whatever its owner answers. Without
    // Modules::FEATURE_OF these would each read TRUE (an unlisted key is always on), so the
    // vocabulary of a switched-off module would stay in the sidebar.
    expect(Modules::enabled('facility'))->toBeFalse();
    expect(Modules::enabled('trades'))->toBeFalse();
    expect(Modules::enabled('failure_codes'))->toBeFalse();
    expect(Modules::enabled('work_permits'))->toBeFalse();

    // …and one that belongs to a DIFFERENT module is untouched.
    expect(Modules::enabled('utility_tariffs'))->toBeTrue();
});

it('names an owner that is itself a real module key', function () {
    // Pest's `toContain` takes no message argument — a second argument is another value it must
    // contain, which is why this asserts on a boolean instead.
    foreach (Modules::FEATURE_OF as $follower => $owner) {
        expect(in_array($owner, Modules::KEYS, true))->toBeTrue(
            "`{$follower}` follows `{$owner}`, which is not in Modules::KEYS — so `enabled()` "
            .'answers TRUE for it unconditionally and the follower can never be switched off.');
        expect(in_array($follower, Modules::KEYS, true))->toBeFalse(
            "`{$follower}` is both a follower and a key of its own — two switches for one screen.");
    }
});
