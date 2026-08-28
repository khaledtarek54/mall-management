<?php

use Illuminate\Support\Facades\File;

/**
 * **A document's colours come from `DocumentTheme`, never from a literal in markup.**
 *
 * `DocumentTheme`'s own docblock records why it exists: six templates each carried their own copy of
 * the palette inline, which is how the credit note's rules ended up a shade lighter than the
 * invoice's and the receipt's muted grey became a third grey. *"Nobody chose any of that; it is what
 * happens when a colour is a literal in markup."*
 *
 * Adopting Direction D immediately re-created a smaller version of the same thing: the four internal
 * reports that are NOT on the shared shell were repalletted by rewriting ~60 hex literals by hand.
 * That was a deliberate call — they are dense analytical layouts read by the accountant who asked
 * for them, and a masthead band buys them nothing — but **a deliberate exception with no gate is
 * indistinguishable from an oversight**, and the next palette change has to find them all by hand.
 *
 * So the exception is NAMED, with its reason, and everything else takes its colours from the theme.
 * A fifth template picking up hex literals fails the build.
 */

/**
 * Templates allowed to carry their own hex colours, and why.
 *
 * Each is a document that lays itself out inside the page margins rather than on the shared shell,
 * so it has no `_styles` to inherit from. Moving one onto the shell is what removes it from this
 * list — not widening the list.
 */
const THEME_EXEMPT = [
    'accounting/pdf/layout.blade.php' => 'The four financial statements share this layout and are read by the accountant who requested them; dense analytical tables rather than counterparty documents, and they gain nothing from a masthead band.',
    'assets/statement.blade.php' => 'The property statement is an internal analytical rollup with its own column structure; it took the palette but keeps its layout deliberately.',
    'reports/facility-work-log.blade.php' => 'The widest table in the set — it carried the narrowest page margins of any template for that reason, and does not fit the shell without losing a column.',
    'reports/monthly-close.blade.php' => 'The close pack is one portfolio-wide document with no single property to brand it and no counterparty to address, so the band would state something untrue.',
];

it('takes every document colour from the theme', function () {
    $offenders = [];
    $checked = 0;

    foreach (File::allFiles(resource_path('views')) as $file) {
        $relative = str_replace(resource_path('views').'/', '', $file->getPathname());

        // Web surfaces have their own branding and are not documents.
        foreach (['emails/', 'pay/', 'filament/', 'errors/', 'vendor/', 'partials/'] as $prefix) {
            if (str_starts_with($relative, $prefix)) {
                continue 2;
            }
        }

        $body = File::get($file->getPathname());

        // A document is a template a PDF service renders — identified by the shell it extends or,
        // for the ones that do not, by naming the issuer block every document carries.
        $isDocument = str_contains($body, "@extends('pdf.layout'")
            || str_contains($body, '$issuerName')
            || array_key_exists($relative, THEME_EXEMPT);

        if (! $isDocument) {
            continue;
        }

        $checked++;

        if (array_key_exists($relative, THEME_EXEMPT)) {
            continue;
        }

        // `pdf/_styles.blade.php` is where the theme is SPELLED — through the constants, so it
        // carries no literals of its own and needs no exemption.
        preg_match_all('/#[0-9A-Fa-f]{6}\b/', $body, $m);

        if ($m[0] !== []) {
            $offenders[] = $relative.' ('.implode(', ', array_unique($m[0])).')';
        }
    }

    // The premise: this found documents at all. A sweep over an empty set passes for ever, and this
    // codebase has shipped exactly that.
    expect($checked)->toBeGreaterThan(8, 'The sweep found almost no document templates — it is looking at the wrong thing.');

    expect($offenders)->toBe([], implode("\n", array_merge(
        ['These document templates carry raw hex colours instead of taking them from'],
        ['App\Support\Pdf\DocumentTheme, which is how six templates drifted apart before it existed:'],
        array_map(fn (string $v): string => '  - '.$v, $offenders),
        ['', 'Use a class from the shared stylesheet, or T::CONSTANT. If the template genuinely'],
        ['cannot sit on the shared shell, add it to THEME_EXEMPT with the reason.'],
    )));
});

it('has no stale theme exemption', function () {
    // An exemption that outlives its template is a hole waiting for the next file at that path.
    $stale = [];

    foreach (THEME_EXEMPT as $relative => $reason) {
        if (! File::exists(resource_path('views/'.$relative))) {
            $stale[] = $relative.' (gone)';

            continue;
        }

        // A template that has since moved onto the shared shell no longer needs the exemption — and
        // leaving it there would let real literals creep back in unnoticed.
        if (str_contains(File::get(resource_path('views/'.$relative)), "@extends('pdf.layout'")) {
            $stale[] = $relative.' (now on the shared shell)';
        }

        expect(strlen($reason))->toBeGreaterThan(60, "The exemption for {$relative} does not say why it is off the shell.");
    }

    expect($stale)->toBe([], 'Remove from THEME_EXEMPT: '.implode(', ', $stale));
});
