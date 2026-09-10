<?php

use App\Filament\Admin\Resources\Invoices\Pages\ListInvoices;
use App\Support\LatinNumerals;
use Database\Seeders\RolesPermissionsSeeder;
use Filament\Facades\Filament;
use Livewire\Livewire;

/**
 * A number reads the same in both languages: 0 1 2 … 100, never ٠ ١ ٢ … ١٠٠.
 *
 * Reported from the panel: "momkn matkhalesh ay rakam fl system bl 3araby? khaleh 3ady english."
 * Egypt writes numbers in Latin digits — the bank statement, the tax invoice, the POS receipt —
 * so a tenant reading «١٢٬٧٨٠» on screen has to transliterate before they can reconcile it
 * against `12,780` on the statement, and an operator reading `٣٠` in one sentence and `30` in the
 * next cannot tell which is the system's own voice.
 *
 * This drives the SCREEN. `LatinNumeralsConformanceTest` sweeps the catalogue, the seeded data
 * and the formatters, and each of those is a statement about a source file — none of them can see
 * what Blade composes out of the three, nor a string that arrives from Filament's own views. The
 * defect was found by looking at a page, so a page is what proves it fixed.
 */
beforeEach(function () {
    $this->seed(RolesPermissionsSeeder::class);
    $this->asset = makeAsset();
    $this->actingAs(makeUser('super_admin', [$this->asset->id]));
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    Filament::setTenant($this->asset, isQuiet: true);

    app()->setLocale('ar');
});

afterEach(fn () => Filament::setTenant(null, isQuiet: true));

it('renders an Arabic money list in Latin digits', function () {
    // A real amount with a thousands separator and decimals — the shape that shows the difference.
    $unit = makeUnit($this->asset);
    makeInvoice(makeLease($unit), ['total' => 12780.50, 'balance' => 12780.50]);

    $html = Livewire::test(ListInvoices::class)
        ->assertOk()
        ->html();

    expect($html)->toContain('12,780.50');

    $offenders = [];

    foreach (explode("\n", $html) as $i => $line) {
        if (LatinNumerals::contains($line)) {
            $offenders[] = 'line '.($i + 1).': '.trim(mb_substr($line, 0, 160));
        }
    }

    expect($offenders)->toBe([], "The Arabic invoice list rendered Arabic-Indic digits:\n  ".implode("\n  ", $offenders));
});

it('keeps a helper string that quotes a number in Latin digits', function () {
    // The withholding return's own title carried «نموذج ٤١» — one of 1,008 typed codepoints that
    // no formatting seam could ever have reached, because nothing formats a translation string.
    expect(__('admin.reports.wht_return_title'))->toContain('41')
        ->and(LatinNumerals::contains(__('admin.reports.wht_return_title')))->toBeFalse();

    // …and the ageing buckets, whose separators carried bidi marks placed to control a run of
    // Arabic-Indic digits. With Latin digits UBA rule W4 absorbs the separator into the numeric
    // run on its own; the marks BLOCKED that and rendered the group reversed as 90/60/30.
    $aging = __('admin.report_hub.descriptions.ar_aging');

    expect($aging)->toContain('30/60/90')
        ->and(preg_match('/[0-9][\x{200E}\x{200F}]/u', $aging))->toBe(0);
});
