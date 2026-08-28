<?php

use App\Support\Pdf\PdfDocument;
use Illuminate\Support\Facades\File;

/**
 * **Every document this system issues can be asked for a language, and only one class renders one.**
 *
 * Two properties, both of which were true of nothing before 2026-08-27 and both of which decay the
 * same way — by a fourteenth document shipping that looks like the thirteen beside it.
 *
 * 1. **Every PDF-producing method takes a locale.** A service that does not is not merely missing a
 *    feature: it silently renders in `app()->getLocale()`, which for a scheduled billing run is
 *    `config('app.locale')` and for an operator is whatever their own UI is set to. The failure is
 *    invisible from the call site and from the code — the document renders, in a language, and only
 *    the recipient can tell it is the wrong one.
 *
 * 2. **`App\Support\Pdf\PdfDocument` is the only thing that constructs mpdf.** All thirteen services
 *    ended in the same twenty lines and had already drifted apart — two used 14mm margins where
 *    eleven used 12, two set 10pt where eleven set 10.5 — so "how these documents are typeset" was a
 *    thirteen-file edit nobody would make. A fourteenth copy re-opens that.
 *
 * Discovered from disk rather than from a list, so a new service is covered the day it ships. Both
 * sweeps assert they found something first: this codebase has shipped a gate that swept zero models
 * and stayed green for a year.
 */

/** @return array<int, ReflectionClass<object>> every *PdfService on disk */
function pdfServiceClasses(): array
{
    $classes = [];

    foreach (File::allFiles(app_path('Services')) as $file) {
        if (! str_ends_with($file->getFilename(), 'PdfService.php')) {
            continue;
        }

        $relative = str_replace([app_path().'/', '/', '.php'], ['', '\\', ''], $file->getPathname());
        $class = 'App\\'.$relative;

        if (class_exists($class)) {
            $classes[] = new ReflectionClass($class);
        }
    }

    return $classes;
}

it('discovers the PDF services it is meant to be sweeping', function () {
    expect(pdfServiceClasses())->toHaveCount(13);
});

it('lets every document be asked for a language', function () {
    $offenders = [];
    $checked = 0;

    foreach (pdfServiceClasses() as $class) {
        foreach ($class->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            // A method that RETURNS the finished document. `filename()` returns a string too and is
            // not one; `viewData()`/`data()`/`facts()` return arrays and are the seams a test reads.
            // Keying on the return type rather than on the name `build` is what covers
            // `LedgerReportPdfService`, whose four statements are four differently-named methods.
            if ($method->getName() === 'filename' || (string) $method->getReturnType() !== 'string') {
                continue;
            }

            $checked++;

            $takesLocale = collect($method->getParameters())
                ->contains(fn (ReflectionParameter $p): bool => $p->getName() === 'locale');

            if (! $takesLocale) {
                $offenders[] = $class->getShortName().'::'.$method->getName().'()';
            }
        }
    }

    expect($checked)->toBeGreaterThanOrEqual(13)
        ->and($offenders)->toBe([], implode("\n", array_merge(
            ['These methods render a document with no way to say which language it is written in,'],
            ['so they render in whoever happens to be asking — for a queued or scheduled run, in'],
            ['config(\'app.locale\'):'],
            array_map(fn (string $v): string => '  - '.$v, $offenders),
            ['', 'Add `?string $locale = null` and resolve it through App\Support\Pdf\DocumentLocale.'],
        )));
});

it('constructs mpdf in exactly one place', function () {
    // Everything under app/, not just the services — the point is that there is ONE renderer, and a
    // controller or a command reaching for mpdf directly is the same drift arriving by another door.
    $offenders = [];

    foreach (File::allFiles(app_path()) as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }

        $relative = str_replace(base_path().'/', '', $file->getPathname());

        if (! str_contains(File::get($file->getPathname()), 'new Mpdf(')) {
            continue;
        }

        if (in_array($relative, PDF_RENDERER_EXEMPT, true)) {
            continue;
        }

        $offenders[] = $relative;
    }

    expect($offenders)->toBe([], 'These construct mpdf themselves instead of going through '
        .'App\Support\Pdf\PdfDocument, which is how thirteen copies of one config drifted apart: '
        .implode(', ', $offenders));
});

it('does not carry exemptions for files that no longer construct mpdf', function () {
    // A stale exemption is a hole waiting for the next file to be dropped at that path.
    foreach (PDF_RENDERER_EXEMPT as $relative) {
        expect(File::exists(base_path($relative)))->toBeTrue("Exempt file is gone: {$relative}")
            ->and(File::get(base_path($relative)))->toContain('new Mpdf(');
    }
});

it('registers a font that is actually on disk, for both weights', function () {
    // The font is checked in rather than fetched, precisely so this can be asserted. A missing file
    // does not fail loudly at render — mpdf substitutes, and the install quietly starts issuing
    // documents in a face nobody chose.
    foreach (PdfDocument::fontData() as $family => $data) {
        foreach (['R', 'B'] as $style) {
            expect($data[$style] ?? null)->toBeString("{$family} has no {$style} variant")
                ->and(resource_path('fonts/'.$data[$style]))->toBeReadableFile();
        }

        // Arabic is cursive: without OpenType layout the glyphs render as disconnected letterforms.
        // mpdf's own bundled Arabic face is what this replaced, and that is exactly how it failed.
        expect($data['useOTL'] ?? 0)->toBe(0xFF, "{$family} has OpenType layout off");
    }
});

it('uses every font family it ships', function () {
    // The other half, and the half that was missing: the gate above proved every registered file is
    // ON DISK, which a family nothing references satisfies perfectly. `plexsansarabicheavy` was
    // registered on 2026-08-27 and named by no stylesheet for a day — a 247 KB face checked in,
    // parsed into mpdf's metric cache on first render, and asserted by a test, doing no work.
    //
    // The default family needs no reference (mpdf applies it as `default_font`); every OTHER one has
    // to be named by something that renders.
    $stylesheets = collect(File::allFiles(resource_path('views/pdf')))
        ->map(fn ($f) => File::get($f->getPathname()))
        ->implode("\n");

    $unused = [];

    foreach (array_keys(PdfDocument::fontData()) as $family) {
        if ($family === PdfDocument::FONT) {
            continue;
        }

        // Named in the stylesheet as `…FONT }}heavy`, i.e. the constant plus its suffix.
        $suffix = str_replace(PdfDocument::FONT, '', $family);

        if (! str_contains($stylesheets, '::FONT }}'.$suffix)) {
            $unused[] = $family;
        }
    }

    expect($unused)->toBe([], 'These font families are registered with mpdf and shipped in '
        .'resources/fonts, and nothing renders in them: '.implode(', ', $unused));
});

/**
 * Files allowed to construct mpdf themselves, and why.
 *
 * `PdfDocument` renders a BLADE TEMPLATE in a document locale. `atriom:doc-pdf` renders arbitrary
 * MARKDOWN with its own stylesheet and no template, so it is not a document in that sense — but it
 * reads the same font registry, which is the part that had to be shared.
 */
const PDF_RENDERER_EXEMPT = [
    'app/Support/Pdf/PdfDocument.php',
    'app/Console/Commands/RenderDocPdfCommand.php',
];
