<?php

/*
|--------------------------------------------------------------------------
| Every document that names the issuer also carries the mall's logo
|--------------------------------------------------------------------------
| `Asset` has had a `logo` media collection since the panel's per-property branding shipped, and not
| one of the twelve PDF templates referenced it — so every invoice, receipt, statement, payslip and
| purchase order left the building with the operator's text name and no mark. It is the first thing
| an operator asks for and the cheapest thing on the list (S-8).
|
| The logo reaches the templates through `IssuingEntity::forView()`, which all of them already call,
| and is rendered by ONE partial. This gate is what stops the thirteenth template being added without
| it: a header that names the issuer and omits the logo is the exact "fixed one, missed the siblings"
| shape this project keeps hitting.
|
| Emails and the payment pages are deliberately out of scope — they are web surfaces with their own
| branding, not documents that leave the building as files.
*/

use App\Support\IssuingEntity;
use Illuminate\Support\Facades\File;

/**
 * Does this template put the logo beside the issuer name — itself, or through what it composes with?
 *
 * The rule used to be a literal `@include('partials.issuer-logo')` in every file, which was right
 * while every document carried its own header. Since the header became ONE shared partial
 * (`pdf._issuer`, drawn by `pdf.layout`), a document that extends the shell carries the logo without
 * ever naming the partial — and a literal check reports the shell's own users as offenders while the
 * documents that genuinely lack a header still pass. Following the composition is what keeps the
 * gate pointed at the property rather than at the spelling.
 */
function carriesTheIssuerLogo(string $body): bool
{
    if (str_contains($body, "@include('partials.issuer-logo')")) {
        return true;
    }

    preg_match_all("/@(?:extends|include)\(\s*'([^']+)'/", $body, $m);

    foreach ($m[1] as $name) {
        $path = resource_path('views/'.str_replace('.', '/', $name).'.blade.php');

        if (File::exists($path) && carriesTheIssuerLogo(File::get($path))) {
            return true;
        }
    }

    return false;
}

it('includes the logo partial in every PDF template that names the issuer', function () {
    $offenders = [];
    $checked = 0;
    $seen = [];

    foreach (File::allFiles(resource_path('views')) as $file) {
        $relative = str_replace(resource_path('views').'/', '', $file->getPathname());

        // Web surfaces, not documents.
        if (str_starts_with($relative, 'emails/') || str_starts_with($relative, 'pay/')) {
            continue;
        }

        $body = (string) $file->getContents();

        if (! str_contains($body, '$issuerName')) {
            continue;
        }

        $checked++;
        $seen[$relative] = true;

        if (! carriesTheIssuerLogo($body)) {
            $offenders[] = $relative;
        }
    }

    // The premise: this found templates at all. A sweep over an empty set passes for ever.
    //
    // The floor DROPPED from 8 to 5 on 2026-08-28, and the reason matters more than the number: nine
    // documents now render their issuer through ONE shared partial, so they no longer name
    // `$issuerName` themselves and the sweep legitimately sees fewer files. The property is not
    // weaker — `carriesTheIssuerLogo()` follows @extends into the shell — but a premise assertion
    // must be moved DELIBERATELY, with the reason, or it becomes the thing that gets edited every
    // time it goes red. Which is why the shell's own issuer block is named below: it is now the file
    // that carries the logo for most of the set, and a sweep that stopped seeing it would be
    // checking almost nothing while still counting above its floor.
    expect($checked)->toBeGreaterThan(5, 'The sweep found almost no issuer templates — it is looking at the wrong thing.')
        ->and(array_keys($seen))->toContain('pdf/_issuer.blade.php');

    expect($offenders)->toBe([], implode("\n", [
        'These render the issuer name without the mall logo beside it:',
        '  '.implode("\n  ", $offenders),
        '',
        "Add @include('partials.issuer-logo') above the issuer block. `IssuingEntity::forView()`",
        'already provides `$issuerLogo` to every one of these templates.',
    ]));
});

/**
 * PDF services that deliberately render NO property logo, and why.
 *
 * A document with no single property has nothing to brand it with. Each entry must say which,
 * because "it's a report" is the shrug that let five documents ship logo-less.
 */
const PORTFOLIO_DOCUMENTS = [
    'app/Services/Reports/MonthlyCloseReportPdfService.php' => 'The monthly close is one document for the whole operator — it has no single property, and there is no parameter that could give it one.',
];

