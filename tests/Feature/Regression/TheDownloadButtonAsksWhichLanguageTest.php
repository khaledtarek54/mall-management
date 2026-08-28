<?php

use App\Filament\Admin\Resources\Invoices\Pages\ListInvoices;
use App\Models\User;
use App\Support\Filament\AuthorizedAction;
use App\Support\Filament\PdfDownloadAction;
use App\Support\Pdf\DocumentLocale;
use Database\Seeders\RolesPermissionsSeeder;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\App;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * **The Download button asks which language, and it opens on the tenant's own.**
 *
 * Fifteen call sites each repeated `streamDownload` with a service built from the ambient locale, so
 * the only way an operator could produce the other language was to change their OWN panel language,
 * download again, and change it back. `App\Support\Filament\PdfDownloadAction` is that button once,
 * with the choice on it.
 *
 * Driven through the REAL page rather than by building the action in a test. Filament resolves a
 * schema and an action body through closures that only run when an operator opens the modal, so an
 * action assembled in a test and inspected proves its shape and nothing about whether it works —
 * this codebase has shipped two live 500s of exactly that kind (`UtilityMetersTable`, `VendorForm`),
 * both invisible to tests that enumerated actions.
 */
beforeEach(function () {
    $this->seed(RolesPermissionsSeeder::class);

    $this->asset = makeAsset();
    $this->tenant = makeTenant(['locale' => 'ar']);
    $lease = makeLease(makeUnit($this->asset), $this->tenant);

    $this->invoice = makeInvoice($lease, ['status' => 'issued']);
    $this->invoice->items()->create([
        'description' => 'Monthly Rent',
        'type' => 'base_rent',
        'amount' => 10_000,
        'vat_rate' => 0,
        'vat_amount' => 0,
        'total' => 10_000,
    ]);

    $admin = User::factory()->create();
    $admin->assignRole('super_admin');

    $this->actingAs($admin);
    Filament::setTenant($this->asset);
});

it('offers the language picker, pre-selected to the tenant\'s own', function () {
    // The operator is reading the panel in English; the tenant reads Arabic. The default has to come
    // from the tenant, or the picker is just a second click on the wrong answer.
    App::setLocale('en');

    Livewire::test(ListInvoices::class)
        ->mountTableAction('downloadPdf', $this->invoice)
        ->assertTableActionDataSet([PdfDownloadAction::LANGUAGE_FIELD => 'ar']);
});

it('streams the document in the language the operator picked', function () {
    App::setLocale('en');

    $bytes = [];

    foreach (['en', 'ar'] as $locale) {
        $component = Livewire::test(ListInvoices::class)
            ->callTableAction('downloadPdf', $this->invoice, [PdfDownloadAction::LANGUAGE_FIELD => $locale])
            ->assertHasNoTableActionErrors()
            // A real download, not a redirect or a toast — the action's whole job is to return a
            // Response, and Livewire turns that into a `download` effect rather than a page change.
            ->assertFileDownloaded($this->invoice->number.'.pdf');

        $bytes[$locale] = base64_decode(data_get($component->effects, 'download.content'));

        expect($bytes[$locale])->toStartWith('%PDF-');
    }

    // **The assertion that proves the picker reaches the renderer.** Both downloads succeeding says
    // only that the button works; two IDENTICAL files would mean the chosen language was read,
    // validated and then thrown away — which is exactly what fifteen call sites did before this.
    expect($bytes['ar'])->not->toBe($bytes['en']);

    // And the operator's own panel is where they left it: the locale swap is scoped to the render.
    expect(App::getLocale())->toBe('en');
});

it('refuses a language it has no catalogue for instead of rendering a blank document', function () {
    // The radio's `->in()` rule is the operator's error message; `DocumentLocale::resolve()` is the
    // guard. Both matter: a disabled or absent radio still arrives as a Livewire payload, so the
    // rule alone would not be a gate — which is the same reasoning `PropertyField` records.
    Livewire::test(ListInvoices::class)
        ->callTableAction('downloadPdf', $this->invoice, [PdfDownloadAction::LANGUAGE_FIELD => 'klingon'])
        ->assertHasTableActionErrors([PdfDownloadAction::LANGUAGE_FIELD]);

    // The control: the resolver would have clamped it anyway, so nothing reaches mpdf unclamped.
    expect(DocumentLocale::resolve('klingon'))->toBeIn(DocumentLocale::supported());
});

it('falls back to the operator\'s language when the tenant has stated none', function () {
    // Null is the normal state — `tenants.locale` is nullable and most rows will never carry one.
    // The picker must still open on something, and the operator's own language is the best guess
    // available.
    $this->tenant->forceFill(['locale' => null])->save();

    App::setLocale('ar');

    Livewire::test(ListInvoices::class)
        ->mountTableAction('downloadPdf', $this->invoice->fresh())
        ->assertTableActionDataSet([PdfDownloadAction::LANGUAGE_FIELD => 'ar']);
});

it('keeps the shared action inside the container\'s authorization seam', function () {
    // `PdfDownloadAction` extends `AuthorizedAction`, not Filament's `Action`, so `->authorize()` on
    // a call site is still enforced inside `call()` at dispatch. A shared action class that quietly
    // stepped outside that seam would be a hole in it — and these are the actions most likely to be
    // copied for the next document.
    // Imported, not written inline: a Pest file lives in its own namespace, so a
    // partially-qualified `App\Support\…` here resolves to `P\Tests\…\App\Support\…` — and
    // `is_subclass_of` on a class that does not exist returns FALSE rather than throwing. The
    // assertion then fails for a reason that has nothing to do with the class hierarchy, which is
    // the same trap `UnresolvedClassReferenceConformanceTest` exists to catch in app code.
    expect(is_subclass_of(PdfDownloadAction::class, AuthorizedAction::class))->toBeTrue();

    $action = PdfDownloadAction::make('downloadPdf')->authorize(fn (): bool => false);

    expect(fn () => $action->call())
        ->toThrow(HttpException::class);
});

it('leaves an invoice with no tenant language alone rather than guessing per document', function () {
    // A control for the whole file: the resolver must not be answering one fixed language. With the
    // panel in English and no stored preference, the document is English; the previous test proves
    // the same setup answers Arabic when the panel is.
    $this->tenant->forceFill(['locale' => null])->save();

    App::setLocale('en');

    Livewire::test(ListInvoices::class)
        ->mountTableAction('downloadPdf', $this->invoice->fresh())
        ->assertTableActionDataSet([PdfDownloadAction::LANGUAGE_FIELD => 'en']);
});
