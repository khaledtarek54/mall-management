<?php

use Illuminate\Support\Facades\File;

/**
 * Italic loses Arabic entirely, so no document may use it.
 *
 * **Measured on mpdf 8.x, not reasoned:** rendering «فاتورة ضريبية» plain and in bold works; the
 * same words inside `<em>`, `<i>` or `font-style: italic` come out as a row of EMPTY BOXES, while
 * the Latin text beside them slopes correctly. IBM Plex Sans Arabic ships no italic face and mpdf
 * does not fall back to the upright one — it falls through to a font with no Arabic coverage.
 *
 * **Declaring `I`/`BI` in `PdfDocument::fontData()` does not fix it.** That was tried, measured to
 * change nothing, and reverted rather than shipped as a fix that does not fix.
 *
 * No shipped template used italic when this was found, so the bug was latent — which is exactly the
 * kind that ships the day somebody reaches for `<em>` in a template nobody re-renders in Arabic.
 * The neutraliser in `_styles` makes it unreachable; this keeps it that way, and keeps the
 * neutraliser from being "tidied up" by someone who cannot see what it prevents.
 */
it('uses no italic anywhere in a PDF template', function () {
    $templates = collect(File::allFiles(resource_path('views/pdf')))
        ->filter(fn ($f): bool => str_ends_with($f->getFilename(), '.blade.php'));

    // The premise: a sweep that silently stopped collecting would report no offenders and pass.
    expect($templates)->not->toBeEmpty();

    $offenders = [];

    foreach ($templates as $template) {
        $source = File::get($template->getRealPath());

        // COMMENTS ARE STRIPPED FIRST, and that is not tidiness.
        //
        // The neutraliser's own comment explains the bug by naming `font-style: italic`, so a raw
        // grep flags the file that FIXES the problem — this project's signature gate defect, and
        // the reason it records that "a gate that fires on a sentence is one that gets weakened
        // rather than fixed". Removing the prose is the structural answer; a filename exemption
        // would also blind the gate to a real use in that same file.
        $source = preg_replace('/\{\{--.*?--\}\}/s', '', $source) ?? $source;
        $source = preg_replace('#/\*.*?\*/#s', '', $source) ?? $source;

        // The neutraliser itself names these tags in order to switch them OFF, so it is read as a
        // declaration rather than as a use. Matching the DECLARATION shape (`font-style: italic`,
        // an opening `<em>`/`<i>` tag) keeps that distinction structural rather than a filename
        // exemption that would also hide a real use in the same file.
        $usesItalic = preg_match('/font-style:\s*italic/i', $source)
            || preg_match('/<(em|i)[\s>]/i', $source);

        if ($usesItalic) {
            $offenders[] = str_replace(resource_path('views/pdf').'/', '', $template->getRealPath());
        }
    }

    expect($offenders)->toBe([], "Italic renders Arabic as empty boxes in mpdf.\n"
        ."Use weight instead — `font-weight: 600` — in:\n  ".implode("\n  ", $offenders));
});

it('keeps the neutraliser that makes it unreachable', function () {
    // Belt and braces: the sweep above stops a template REACHING for italic, and this stops the
    // rule that would save one anyway from being deleted as dead CSS.
    expect(File::get(resource_path('views/pdf/_styles.blade.php')))
        ->toContain('font-style: normal');
});