it('passes a property to the issuer block from every service that has one', function () {
    // The template gate above proves the partial is PRESENT. It cannot prove `$issuerLogo` is ever
    // non-null, and five of twelve services called `forView()` with NO argument — so the owner
    // statement, the document Jawad actually receives, rendered `$asset` in its own party block
    // while the logo beside the issuer name was unconditionally absent. Green gate, no logo.
    //
    // This is the half that checks the value can exist at all.
    $offenders = [];
    $checked = 0;

    foreach (File::allFiles(base_path('app/Services')) as $file) {
        $relative = str_replace(base_path().'/', '', $file->getPathname());
        $code = (string) $file->getContents();

        if (! str_contains($code, 'IssuingEntity::forView')) {
            continue;
        }

        $checked++;

        if (array_key_exists($relative, PORTFOLIO_DOCUMENTS)) {
            continue;
        }

        // COMMENTS STRIPPED FIRST, via PHP's own tokenizer. This sweep greps raw source, so a
        // docblock that mentions `IssuingEntity::forView()` in prose — describing the seam, three
        // lines above a call that does pass an asset — reported that service as an offender. A gate
        // that fires on a sentence is one that gets weakened rather than fixed. Not extracted to a
        // shared helper: a file-scope function of the same name already exists in another gate, and
        // two of them is a fatal redeclaration that exits the whole suite with no output.
        $stripped = '';

        foreach (token_get_all($code) as $token) {
            $stripped .= is_array($token)
                ? (in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true) ? ' ' : $token[1])
                : $token;
        }

        // `forView()` with nothing between the parentheses can never produce a logo.
        if (preg_match('~IssuingEntity::forView\(\s*\)~', $stripped)) {
            $offenders[] = $relative;
        }
    }

    expect($checked)->toBeGreaterThan(6, 'The sweep found almost no PDF services — it is looking at the wrong thing.');

    expect($offenders)->toBe([], implode("\n", [
        'These build a document and pass NO property to the issuer block, so the mall logo can never',
        'appear on it however many templates include the partial:',
        '  '.implode("\n  ", $offenders),
        '',
        'Pass the asset (`forView($asset)`), or the scope (`forViewScopedTo($assetIds)`) for a report',
        'that may span several. If the document genuinely has no property, add it to',
        'PORTFOLIO_DOCUMENTS saying which.',
    ]));
});

it('has no stale portfolio exemption', function () {
    $stale = [];

    foreach (PORTFOLIO_DOCUMENTS as $relative => $reason) {
        if (! file_exists(base_path($relative))) {
            $stale[] = "{$relative} (gone)";

            continue;
        }

        if (! str_contains((string) file_get_contents(base_path($relative)), 'IssuingEntity::forView')) {
            $stale[] = "{$relative} (no longer renders an issuer block)";
        }

        expect(strlen($reason))->toBeGreaterThan(40, "The exemption for {$relative} does not say why it has no property.");
    }

    expect($stale)->toBe([], 'Remove from PORTFOLIO_DOCUMENTS: '.implode(', ', $stale));
});

it('hands the templates a readable local path, never a URL', function () {
    // Driven with a REAL uploaded logo. The first version of this case asserted only the two null
    // paths — no asset, no logo — which is true under a `logoUrl()` implementation too, so it could
    // not fail on the decision it is named for. mpdf renders server-side, and a URL makes every
    // document depend on the box fetching its own public address: it fails as a missing image with
    // no error anyone sees.
    $asset = makeAsset();

    $file = tempnam(sys_get_temp_dir(), 'logo').'.png';
    // A 1×1 PNG — the smallest thing medialibrary will accept as an image.
    file_put_contents($file, base64_decode(
        'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg=='
    ));

    $asset->addMedia($file)->toMediaCollection('logo');

    $logo = IssuingEntity::forView($asset->fresh())['issuerLogo'];

    expect($logo)->toBeString()
        ->and(str_starts_with($logo, 'http'))->toBeFalse('A URL makes the PDF depend on the box fetching its own public address.')
        ->and(is_file($logo))->toBeTrue('mpdf is handed a path it cannot read.');

    // The control: no logo uploaded is null, and the templates then render the text header alone —
    // exactly what they did before this existed.
    expect(IssuingEntity::forView(makeAsset())['issuerLogo'])->toBeNull()
        ->and(IssuingEntity::forView(null)['issuerLogo'])->toBeNull();
});

it('brands a single-property report and leaves a portfolio one plain', function () {
    // `forViewScopedTo()` is the rule the four financial statements and the facility work log share.
    // One mall means one letterhead; two or more, or none, is a portfolio document.
    $one = makeAsset();
    $two = makeAsset();

    expect(IssuingEntity::forViewScopedTo([$one->id])['issuerName'])
        ->toBe(IssuingEntity::forView($one)['issuerName'])
        ->and(IssuingEntity::forViewScopedTo([$one->id, $two->id])['issuerLogo'])->toBeNull()
        ->and(IssuingEntity::forViewScopedTo(null)['issuerLogo'])->toBeNull()
        ->and(IssuingEntity::forViewScopedTo([])['issuerLogo'])->toBeNull();
});
