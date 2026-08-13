<?php

use App\Filament\Admin\Resources\Invoices\Pages\EditInvoice;
use Database\Seeders\RolesPermissionsSeeder;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\App;
use Livewire\Livewire;

/**
 * **A tab's identity must not change with the reader's language.**
 *
 * WHY THIS EXISTS. Filament derives a tab's key from its LABEL — `Tab::setUp()` sets it to
 * `Str::slug(Str::transliterate($label))` — and that key is what `persistTabInQueryString()` puts in
 * the URL and what `getActiveTab()` matches on arrival. With a translated label the identity became
 * per-language: the invoice's Line Items tab was `line-items::data::tab` in English and
 * `albnwd::data::tab` in Arabic. The same tab, under two names.
 *
 * So a deep link to a tab — a bookmark, a link sent to a colleague, anything saved while the panel
 * was in the other language — matched no tab on arrival and the form opened on tab ONE instead.
 * Nothing errored: the reader simply landed on Invoice Details and concluded the lines tab had not
 * loaded. Found in a browser against real data. No server-side test could have seen it, because the
 * form state hydrates perfectly either way — it is only ever a question of which panel is shown,
 * which is why this asserts on the RENDERED key rather than on any model state.
 *
 * `FormTab::make()` now takes the translation KEY and derives the tab key from it, so the identity
 * is stable across languages by construction.
 */
beforeEach(function () {
    $this->seed(RolesPermissionsSeeder::class);
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $this->asset = makeAsset();
    $this->actingAs(makeUser('super_admin'));
    Filament::setTenant($this->asset);
});

afterEach(function () {
    App::setLocale('en');
    Filament::setTenant(null, isQuiet: true);
});

it('renders the same tab key in English and in Arabic', function () {
    $invoice = makeInvoice(makeLease(makeUnit($this->asset)));

    $render = function (string $locale) use ($invoice): string {
        App::setLocale($locale);

        return Livewire::test(EditInvoice::class, ['record' => $invoice->getRouteKey()])
            ->assertOk()
            ->html();
    };

    $en = $render('en');
    $ar = $render('ar');

    // The stable, translation-key-derived identity is present in BOTH renderings.
    expect($en)->toContain('admin-sections-items')
        ->and($ar)->toContain('admin-sections-items');

    // The control: the labels really do differ, so this is not two identical pages being compared.
    // The whole defect was that the label drove the key, so a test that could not tell the two
    // renderings apart would prove nothing.
    App::setLocale('en');
    $enLabel = __('admin.sections.items');
    App::setLocale('ar');
    $arLabel = __('admin.sections.items');

    expect($enLabel)->not->toBe($arLabel)
        ->and($en)->toContain($enLabel)
        ->and($ar)->toContain($arLabel);

    // And the OLD, locale-derived key is gone — this is what actually regressed.
    expect($ar)->not->toContain('albnwd');
});
