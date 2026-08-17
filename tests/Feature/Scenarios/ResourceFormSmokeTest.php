<?php

use Database\Seeders\AccountMappingSeeder;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\RolesPermissionsSeeder;
use Filament\Facades\Filament;
use Filament\Resources\Pages\CreateRecord;
use Livewire\Livewire;

/**
 * **Every resource's Create page must actually mount.**
 *
 * WHY THIS EXISTS. `AdminPageSmokeTest` proves every admin PAGE renders. Nothing proved the same
 * for a resource's form, and a measurement on 2026-08-17 found **22 of 93 Create/Edit pages were
 * referenced by no test at all** — `CreateTenant` and `CreateVendor` among them. Any of them could
 * have thrown on open and nothing would have said so; a form nobody automated is a form whose first
 * reader is the operator who needed it.
 *
 * The gap is structural rather than accidental, and it produced two of this session's bugs: this
 * suite drives SERVICES, and a form schema is code that only runs when a form runs.
 * `InvoiceForm::prefillItemsFromLease()` shipped with a closure missing `use ($get)` — an
 * `Undefined variable` on PHP 8, a 500 on the first click of raising an invoice — and survived five
 * days of a green suite because every test of that behaviour called the service.
 *
 * ## What this covers, and what it deliberately does not
 *
 * **Create pages only.** An Edit page needs a valid, saved record of its own model, and
 * manufacturing one for fifty models generically is a fixture project that would fail for reasons
 * unrelated to rendering. Most resources share ONE schema class between Create and Edit, so
 * mounting Create exercises it; where they genuinely differ, the Edit path stays uncovered and is
 * named here rather than implied.
 *
 * **Mount, not interaction.** This asserts the schema builds and renders. It would NOT have caught
 * the `$get` bug above, which fired from `afterStateUpdated` — that needs a form to be driven, and
 * `MoneyFormInteractionSmokeTest` does it for the forms where a 500 costs most. Saying so plainly
 * matters: a gate that is believed to cover more than it does is worse than none, which is the
 * lesson already recorded about this project's conformance gates.
 *
 * **Discovery, not a list.** Pages come from the panel's own registry, so resource #52 is covered
 * the day it is written — which a hand-kept list would not manage, and is exactly why the 22 were
 * uncovered in the first place.
 *
 * **Both panels.** The portal was the half nobody looked at: its forms carry four entity pickers
 * whose scoping is genuinely different — `TenantScope::visibleAssetIds()` is null there, because the
 * authenticated party is a `TenantUser` and not a `User`, so the tenant clamp written at each call
 * site IS the isolation rather than an addition to it. A picker that quietly stopped narrowing there
 * would leak another retailer's space, and until this test the only thing exercising those forms was
 * the advisory Playwright suite.
 */
beforeEach(function () {
    $this->seed(RolesPermissionsSeeder::class);
    // The chart + posting map, because `atriom:install` lays both down on a first deploy — this is
    // the shape of a real environment, and several accounting forms resolve a posting role on mount.
    $this->seed(ChartOfAccountsSeeder::class);
    $this->seed(AccountMappingSeeder::class);

    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $this->asset = makeAsset();
    // super_admin: this asks "does the form render", not "who may open it" — `AuthorizationMatrixTest`
    // owns the second question, and a role-gated 403 here would read as a rendering failure.
    $this->actingAs(makeUser('super_admin', [$this->asset->id]));
    Filament::setTenant($this->asset);
});

afterEach(fn () => Filament::setTenant(null, isQuiet: true));

/** @return array<int, class-string<CreateRecord>> */
function panelCreatePages(string $panel): array
{
    $pages = [];

    foreach (Filament::getPanel($panel)->getResources() as $resource) {
        foreach ($resource::getPages() as $registration) {
            $page = $registration->getPage();

            if (is_subclass_of($page, CreateRecord::class)) {
                $pages[] = $page;
            }
        }
    }

    return array_values(array_unique($pages));
}

it('mounts every admin resource Create form without erroring', function () {
    $pages = panelCreatePages('admin');

    // The sweep must find something. One that silently matched zero pages would pass for ever
    // while covering nothing — this project has shipped exactly that gate before.
    expect(count($pages))->toBeGreaterThan(25);

    $failed = [];

    foreach ($pages as $page) {
        try {
            Livewire::test($page)->assertOk();
        } catch (Throwable $e) {
            $failed[] = class_basename($page).' — '.str($e->getMessage())->limit(200);
        }
    }

    expect($failed)->toBe([], implode("\n  ", array_merge(
        ['These resource Create forms did not mount:'],
        $failed,
    )));
});

it('mounts every portal resource Create form without erroring', function () {
    // A retailer, not an operator: the portal authenticates a `TenantUser` on its own guard, and
    // `Filament::getTenant()` is never an Asset there. That difference is the reason this case
    // exists rather than being folded into the one above — the portal's pickers are scoped by the
    // tenant clamp at each call site, and nothing else.
    $tenant = makeTenant(['name' => 'Portal Smoke Retail']);
    makeLease(makeUnit($this->asset, ['code' => 'PS-01']), $tenant);

    Filament::setTenant(null, isQuiet: true);
    Filament::setCurrentPanel(Filament::getPanel('portal'));
    $this->actingAs(makeTenantUser($tenant, isAdmin: true), 'portal');

    $pages = panelCreatePages('portal');

    expect($pages)->not->toBeEmpty('The portal sweep found no Create pages — it is matching nothing.');

    $failed = [];

    foreach ($pages as $page) {
        try {
            Livewire::test($page)->assertOk();
        } catch (Throwable $e) {
            $failed[] = class_basename($page).' — '.str($e->getMessage())->limit(200);
        }
    }

    Filament::setCurrentPanel(Filament::getPanel('admin'));

    expect($failed)->toBe([], implode("\n  ", array_merge(
        ['These portal Create forms did not mount:'],
        $failed,
    )));
});
