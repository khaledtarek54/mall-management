<?php

use App\Filament\Admin\Pages\Workflows;
use App\Settings\ModulesSettings;
use Database\Seeders\RolesPermissionsSeeder;
use Filament\Facades\Filament;
use Livewire\Livewire;

/**
 * THE WORKFLOW MAP SPOKE ENGLISH, AND IT WAS GATED ON A MODULE IT DOES NOT DRAW.
 *
 * Two findings in one file because they are two halves of the same omission: the page held its own
 * private opinion of what a status is CALLED and of which module it BELONGS to, and both opinions
 * were wrong in a way that is invisible from the page itself.
 *
 * **SW-096.** `humanize()` was `ucwords(str_replace('_', ' ', $state))`, so an operator working the
 * Arabic panel read `Awaiting Tenant`, `In Progress`, `Ordered` — the raw database value in English
 * typography — while three catalogues already name all eighteen of these states in both languages
 * and are what the request board, the work-order list and the purchase-request list render.
 *
 * **SW-104.** `canAccess()` gated the whole page on `Modules::enabled('approvals')`. `approvals`
 * owns the value-threshold approval LADDER (`approval_rules`, via `Modules::FEATURE_OF`) and is not
 * one of the three state machines drawn here, so switching that ladder off took the tenant-request
 * and work-order maps with it. The mirror fault was that `rows()` drew the purchase-request machine
 * with `modules.procurement` switched off, describing a module the install no longer runs — and the
 * permission list omitted `facility.view` while one of the three IS the work-order machine.
 */
beforeEach(function () {
    $this->seed(RolesPermissionsSeeder::class);

    // The finding is about what an ARABIC operator reads, so the whole file runs in Arabic. The
    // module tests below assert on row KEYS, which are locale-independent.
    app()->setLocale('ar');

    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $this->asset = makeAsset();
    $this->actingAs(makeUser('super_admin', [$this->asset->id]));
    Filament::setTenant($this->asset);
});

afterEach(function () {
    Filament::setTenant(null, isQuiet: true);
    app()->setLocale('en');
});

it('names every state from the catalogue the screens label from, not in raw English', function () {
    // The premise, asserted rather than assumed: the catalogue really does differ from what
    // `ucwords(str_replace('_', ' ', …))` produced, so this case cannot pass for the old reason.
    $fromCatalogue = (string) __('admin.statuses.tenant_request.awaiting_tenant');

    expect($fromCatalogue)->not->toBe('Awaiting Tenant');

    $page = Livewire::test(Workflows::class);

    // Rendered, not merely computed: the badge column prints the value verbatim, and English chrome
    // on the Arabic panel is the whole finding.
    $page->assertDontSee('Awaiting Tenant');

    $states = tableRows($page)->pluck('state')->all();

    expect($states)->toContain($fromCatalogue);
    expect($states)->not->toContain('Awaiting Tenant');
});

it('names the NEXT states from the catalogue too, not only the row it is on', function () {
    // `to` is an array rendered as one badge per allowed next status, and it went through the same
    // humaniser. Fixing only the `state` column would leave half the page in English.
    $fromCatalogue = (string) __('admin.statuses.tenant_request.awaiting_tenant');

    $rows = tableRows(Livewire::test(Workflows::class));
    $nextStates = $rows->get('tenant_request:acknowledged')['to'] ?? [];

    expect($nextStates)->toContain($fromCatalogue);
    expect($nextStates)->not->toContain('Awaiting Tenant');
});

it('keeps the tenant-request and work-order maps when the approval ladder is switched off', function () {
    // `modules.approvals` governs the value-threshold approval ladder. This page draws three STATE
    // MACHINES and none of them is that ladder, so switching it off must change nothing here.
    app(ModulesSettings::class)->fill(['approvals' => false])->save();
    app()->forgetInstance(ModulesSettings::class);

    expect(Workflows::canAccess())->toBeTrue();

    $keys = tableRows(Livewire::test(Workflows::class))->keys()->all();

    expect($keys)->toContain('tenant_request:submitted');
    expect($keys)->toContain('work_order:open');
    expect($keys)->toContain('purchase_request:draft');
});

it('drops a workflow whose own module is switched off', function () {
    // The control first — with procurement on, its machine is drawn.
    $before = tableRows(Livewire::test(Workflows::class))->keys()->all();

    expect($before)->toContain('purchase_request:draft');

    app(ModulesSettings::class)->fill(['procurement' => false])->save();
    app()->forgetInstance(ModulesSettings::class);

    $after = tableRows(Livewire::test(Workflows::class))->keys()->all();

    expect($after)->not->toContain('purchase_request:draft');
    // …and the other two are untouched, or "filter by module" would just be a broken page.
    expect($after)->toContain('tenant_request:submitted');
    expect($after)->toContain('work_order:open');
});

it('refuses the page when every workflow it maps is switched off', function () {
    // The gate must still bite. A page reachable by everyone is a page with no lock, which is what
    // `EveryRoleMeetsEveryScreenTest` exists to catch — and replacing one wrong module key with no
    // key at all would be exactly that.
    app(ModulesSettings::class)
        ->fill(['requests' => false, 'facility' => false, 'procurement' => false])
        ->save();
    app()->forgetInstance(ModulesSettings::class);

    expect(Workflows::canAccess())->toBeFalse();
});

it('refuses an operator who works none of the three workflows', function () {
    // `accounting` holds none of `requests.view`, `facility.view`, `procurement.view` (checked
    // against RolesPermissionsSeeder's grant list, 2026-09-03). The permission half of the gate is
    // a union, not an absence.
    $this->actingAs(makeUser('accounting', [$this->asset->id]));

    expect(Workflows::canAccess())->toBeFalse();
});
