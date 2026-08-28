<?php

use App\Settings\TaxSettings;
use App\Support\IssuingEntity;
use Illuminate\Support\Facades\File;

/**
 * Every PDF this system produces names its ISSUER and renders in Arabic.
 *
 * Both properties had drifted, silently, in the way template drift always does — nothing throws, the
 * document still prints, and the defect is only visible to whoever reads the output in the other
 * language or looks at the top line and asks who sent this.
 *
 * The sweep of 2026-08-17 found:
 *
 *   - **Five of twelve documents printed "Atriom"** — the software's name — where the issuing entity
 *     belongs: the owner statement, the payslip, the monthly-close pack, the facility work log and
 *     the four financial statements. The fallback was spelled three different ways across the set
 *     (`'Atriom'`, `config('app.name')`, and a bare literal), which is how nobody noticed it was a
 *     decision at all.
 *   - **`accounting/pdf/layout.blade.php` applied `letter-spacing` unconditionally.** Arabic is a
 *     cursive script and letter-spacing breaks the glyph joins, so the balance sheet, income
 *     statement, trial balance and cash flow — the four documents the accountant actually reads in
 *     Arabic — printed with disconnected letters. Every other template in the set guards this on
 *     `$isRtl`; this one was written before that was the rule and never revisited.
 *
 * The view list is DERIVED from the PDF services rather than hand-written, so a thirteenth document
 * is covered the day it ships rather than the day someone remembers this file. Layouts reached by
 * `@extends` are followed, because that is where the RTL defect actually lived.
 */

/** @return array<string, string> view name => absolute path */
function pdfTemplates(): array
{
    $views = [];

    $services = collect(File::allFiles(app_path('Services')))
        ->filter(fn ($f) => str_ends_with($f->getFilename(), 'PdfService.php'))
        // The PDFs are not the only documents that leave the building carrying the operator's name.
        // The invoice EMAIL and the hosted PAYMENT PAGE are read by the same tenant, and the payment
        // page is where a cardholder decides whether they recognise the merchant. All three
        // hardcoded "Atriom" too, and a gate that swept only `*PdfService` would have called that
        // clean.
        ->merge(collect(File::allFiles(app_path('Mail'))))
        ->merge([new SplFileInfo(app_path('Http/Controllers/PaymentLinkController.php'))])
        ->merge(collect(File::allFiles(app_path('Notifications')))
            ->filter(fn ($f) => str_contains(File::get($f->getRealPath()), '->markdown(')));

    foreach ($services as $service) {
        // Any single-quoted string in the file that RESOLVES to a blade template, rather than
        // `View::make('…')` specifically. `LedgerReportPdfService` names its four statements as
        // arguments to a private `render()` helper, so a View::make-shaped regex found none of them
        // — including `accounting.pdf.layout`, which is exactly where the RTL defect lived. Keying
        // on "does this string name a real template" cannot be outrun by a refactor.
        preg_match_all("/'([a-z0-9_.-]+)'/i", File::get($service->getRealPath()), $m);

        foreach ($m[1] as $name) {
            $path = resource_path('views/'.str_replace('.', '/', $name).'.blade.php');

            if (File::exists($path)) {
                $views[$name] = $path;
            }
        }
    }

    // Follow @extends AND @include — a layout and a shared partial are PDF templates too, and are
    // where the RTL defect lived both times. `@extends` alone was enough while every document
    // carried its own <style> block; since the stylesheet became ONE `@include`d partial, a gate
    // that did not follow includes would sweep twelve documents and miss the only file that
    // actually sets `letter-spacing` for all of them — reporting full coverage of a rule it had
    // stopped checking anywhere.
    //
    // Transitively, because the shell includes the stylesheet and each document extends the shell:
    // a single pass would reach `pdf.layout` and stop one file short of `pdf._styles`.
    do {
        $before = count($views);

        foreach ($views as $path) {
            preg_match_all("/@(?:extends|include)\(\s*'([^']+)'/", File::get($path), $m);

            foreach ($m[1] as $name) {
                $referenced = resource_path('views/'.str_replace('.', '/', $name).'.blade.php');

                if (File::exists($referenced)) {
                    $views[$name] = $referenced;
                }
            }
        }
    } while (count($views) > $before);

    return $views;
}

it('discovers the PDF templates it is meant to be sweeping', function () {
    // The guard this file needs most. A discovery regex that silently matches nothing passes every
    // assertion below — this codebase has shipped exactly that gate before, green for a year while
    // sweeping zero models.
    $views = pdfTemplates();

    expect(count($views))->toBeGreaterThanOrEqual(22)
        ->and($views)->toHaveKeys([
            // The shared shell. Reachable only by following @extends and then @include, and the
            // stylesheet is where the RTL rule below is now enforced for every document at once —
            // so if these three ever fall out of the sweep, that rule is being checked nowhere.
            'pdf.layout',
            'pdf._styles',
            'pdf._issuer',
            'invoices.pdf',
            'pdf.credit-note',
            'tenants.statement',
            'payments.receipt',
            'owner-statements.statement',
            'payslips.pdf',
            'reports.monthly-close',
            'reports.facility-work-log',
            // The four financial statements and the layout they share — the RTL defect this gate
            // exists for lived in the layout, which is reachable only through @extends.
            'accounting.pdf.balance-sheet',
            'accounting.pdf.income-statement',
            'accounting.pdf.trial-balance',
            'accounting.pdf.cash-flow',
            'accounting.pdf.layout',
            // Not PDFs, but the same document surface: the tenant reads these too.
            'emails.invoice-issued',
            'pay.show',
            'pay.status',
        ]);
});

