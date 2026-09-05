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
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;

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

// ─────────── A document must not tell someone to run a command that does not exist ───────────

/**
 * **A runbook step that cannot run is worse than a missing one.** Found 2026-08-28 in
 * `docs/qa/POST-STAGING-BACKLOG.md`, which told whoever deployed to staging that the dunning cadence
 * "needs `php artisan settings:migrate` as well". There is no such command —
 * `spatie/laravel-settings` v3 ships `make:setting`, `make:settings-migration`, `settings:discover`
 * and two cache commands, and nothing else — and it was unnecessary anyway, because settings
 * migrations live in `database/settings/` and are applied by the ordinary `migrate` that
 * `deploy.sh` already runs.
 *
 * The failure mode is what earns this a gate: the operator sees the step fail and concludes the
 * cadence did not land, when it already had. A wrong instruction sends someone looking for a problem
 * that does not exist, which costs more than silence.
 *
 * Checks the docs tree AND `CLAUDE.md`, because the working guide is the most-followed runbook here.
 * Reads the artisan registry rather than a list kept beside it, so a renamed command is caught by the
 * rename rather than by someone remembering this test.
 */
it('never documents an artisan command that is not registered', function () {
    $registered = collect(Artisan::all())->keys()->all();

    $files = collect(File::allFiles(base_path('docs')))
        ->filter(fn ($f) => $f->getExtension() === 'md')
        ->map(fn ($f) => [$f->getRelativePathname(), $f->getContents()])
        ->push(['CLAUDE.md', file_get_contents(base_path('CLAUDE.md'))]);

    $problems = [];

    foreach ($files as [$name, $body]) {
        preg_match_all('/php artisan ([a-z][a-z0-9:_-]+)/', $body, $m);

        foreach (array_unique($m[1]) as $command) {
            // A doc may quote a command inside a "there is no such command" correction — the very
            // note this gate was born from. Skip a mention the sentence itself marks as absent, or
            // the gate makes it impossible to write down what was wrong.
            if (str_contains($body, 'no such command') && ! in_array($command, $registered, true)) {
                continue;
            }

            if (! in_array($command, $registered, true)) {
                $problems[] = "{$name} says `php artisan {$command}`, which is not a registered command.";
            }
        }
    }

    expect($problems)->toBe([], "\n".implode("\n", $problems));
});
