<?php

/*
|--------------------------------------------------------------------------
| The registry-derived doc sections are current
|--------------------------------------------------------------------------
| A generator alone does not stop drift — it just makes fixing drift easy, which is not the same
| thing. `atriom:dump-system-census` has existed for a while and the census was still 113 test files
| and 28 migrations out of date, because nothing failed when nobody ran it.
|
| So this compares what the generator WOULD write against what is actually in the doc, and fails
| with the command to run. Change a registry without regenerating and the build tells you, the same
| way GlRegistryConformanceTest tells you about an unregistered journalizer.
|
| Found when this was written: docs/modules/21-general-ledger.md described 12 posting sources while
| LedgerPoster registered 21 — nine sources (depreciation, disposals, advances, custody, owner
| statements, disbursements, tenant credit) had no mention anywhere in the GL module doc.
*/

use App\Console\Commands\DumpRegistriesCommand;
use App\Services\Accounting\LedgerPoster;

/** The exact text between a section's GENERATED markers, or null when the markers are absent. */
function generatedBlock(string $path, string $marker): ?string
{
    $body = file_get_contents($path);

    $open = "<!-- GENERATED:{$marker} — do not edit by hand; run `php artisan atriom:dump-registries` -->";
    $close = "<!-- /GENERATED:{$marker} -->";

    if (! str_contains($body, $open) || ! str_contains($body, $close)) {
        return null;
    }

    $start = strpos($body, $open) + strlen($open);

    return trim(substr($body, $start, strpos($body, $close) - $start));
}

it('has the GL posting-source table in sync with the registry', function () {
    $command = new DumpRegistriesCommand;

    $inDoc = generatedBlock(base_path('docs/modules/21-general-ledger.md'), 'gl-sources');

    expect($inDoc)->not->toBeNull('the GENERATED:gl-sources markers are missing from the GL module doc')
        ->and($inDoc)->toBe(
            trim($command->glSources()),
            'The GL posting-source table is stale. Run: php artisan atriom:dump-registries'
        );
});

it('has the property-isolation classification in sync with the registry', function () {
    $command = new DumpRegistriesCommand;

    $inDoc = generatedBlock(base_path('docs/PROPERTY-ISOLATION.md'), 'isolation-classification');

    expect($inDoc)->not->toBeNull('the GENERATED:isolation-classification markers are missing')
        ->and($inDoc)->toBe(
            trim($command->isolationClassification()),
            'The property-isolation classification is stale. Run: php artisan atriom:dump-registries'
        );
});

it('documents every GL posting source', function () {
    // The property the table exists for, asserted independently of the generator: if a journalizer
    // is registered, the doc names it. This is what was broken — nine of 21 were absent.
    $doc = file_get_contents(base_path('docs/modules/21-general-ledger.md'));

    $undocumented = [];

    foreach (array_keys(LedgerPoster::JOURNALIZERS) as $source) {
        if (! str_contains($doc, class_basename($source))) {
            $undocumented[] = class_basename($source);
        }
    }

    expect($undocumented)->toBe([], implode('', [
        'These post to the GL but appear nowhere in the module doc: '.implode(', ', $undocumented).'. ',
        'Run: php artisan atriom:dump-registries',
    ]));
});
