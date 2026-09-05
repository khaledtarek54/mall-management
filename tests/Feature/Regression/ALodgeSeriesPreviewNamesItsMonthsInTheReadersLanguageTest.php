<?php

/*
|--------------------------------------------------------------------------
| The lodge-series preview names its months in the reader's language (SW-028)
|--------------------------------------------------------------------------
| An Egyptian tenant hands over a year of post-dated cheques at once, and the lodge-series modal's
| live preview is the last thing an operator reads before committing twelve of them. It composed the
| first and last maturity with `Carbon::format('M Y')`, which is not localised — so the Arabic
| sentence carried two English months inside it.
|
| Measured 2026-09-05 in a booted autoloader: for 2026-10-01, `format('M Y')` answers `Oct 2026`
| whatever the locale is, while `->locale('ar')->isoFormat('MMM YYYY')` answers the Arabic month with
| the year in Latin digits. For English the two are byte-identical, which is why the English control
| below must pass unchanged.
|
| No gate could see this: `ArabicPanelHasNoEnglishChromeConformanceTest` sweeps labels, filters,
| actions and modal chrome, never a value composed inside a Placeholder closure, and the translation
| key itself resolves perfectly in both languages.
*/

use App\Filament\Admin\Resources\PostDatedCheques\Pages\ListPostDatedCheques;
use App\Support\Search\OptionDisplay;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesPermissionsSeeder;
use Filament\Forms\Components\Placeholder;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed(RolesPermissionsSeeder::class);
    ensureAllPropertiesAsset();

    $this->asset = makeAsset(['code' => 'LSP']);
    $this->tenant = makeTenant(['name' => 'Cafe Crema']);

    $this->actingAs(makeUser('super_admin', [$this->asset->id]));
});

afterEach(fn () => app()->setLocale('en'));

/**
 * The sentence the lodge-series preview actually renders, read off the MOUNTED action's own schema.
 *
 * NOT `assertSee()`: Filament's `fillForm()` — which is what `setActionData()` calls — ends in
 * `skipRender()` (`InteractsWithSchemas::fillFormDataForTesting`), so the HTML a test can assert on
 * after filling is still the one from `mountAction`, i.e. the empty-preview branch. Reading the
 * component evaluates exactly the closure under test, on the same accessor path Filament's own
 * `fillForm` helper uses (`$livewire->{$livewire->getMountedActionSchemaName()}`).
 *
 * `pdcLodgeSeriesPreview` prefix: a file-scope helper name is GLOBAL across tests/ and a collision
 * exits the whole suite 255 with no output on either stream (`seriesData` is already taken by
 * PostDatedChequeSeriesTest).
 *
 * @param  array<string, mixed>  $data
 */
function pdcLodgeSeriesPreview(array $data): string
{
    $component = Livewire::test(ListPostDatedCheques::class)
        ->mountAction('lodge_series')
        ->setActionData($data);

    $livewire = $component->instance();
    $schema = $livewire->{$livewire->getMountedActionSchemaName()};

    // Exactly one Placeholder in this schema — the preview.
    $preview = collect($schema->getFlatComponents(withHidden: true))
        ->first(fn ($item) => $item instanceof Placeholder);

    expect($preview)->not->toBeNull();

    return (string) $preview->getContent();
}

it('says nothing at all until the operator has typed an amount', function () {
    // The reachability control. Every assertion below is about the CONTENT of this placeholder, so
    // if the helper could not find or evaluate it, this is the test that says so — rather than
    // three `toContain` failures that read as a formatting bug.
    $text = asTenant($this->asset, fn () => pdcLodgeSeriesPreview([
        'count' => 12,
        'interval_months' => 1,
        'first_cheque_date' => '2026-10-01',
    ]));

    expect($text)->toBe(__('admin.post_dated_cheques.series_preview_empty'));
});

