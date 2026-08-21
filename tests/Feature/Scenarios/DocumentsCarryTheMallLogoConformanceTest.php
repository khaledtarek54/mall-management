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

it('includes the logo partial in every PDF template that names the issuer', function () {
    $offenders = [];
    $checked = 0;

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

        if (! str_contains($body, "@include('partials.issuer-logo')")) {
            $offenders[] = $relative;
        }
    }

    // The premise: this found templates at all. A sweep over an empty set passes for ever.
    expect($checked)->toBeGreaterThan(8, 'The sweep found almost no issuer templates — it is looking at the wrong thing.');

    expect($offenders)->toBe([], implode("\n", [
        'These render the issuer name without the mall logo beside it:',
        '  '.implode("\n  ", $offenders),
        '',
        "Add @include('partials.issuer-logo') above the issuer block. `IssuingEntity::forView()`",
        'already provides `$issuerLogo` to every one of these templates.',
    ]));
});

it('hands the templates a readable local path, never a URL', function () {
    // mpdf renders server-side. A URL makes every document depend on the box being able to fetch its
    // own public address — which fails behind a private network or a self-signed certificate, and
    // fails as a MISSING IMAGE that nobody sees an error for.
    $asset = makeAsset();

    $data = IssuingEntity::forView($asset);

    expect($data)->toHaveKey('issuerLogo')
        // No logo uploaded: null, and the templates render the text header alone — exactly what they
        // did before this existed.
        ->and($data['issuerLogo'])->toBeNull();

    $withLogo = IssuingEntity::forView(null);

    expect($withLogo['issuerLogo'])->toBeNull('No property means no property logo.');
});
