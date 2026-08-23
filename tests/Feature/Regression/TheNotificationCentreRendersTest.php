<?php

use App\Models\Asset;
use Database\Seeders\DatabaseSeeder;
use Filament\Facades\Filament;
use Livewire\Livewire;

/**
 * **The notification centre must RENDER, and its unread marker stays unlabelled.**
 *
 * EG-32 turned on Filament's column manager globally — `TableDefaults::register()` chains
 * `->reorderableColumns()` onto every table in the app. `HasColumnManager` throws a bare
 * `LogicException` on **any** column with a blank label, and the notification centre's unread marker
 * is deliberately blank: the icon IS the column, and a header would spend exactly the width the
 * design exists to save.
 *
 * So `/admin/AW/notifications` returned **HTTP 500** — for every user, on both panels, on a page the
 * bell in the top bar links to. CLAUDE.md had already written down that reordering must stay off
 * until a label sweep was done; the sweep was not done.
 *
 * The concern opts out with `->reorderableColumns(false)` rather than inventing a label, because the
 * blank header is the deliberate half and reordering a fixed four-column bespoke list is the
 * worthless half.
 *
 * ## Why a test and not just the fix
 *
 * Because the failure is invisible to everything in the push loop. No Pest test rendered this page,
 * `ResourceFormSmokeTest` sweeps Create forms and this is not a resource, and the tenancy sweep calls
 * `getTableRecords()` — which runs the QUERY; the throw is in the column manager, during RENDER. The
 * browser suite did catch it, and the browser suite had itself been dead for a month.
 *
 * Both panels, because the concern is shared and a fix applied to one would look complete.
 */
beforeEach(function () {
    // `DatabaseSeeder`: the roles catalogue as well as the demo data — `makeUser('super_admin')`
    // needs the role to exist, and the demo alone does not create it.
    $this->seed(DatabaseSeeder::class);
});

it('renders the admin notification centre', function () {
    $asset = Asset::query()->where('code', '!=', Asset::ALL_PROPERTIES_CODE)->firstOrFail();

    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $this->actingAs(makeUser('super_admin', [$asset->id]));
    Filament::setTenant($asset);

    // `assertOk()` alone is not enough — the table has to be BUILT for the column manager to run,
    // which is why this asserts on rendered content rather than just a 200.
    Livewire::test(App\Filament\Admin\Pages\NotificationCenter::class)->assertOk();

    Filament::setTenant(null, isQuiet: true);
});

it('renders the portal notification centre', function () {
    $tenantUser = App\Models\TenantUser::query()->firstOrFail();

    Filament::setCurrentPanel(Filament::getPanel('portal'));
    $this->actingAs($tenantUser, 'portal');

    Livewire::test(App\Filament\Portal\Pages\NotificationCenter::class)->assertOk();
});