it('reads exactly as it always did in English', function () {
    // The control that must succeed: `isoFormat('MMM YYYY')` and `format('M Y')` are byte-identical
    // under `en`, so this test passes both before and after the fix — and fails loudly if somebody
    // "fixes" the format string into something else (`MMMM`, a numeric month, a different order).
    $text = asTenant($this->asset, fn () => pdcLodgeSeriesPreview([
        'count' => 12,
        'amount' => 25000,
        'interval_months' => 1,
        'first_cheque_date' => '2026-10-01',
    ]));

    // 12 monthly cheques from 1 Oct 2026 mature Oct 2026 through Sep 2027.
    expect($text)->toContain('12 cheques')
        ->and($text)->toContain('25,000.00')
        ->and($text)->toContain('300,000.00')
        ->and($text)->toContain('Oct 2026')
        ->and($text)->toContain('Sep 2027');
});

it('writes both maturities in Arabic for a reader working in Arabic', function () {
    app()->setLocale('ar');

    $text = asTenant($this->asset, fn () => pdcLodgeSeriesPreview([
        'count' => 12,
        'amount' => 25000,
        'interval_months' => 1,
        'first_cheque_date' => '2026-10-01',
    ]));

    // The two months, in the reader's own language. Written as the Carbon `ar` locale renders them.
    expect($text)->toContain('أكتوبر 2026')
        ->and($text)->toContain('سبتمبر 2027')
        // The half that was wrong: `format('M Y')` emits these whatever the locale is.
        ->and($text)->not->toContain('Oct 2026')
        ->and($text)->not->toContain('Sep 2027')
        // Latin digits, never Arabic-Indic — the app-wide rule LatinNumeralsTest pins. A localised
        // Carbon instance is exactly where that could regress.
        ->and(preg_match('/[\x{0660}-\x{0669}\x{06F0}-\x{06F9}]/u', $text))->toBe(0);
});

it('honours a quarterly interval in the reader’s language too', function () {
    // A second span, so the assertion cannot be passing on one lucky month name. 4 quarterly
    // cheques from 1 Oct 2026 mature Oct 2026 through Jul 2027.
    app()->setLocale('ar');

    $text = asTenant($this->asset, fn () => pdcLodgeSeriesPreview([
        'count' => 4,
        'amount' => 60000,
        'interval_months' => 3,
        'first_cheque_date' => '2026-10-01',
    ]));

    expect($text)->toContain('أكتوبر 2026')
        ->and($text)->toContain('يوليو 2027')
        ->and($text)->not->toContain('Jul 2027');
});

/*
|--------------------------------------------------------------------------
| ...AND SO DOES EVERY OTHER MONTH THE PANEL SHOWS
|--------------------------------------------------------------------------
| The first pass of this fix repaired the lodge-series preview and left three more panel-facing
| sites on the unlocalised call — enumerated from the diff that had just been written rather than
| from the code, which CLAUDE.md names as this codebase's most repeated defect. The adversarial
| review found them, and the highest-leverage one was not the one the row named:
|
|   `OptionDisplay::dateRange()`     — ONE seam, three callers: a lease's commencement→expiry, a
|                                      vendor contract's start→end, an announcement's window. That
|                                      is the second line of every lease / vendor-contract /
|                                      announcement `EntitySelect` in BOTH panels.
|   `ListOwnerStatementRuns`         — the option LABELS of the period picker on the modal that
|                                      generates an owner statement: the same shape as the cheque
|                                      preview, an act being committed with English months on it.
|   `RentIndex::label()`             — read by the rent-index picker on the lease form.
|
| So the sweep below is the real guard and the four cases above are its worked example. It is
| deliberately narrow: it sweeps what the PANEL renders, because a month formatted inside a billing
| service is `invoice_items.description` — STORED English prose, whose reason is written out in
| `MonthlyBillingService` (localising it would freeze the billing RUN's locale into the row, so an
| Arabic queue worker would store an Arabic word beside an English month and the register would then
| hold both). Console output is English on purpose too. Those are decisions, not omissions, and a
| gate that swept them would be argued down rather than fixed.
*/

