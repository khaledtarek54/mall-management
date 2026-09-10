<?php

use App\Filament\Admin\Resources\Assets\Pages\CreateAsset;
use App\Filament\Admin\Resources\Assets\Pages\EditAsset;
use App\Filament\Admin\Resources\Assets\AssetResource;
use App\Filament\Admin\Resources\Assets\Pages\ListAssets;
use Database\Seeders\RolesPermissionsSeeder;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;

/**
 * **OPENING A MALL FROM THE PROPERTIES LIST PUTS YOU IN THAT MALL.**
 *
 * Reported from the panel as two bugs a day apart, both of which are this one:
 *
 *  1. `/admin/VP/units/13/edit` → **404**, reached from a property page. Unit 13 is Nile Gate's.
 *  2. Once that link named the row's own mall, the same click read as *"it opens another
 *     property"* — `/admin/VP/assets/3/edit?relation=2` sending the operator to `/admin/NG/…`.
 *
 * Both are the same cause and the second is the honest one: the operator was **already looking at
 * Nile Gate**, on a page whose URL and whose switcher both said Val Plaza. Measured before this
 * fix: that URL answered **200** with *"Nile Gate Mall"* six times in the page and *"Val Plaza"*
 * eight, and nothing on screen saying which mall you were in.
 *
 * **Yardi is the standard and this repo already claimed to meet it** — `docs/benchmarks/yardi/08`
 * scores the *persistent scope selector* (*"everything you see is scoped, always, VISIBLY"*) as
 * ✅ *"Atriom already does this well"*. True of every screen but this one.
 *
 * ## Why the LINK, and not either of the other two candidates
 *
 * **Not a redirect on the record page.** Tried first, and measured worse three ways: it handed back
 * a null Livewire component to any test mounting the page for a foreign record (breaking one),
 * it left DELETING a property on a **404** — the redirect had already moved you into the mall you
 * were archiving — and it fired on every ordinary open to correct a link nobody had fixed. Naming
 * the property in the LINK is also what this codebase already does for the same problem
 * (`App\Support\Filament\PropertyLink`, and `tenant: $this->getOwnerRecord()` on this very page's
 * tabs).
 *
 * **Not narrowing the list to the selected mall** (the owner's first instinct, and a reasonable
 * one — it removes the confusing path outright). Measured cost: a trashed mall can never BE the
 * selected tenant — `getTenants()` excludes soft-deleted and `canAccessTenant()` refuses one — so
 * an archived property would appear in **no list at all**, while this table ships `TrashedFilter`
 * and this page a `RestoreAction` precisely to bring one back. It would also take the portfolio
 * view away and strand a newly created mall, which is the reason the query is portfolio-wide and
 * says so in a comment.
 */
beforeEach(function () {
    $this->seed(RolesPermissionsSeeder::class);
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    ensureAllPropertiesAsset();
    $this->actingAs(makeUser('super_admin'));

    $this->selected = makeAsset(['code' => 'VP', 'name' => 'Val Plaza']);
    $this->other = makeAsset(['code' => 'NG', 'name' => 'Nile Gate Mall']);
});

it('links a mall at its own property, not the one in the switcher', function () {
    asTenant($this->selected, function () {
        Livewire::test(ListAssets::class)
            ->assertTableActionHasUrl('edit', url("/admin/NG/assets/{$this->other->id}/edit"), $this->other);
    });
});

it('opens that mall when the ROW is clicked', function () {
    // The row and the button are two affordances. The row's href comes from `RowClickTarget`,
    // which resolves the table's own `edit` action — so it inherits the fix, and would have
    // inherited the bug just as silently.
    asTenant($this->selected, function () {
        $url = Livewire::test(ListAssets::class)->instance()->getTable()->getRecordUrl($this->other);

        expect($url)->toContain("/admin/NG/assets/{$this->other->id}/edit")
            ->and($url)->not->toContain('/admin/VP/');
    });
});

it('still names the selected mall when that is the row you clicked', function () {
    // The CONTROL. A link that always named some OTHER mall would satisfy the assertions above.
    asTenant($this->selected, function () {
        Livewire::test(ListAssets::class)
            ->assertTableActionHasUrl('edit', url("/admin/VP/assets/{$this->selected->id}/edit"), $this->selected);
    });
});

it('lands on a page that actually renders, in that mall', function () {
    // **A CONTROL, not a tooth** — it fetches a hand-written URL and passes with the fix removed.
    // It earns its place by proving the destination the teeth above NAME is one that actually
    // renders, which asserting on a string cannot.
    $this->get("/admin/NG/assets/{$this->other->id}/edit")
        ->assertOk()
        ->assertSee('Nile Gate Mall');
});