it('never hardcodes the issuing entity in a template', function () {
    // The name on a document is a decision the operator owns through `TaxSettings::seller_legal_name`
    // (a go-live gate item), resolved in ONE place. A literal in a template is that decision made by
    // whoever last edited the markup, for one document, invisibly.
    $offenders = [];

    foreach (pdfTemplates() as $name => $path) {
        $body = File::get($path);

        if (str_contains($body, "'".IssuingEntity::FALLBACK."'")
            || preg_match('/>\s*'.preg_quote(IssuingEntity::FALLBACK, '/').'\s*</', $body)
            || str_contains($body, "config('app.name')")) {
            $offenders[] = $name;
        }
    }

    expect($offenders)->toBe([], 'These PDF templates name the issuer themselves instead of reading '
        .'App\Support\IssuingEntity: '.implode(', ', $offenders));
});

it('guards letter-spacing and uppercase on $isRtl, so Arabic glyphs stay joined', function () {
    // Arabic is cursive: letter-spacing pulls the joins apart, and `uppercase` is meaningless on it.
    // A template that sets either unconditionally is broken in one of the two languages this system
    // ships in — and it is the language the accountant and most tenants read.
    $offenders = [];

    foreach (pdfTemplates() as $name => $path) {
        $source = File::get($path);

        // A variable this template DERIVES from $isRtl is as good a guard as the ternary itself, and
        // in the shared stylesheet it is a better one: `$track` and `$caps` are defined once at the
        // top and used by nine rules, where nine copies of the same ternary is what drifts. Collected
        // per file, so a variable of that name defined some other way elsewhere proves nothing here.
        preg_match_all('/\$(\w+)\s*=\s*[^;]*isRtl[^;]*;/', $source, $derived);
        $guardedByVariable = $derived[1];

        // Blade comments span lines: `{{--` on the first, prose on the rest. The original check
        // skipped only lines CONTAINING the opener, so a comment that discusses `text-transform` on
        // its second line was read as a declaration — which is a false RED, the failure mode that
        // gets a gate deleted rather than fixed.
        $inBladeComment = false;

        foreach (file($path) as $i => $line) {
            if (str_contains($line, '{{--')) {
                $inBladeComment = ! str_contains($line, '--}}');

                continue;
            }

            if ($inBladeComment) {
                $inBladeComment = ! str_contains($line, '--}}');

                continue;
            }

            // Comment lines discuss these properties without setting them.
            if (str_starts_with(ltrim($line), '*') || str_starts_with(ltrim($line), '/*') || str_starts_with(ltrim($line), '//')) {
                continue;
            }

            // Per DECLARATION, not per line. A CSS rule packs several declarations onto one line,
            // and the real defect sat on a line that already mentioned `$isRtl` — via `text-align`,
            // which was guarded — while `letter-spacing` next to it was not. A line-level check
            // reads as a working gate and would have passed the very bug this file was written for.
            //
            // The value runs to `;` or to the closing brace of the RULE, but a blade echo closes with
            // `}}` of its own — so `[^;}]+` truncated `{{ $caps }}` to `{{ $caps` and lost the very
            // token it was about to search for. Matched non-greedily up to a real terminator instead.
            preg_match_all('/(letter-spacing|text-transform)\s*:\s*(.*?)(?=;|\s*\}(?!\})|$)/', $line, $m, PREG_SET_ORDER);

            foreach ($m as [, $property, $value]) {
                if (str_contains($value, 'isRtl')) {
                    continue;
                }

                $viaVariable = collect($guardedByVariable)
                    ->contains(fn (string $variable): bool => str_contains($value, '$'.$variable));

                if ($viaVariable) {
                    continue;
                }

                // Values that are already RTL-safe unconditionally.
                if (preg_match('/^\s*(0|none|inherit)\s*$/', trim($value))) {
                    continue;
                }

                $offenders[] = $name.':'.($i + 1).' ('.$property.':'.trim($value).')';
            }
        }
    }

    expect($offenders)->toBe([], 'These lines set letter-spacing or uppercase without an $isRtl '
        .'guard, which breaks Arabic glyph joining: '.implode(', ', $offenders));
});

it('resolves the issuer from settings, falling back only when nothing is configured', function () {
    $tax = app(TaxSettings::class);

    // Unconfigured: the fallback, so a header is never blank.
    $tax->seller_legal_name = '';
    expect(IssuingEntity::name())->toBe(IssuingEntity::FALLBACK)
        // '' rather than the fallback, so a caller can tell "not configured" from "configured" —
        // the tax documents print the registered-name line only when it is real.
        ->and(IssuingEntity::legalName())->toBe('');

    $tax->seller_legal_name = 'Eltizam Property Management LLC';
    expect(IssuingEntity::name())->toBe('Eltizam Property Management LLC');

    // A property wins for the documents a counterparty reads: the tenant knows the mall's name.
    $asset = makeAsset(['name' => 'Atriom Walk']);
    expect(IssuingEntity::tradingName($asset))->toBe('Atriom Walk')
        ->and(IssuingEntity::tradingName(null))->toBe('Eltizam Property Management LLC');
});
