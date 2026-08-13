<?php

use App\Filament\Admin\Pages\VatReturn;
use App\Models\AccountMapping;
use Database\Seeders\AccountMappingSeeder;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\RolesPermissionsSeeder;
use Filament\Facades\Filament;
use Livewire\Livewire;

/**
 * **The VAT return refuses when the chart cannot answer — it does not 500, and it does not show 0.**
 *
 * WHY THIS EXISTS. `AccountResolver` refuses an unmapped posting role with a `DomainException`,
 * which this system renders as a toast everywhere else (`bootstrap/app.php`). Not on this page: the
 * refusal is raised while the TABLE renders, so Blade wraps it in a `ViewException` and the handler
 * that recognises a refusal never sees one. The operator got a raw 500 on the one report Egypt
 * requires monthly.
 *
 * An incomplete posting map is not an exotic state — `ConfigurationHealth` has a check for exactly
 * it, and the operator's real Egyptian chart of accounts is still to be loaded, so remapping is
 * ahead of us rather than behind.
 *
 * **The second assertion is the one that matters.** The obvious repair — catch it and let the
 * figures fall back to zero — would be worse than the crash. A VAT return reading 0.00 looks
 * answered, is a filing position someone can sign, and nothing on screen distinguishes "no supplies
 * this period" from "the account is unmapped". So the rows go away entirely and the reason takes
 * their place.
 *
 * Found by `AdminPageSmokeTest` on its first run.
 */
beforeEach(function () {
    $this->seed(RolesPermissionsSeeder::class);
    $this->seed(ChartOfAccountsSeeder::class);
    $this->seed(AccountMappingSeeder::class);
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $this->asset = makeAsset();
    $this->actingAs(makeUser('super_admin', [$this->asset->id]));
    Filament::setTenant($this->asset);
});

afterEach(fn () => Filament::setTenant(null, isQuiet: true));

it('renders the return normally while the posting map is complete', function () {
    // The control. Without it the refusal case below would pass just as happily against a page
    // that never renders anything at all.
    Livewire::test(VatReturn::class)
        ->assertOk()
        ->assertSee(__('admin.reports.vat_net_payable_label'));
});

it('states which role is unmapped instead of throwing, and shows no figures', function () {
    AccountMapping::query()->where('key', 'vat_payable')->delete();

    $page = Livewire::test(VatReturn::class)->assertOk();

    // The reason is on the screen...
    $page->assertSee('vat_payable');

    // ...and NO return line is, because a zeroed return is a position someone could file.
    $page->assertDontSee(__('admin.reports.vat_net_payable_label'));
});