it('keeps an ARCHIVED mall reachable, and does NOT name it as the tenant', function () {
    // **THE TOOTH THIS FIX'S FIRST VERSION FAILED.** A trashed mall can never be entered —
    // `canAccessTenant()` refuses it and `getTenants()` excludes it — so naming it in the link is a
    // 404 from `IdentifyTenant`, and `RestoreAction` lives on the page that link opens. Measured
    // before the guard: the row linked `/admin/NG/…` → 404, where the old link `/admin/VP/…` → 200,
    // because `getRecordRouteBindingEloquentQuery()` strips `SoftDeletingScope` exactly so an
    // archived mall stays openable.
    //
    // That is the same cost that ruled out narrowing this list, arrived at by a different route in
    // the same screen — and every test passed while it was broken.
    $this->other->delete();

    asTenant($this->selected, function () {
        $table = Livewire::test(ListAssets::class)
            ->set('tableFilters.trashed.value', '1')
            ->instance()->getTable();

        // `toContain()` takes further VALUES, never a message — passing one asserts the array
        // contains that string, which is the false-pass trap this repo records for Pest matchers.
        $ids = $table->getRecords()->pluck('id')->all();
        expect($ids)->toContain($this->other->id);

        // It falls back to the mall in scope, which is the one segment that renders.
        $url = $table->getRecordUrl($this->other->fresh());
        expect($url)->toContain("/admin/VP/assets/{$this->other->id}/edit")
            ->and($url)->not->toContain('/admin/NG/');
    });

    // And the page it points at actually opens, which is what makes Restore reachable.
    $this->get("/admin/VP/assets/{$this->other->id}/edit")->assertOk();
});

it('still lists every property the operator holds', function () {
    // The other control: the portfolio view is the point of this screen, and it is how you reach a
    // mall other than the active one.
    asTenant($this->selected, function () {
        $ids = Livewire::test(ListAssets::class)->instance()->getTable()->getRecords()->pluck('id')->all();

        expect($ids)->toContain($this->selected->id)->toContain($this->other->id);
    });
});

it('refuses a property the operator does not hold', function () {
    // **A CONTROL, not a tooth** — this is pre-existing `canAccessTenant` authorization and passes
    // with the fix removed. It is here so a change to the link can never be read as widening what a
    // restricted operator can reach.
    $this->actingAs(makeUser('manager', [$this->selected->id]));

    $this->get("/admin/NG/assets/{$this->other->id}/edit")->assertNotFound();
    $this->get("/admin/VP/assets/{$this->selected->id}/edit")->assertOk();
});

it('lands you in the mall you just created, not the one you were in', function () {
    // The door the register's link does not reach, and the one `AssetResource`'s own docblock
    // singles out: *"a new mall is never the active tenant"*. `CreateRecord` builds its redirect
    // from the switcher, so creating Nile Gate while Val Plaza was selected landed on
    // `/admin/VP/assets/{new}/edit` — the reported bug at the moment of creation.
    asTenant($this->selected, function () {
        $url = Livewire::test(CreateAsset::class)
            ->fillForm([
                'name' => 'Cairo Festival', 'code' => 'CF', 'type' => 'mall', 'city' => 'Cairo',
                'country' => 'EG', 'currency' => 'EGP', 'total_area_sqm' => 1000,
                'leasable_area_sqm' => 800, 'is_active' => true,
            ])
            ->call('create')
            ->assertHasNoFormErrors()
            ->effects['redirect'] ?? null;

        expect($url)->toContain('/admin/CF/assets/')
            ->and($url)->not->toContain('/admin/VP/assets/');
    });
});

it('opens the mall a global search result names', function () {
    // The other door review found: `HasGlobalSearch` builds its URL with no tenant, so ⌘K → "Nile"
    // → click reproduced the reported bug exactly.
    asTenant($this->selected, function () {
        $results = AssetResource::getGlobalSearchResults('Nile');

        expect($results)->not->toBeEmpty();
        expect($results->first()->url)->toContain("/admin/NG/assets/{$this->other->id}/edit")
            ->and($results->first()->url)->not->toContain('/admin/VP/');
    });
});

it('sends you to a mall you can still enter after archiving one', function () {
    // Deleting the mall you are standing in redirected to that mall's own index — and
    // `IdentifyTenant` refuses a trashed tenant. Measured before the fix: **404**. Reachable
    // before, but the register's link made it the default path.
    asTenant($this->other, function () {
        $redirect = Livewire::test(EditAsset::class,
            ['record' => $this->other->id])
            ->callAction('delete')
            ->effects['redirect'] ?? null;

        expect($redirect)->not->toContain('/admin/NG/');
    });

    // And it is somewhere that opens, which is the half a string assertion cannot prove.
    $this->get('/admin/VP/assets')->assertOk();
});
