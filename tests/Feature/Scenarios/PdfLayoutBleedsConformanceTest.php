<?php

use Illuminate\Support\Facades\File;

/**
 * **Every document built on the shared shell paints to the paper edge.**
 *
 * Direction D's masthead is a full-bleed band, and the bleed is the whole difference between a
 * masthead and a coloured box: inset by a 13mm page margin it reads as a box somebody drew, not as
 * the top of the document. mpdf has no per-element bleed, so the only way to reach the edge is for
 * the PAGE to carry no side margin — `PdfDocument::bleed()` — and the shell to supply the body's
 * own through `.page-body`.
 *
 * That makes it a call a service has to remember, and forgetting it fails in the worst way
 * available: the document still renders, still balances, still says everything it should, and looks
 * like a rendering fault rather than a missing line of code. Nobody would file it as "the service
 * did not call bleed()" — they would file it as "the header is broken".
 *
 * The other direction matters too and is the reason this is not simply the default: the seven
 * documents NOT on the shared shell still lay themselves out inside the page margins, so a service
 * that bleeds one of those would run the payslip or a financial statement edge to edge.
 */

/** @return array<string, string> service path => the shell-extending view it renders */
function bleedingDocumentServices(): array
{
    $extendsShell = [];

    foreach (File::allFiles(resource_path('views')) as $file) {
        if (! str_ends_with($file->getFilename(), '.blade.php')) {
            continue;
        }

        if (preg_match("/@extends\(\s*'pdf\.layout'/", File::get($file->getPathname()))) {
            $name = str_replace([resource_path('views').'/', '/', '.blade.php'], ['', '.', ''], $file->getPathname());
            $extendsShell[] = $name;
        }
    }

    $found = [];

    foreach (File::allFiles(app_path('Services')) as $file) {
        if (! str_ends_with($file->getFilename(), 'PdfService.php')) {
            continue;
        }

        $code = File::get($file->getPathname());

        foreach ($extendsShell as $view) {
            if (str_contains($code, "'".$view."'")) {
                $found[str_replace(base_path().'/', '', $file->getPathname())] = $view;
            }
        }
    }

    return $found;
}

it('discovers the documents built on the shared shell', function () {
    // The guard this file needs most. A discovery pass that silently matches nothing passes every
    // assertion below — this codebase has shipped exactly that gate before, green for a year while
    // sweeping zero models.
    // Six on 2026-08-27 (the counterparty documents), nine on 2026-08-28 when the payslip, the CAM
    // reconciliation and the owner statement joined them — the three remaining documents that leave
    // the building. TEN on 2026-09-05, when the lease agreement became the first document this
    // system GENERATES rather than files (gap O1). An exact count rather than a floor: this number
    // falling is a template quietly dropping off the shared shell, which is the drift the shell
    // exists to prevent.
    expect(bleedingDocumentServices())->toHaveCount(10);
});

it('bleeds every document whose template extends the shared shell', function () {
    $offenders = [];

    foreach (bleedingDocumentServices() as $service => $view) {
        if (! str_contains(File::get(base_path($service)), '->bleed()')) {
            $offenders[] = $service.' (renders '.$view.')';
        }
    }

    expect($offenders)->toBe([], implode("\n", array_merge(
        ['These render a template that extends `pdf.layout` without calling `PdfDocument::bleed()`,'],
        ['so the band stops 13mm short of the paper edge and reads as a rendering fault:'],
        array_map(fn (string $v): string => '  - '.$v, $offenders),
    )));
});

it('gives a bleeding document somewhere for page two to start', function () {
    // **The property the source-grep above cannot see.** `bleed()` zeroes `margin_top` so the band
    // reaches the top of page 1, and mpdf sets `y = tMargin` on EVERY new page — so page 2 of a
    // statement began at y=0, inside the few millimetres most office printers cannot reach. It reads
    // as "the printer ate the first line" rather than as a missing setting, and the checks above
    // stayed green throughout because they read source text and never rendered a second page.
    //
    // The shell answers it with an mpdf continuation header, which `setAutoTopMargin` then makes
    // room for. Asserting both halves together is the point: `->bleed()` on its own is now an
    // incomplete configuration, and this fails if either half is removed.
    $shell = File::get(resource_path('views/pdf/layout.blade.php'));

    expect($shell)->toContain('<htmlpageheader name="continuation">')
        ->and($shell)->toContain('<sethtmlpageheader name="continuation" value="on" />')
        ->and(File::get(app_path('Support/Pdf/PdfDocument.php')))
        ->toContain("setAutoTopMargin = 'pad'");
});

it('does not bleed a document that lays itself out inside the page margins', function () {
    // The opposite mistake, and the reason bleeding is opt-in: the seven documents not on the shared
    // shell still expect a page margin, and one of them bleeding would run edge to edge.
    $offenders = [];
    $onShell = array_keys(bleedingDocumentServices());

    foreach (File::allFiles(app_path('Services')) as $file) {
        if (! str_ends_with($file->getFilename(), 'PdfService.php')) {
            continue;
        }

        $relative = str_replace(base_path().'/', '', $file->getPathname());

        if (in_array($relative, $onShell, true)) {
            continue;
        }

        if (str_contains(File::get($file->getPathname()), '->bleed()')) {
            $offenders[] = $relative;
        }
    }

    expect($offenders)->toBe([], 'These bleed without being built on the shared shell, so their '
        .'content runs to the paper edge with no margin: '.implode(', ', $offenders));
});
