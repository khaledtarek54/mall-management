<?php

use App\Models\Unit;
use App\Support\WriteSurfaces;

/**
 * `atriom:doors` — the command that answers *what else writes this record?*
 *
 * The registry it reads is gated by `WriteSurfacesConformanceTest`. What is tested HERE is the
 * command's own layer, which that gate cannot see: the three ways of asking, the exit codes a hook
 * would key on, and the rendering — the part where a door is turned into a line somebody acts on.
 *
 * The `--check-diff` mode is deliberately exercised against a REF rather than a fabricated diff.
 * Shelling out to git is the thing under test there; stubbing it would leave the one line that can
 * actually be wrong — how the changed paths are compared against door keys — unexercised, which is
 * the false-pass shape this project keeps recording.
 */
it('lists every door onto a record', function () {
    $this->artisan('atriom:doors', ['subject' => 'Unit'])
        ->assertSuccessful()
        ->expectsOutputToContain('app/Filament/Imports/UnitImporter.php')
        ->expectsOutputToContain('app/Filament/Exports/UnitExporter.php');
});

it('accepts a fully qualified class as readily as a bare name', function () {
    $this->artisan('atriom:doors', ['subject' => Unit::class])
        ->assertSuccessful()
        ->expectsOutputToContain('app/Filament/Imports/UnitImporter.php');
});

it('refuses a subject that is not a record, rather than reporting no doors', function () {
    // The failure direction that matters: a typo'd model answering "0 doors" reads as *nothing else
    // writes this*, which is the exact false reassurance the command exists to prevent.
    $this->artisan('atriom:doors', ['subject' => 'NoSuchThingAtAll'])
        ->assertFailed()
        ->expectsOutputToContain('No such model');
});

it('names the siblings of one file when given a path', function () {
    $this->artisan('atriom:doors', ['subject' => 'app/Filament/Imports/UnitImporter.php'])
        ->assertSuccessful()
        ->expectsOutputToContain('app/Filament/Admin/Resources/Units/Schemas/UnitForm.php')
        // ...and never the file it was asked about.
        ->doesntExpectOutputToContain('app/Filament/Imports/UnitImporter.php  ');
});

it('passes when a change touches every door onto the records it touches', function () {
    // An empty diff is the clean case, and it must EXIT ZERO or the check cannot sit in a hook:
    // a guard that fails on a no-op change is one that gets removed within a day.
    $this->artisan('atriom:doors', ['--check-diff' => 'HEAD'])
        ->assertSuccessful();
})->skip(fn (): bool => trim((string) shell_exec('git status --porcelain 2>/dev/null')) !== '',
    'The working tree is dirty, so the clean-diff case cannot be observed here.');

it('reports every kind of door it knows about', function () {
    // The rendering half of the premise assertion in the conformance gate: a KIND that stops being
    // printed is invisible to the reader even when the registry still collects it.
    $this->artisan('atriom:doors', ['--all' => true])
        ->assertSuccessful()
        ->expectsOutputToContain(WriteSurfaces::RESOURCE_FORM)
        ->expectsOutputToContain(WriteSurfaces::RELATION_MANAGER)
        ->expectsOutputToContain(WriteSurfaces::IMPORTER)
        ->expectsOutputToContain(WriteSurfaces::API_RESOURCE)
        ->expectsOutputToContain(WriteSurfaces::OFF_PANEL_CREATOR);
});