/** Every panel-rendered source file, from disk — never a list, so a new screen is swept by existing. */
function panelRenderedSourceFiles(): array
{
    $roots = [
        base_path('app/Filament'),
        base_path('app/Support/Search'),
        base_path('app/Support/Filament'),
        // The final review caught the gate checking a narrower property than its name: the roots
        // above excluded every BLADE — including the owner statement's period line, the public
        // pay link and the invoice e-mail, all reader-facing and locale-wrapped — and a commit
        // edited one of those files 22 lines away from an offender the gate reported zero of.
        // Models carry `label()`s the pickers render, so they are swept too. `app/Services` stays
        // OUT deliberately: a month formatted there is `invoice_items.description`, stored English
        // prose whose reason is written in `MonthlyBillingService`, and console output is English
        // on purpose.
        base_path('resources/views'),
        base_path('app/Models'),
    ];

    $files = [];

    foreach ($roots as $root) {
        if (! is_dir($root)) {
            continue;
        }

        /** @var iterable<SplFileInfo> $it */
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, RecursiveDirectoryIterator::SKIP_DOTS));

        foreach ($it as $file) {
            $name = $file->getFilename();

            if ($file->isFile() && (str_ends_with($name, '.php') || str_ends_with($name, '.blade.php'))) {
                $files[] = $file->getPathname();
            }
        }
    }

    return $files;
}

it('never formats a month for the panel with a call that cannot be localised', function () {
    $offenders = [];

    foreach (panelRenderedSourceFiles() as $path) {
        $source = file_get_contents($path);

        // `format('M Y')` / `format('F Y')` and their bare-month cousins emit English month names
        // whatever `app()->getLocale()` says. `isoFormat()` on a localised instance is the idiom.
        if (preg_match_all("/->format\('[^']*(?:M|F)[^']*'\)/", $source, $m)) {
            foreach ($m[0] as $hit) {
                // A numeric month (`m`) is language-neutral; only the NAME-bearing tokens matter.
                if (preg_match("/'[^']*(?:MMM|MMMM|\bM\b|\bF\b)[^']*'/", $hit)) {
                    $offenders[] = str_replace(base_path().'/', '', $path).'  '.$hit;
                }
            }
        }
    }

    expect($offenders)->toBe([], "A month rendered in the panel must read in the operator's language. "
        ."Use ->locale(app()->getLocale())->isoFormat('MMM YYYY'). Offending: \n  ".implode("\n  ", $offenders));
});

it('proves its own premise — the sweep is looking at real files', function () {
    // A sweep that silently stops collecting reports every screen clean, which this codebase has
    // now been bitten by three times. So: the file set is real, and the pattern really does match
    // the shape being banned.
    expect(count(panelRenderedSourceFiles()))->toBeGreaterThan(300);

    expect(preg_match("/->format\('[^']*(?:M|F)[^']*'\)/", "\$d->format('M Y')"))->toBe(1)
        ->and(preg_match("/->format\('[^']*(?:M|F)[^']*'\)/", "\$d->format('Y-m-d')"))->toBe(0);
});

it('renders an Arabic month in the pickers the one shared seam feeds', function () {
    app()->setLocale('ar');

    $subtitle = (new ReflectionMethod(OptionDisplay::class, 'dateRange'))
        ->invoke(null, CarbonImmutable::parse('2026-01-01'), CarbonImmutable::parse('2028-12-01'));

    // The months are Arabic; the YEARS stay in Latin digits, which `LatinNumeralsTest` pins app-wide.
    expect($subtitle)->toContain('2026')->toContain('2028')
        ->and($subtitle)->not->toContain('Jan')->not->toContain('Dec');

    app()->setLocale('en');

    expect((new ReflectionMethod(OptionDisplay::class, 'dateRange'))
        ->invoke(null, CarbonImmutable::parse('2026-01-01'), CarbonImmutable::parse('2028-12-01')))
        ->toBe('Jan 2026 – Dec 2028');
});
