<?php

use App\Support\Filament\AnnouncingCreateAction;
use App\Support\Filament\AnnouncingDeleteAction;
use App\Support\Filament\AnnouncingEditAction;
use App\Support\Filament\AuthorizedAction;
use App\Support\Filament\RefreshesRecordState;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Illuminate\Support\Facades\Schema;

/**
 * A record page must not be able to LOOK refreshed while showing pre-action data.
 *
 * Filament's `refreshFormData()` refills from the page's in-memory record and never re-reads it,
 * so behind any service that re-reads the row under a lock — which every money service here does,
 * correctly — the call is a no-op that reads as a fix. Nineteen call sites had that shape and the
 * suite was green throughout, because every test asserted the DATABASE row rather than the form.
 * {@see RefreshesRecordState} makes the re-read unconditional; this gate
 * makes sure a page cannot go back to calling the bare version.
 *
 * The bindings are checked for the same reason `ActionCallIsAuthorizedTest` checks its one: the
 * announcement layer rests on `make()` resolving through the container, and a Filament release
 * that switched to `new static` would remove it silently from every action at once.
 */
/** Every page class under app/Filament, discovered rather than listed. */
function refreshGatePageFiles(): array
{
    $files = [];
    $rii = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(app_path('Filament')));
    foreach ($rii as $file) {
        if (! $file->isDir() && $file->getExtension() === 'php') {
            $files[$file->getPathname()] = file_get_contents($file->getPathname());
        }
    }
    ksort($files);

    return $files;
}

it('has every page that refills form data going through the re-reading version', function () {
    $offenders = [];
    $checked = 0;

    foreach (refreshGatePageFiles() as $path => $src) {
        if (! str_contains($src, 'refreshFormData(')) {
            continue;
        }
        $checked++;

        if (! str_contains($src, 'use RefreshesRecordState;')) {
            $offenders[] = str_replace(base_path().'/', '', $path);
        }
    }

    // The sweep must have found something — a gate matching nothing passes for the wrong reason.
    expect($checked)->toBeGreaterThan(5);

    expect($offenders)->toBe([], implode("\n", [
        'These pages call refreshFormData() without App\Support\Filament\RefreshesRecordState.',
        'Filament\'s own version refills from the page\'s IN-MEMORY record — so behind any service',
        'that re-reads the row under lockForUpdate() (all of the money ones do) it refills the same',
        'stale values and the form keeps showing pre-action figures under a success toast.',
        'Fix: `use RefreshesRecordState;` on the page class.',
        '',
        ...$offenders,
    ]));
});

it('has no page declaring derived state paths it does not use', function () {
    $orphans = [];

    foreach (refreshGatePageFiles() as $path => $src) {
        if (str_contains($src, 'function derivedStatePaths(') && ! str_contains($src, 'use RefreshesRecordState;')) {
            $orphans[] = str_replace(base_path().'/', '', $path);
        }
    }

    expect($orphans)->toBe([], "derivedStatePaths() is only read by RefreshesRecordState — without the trait it is dead code that reads as wiring:\n".implode("\n", $orphans));
});

it('has every declared derived state path naming a real column', function () {
    // A typo'd path refills nothing and is indistinguishable from a working one — the same reason
    // DeletionPolicy verifies its blocked_by relations exist rather than trusting the string.
    $bad = [];
    $checkedPaths = 0;

    foreach (refreshGatePageFiles() as $path => $src) {
        if (! preg_match('/function derivedStatePaths\(\): array\s*\{\s*return \[(.*?)\];/s', $src, $m)) {
            continue;
        }
        if (! preg_match_all("/'([a-z_]+)'/", $m[1], $paths)) {
            continue; // a deliberately empty declaration (render-time TextEntry pages)
        }
        if (! preg_match('/protected static string \$resource = (\w+)::class;/', $src, $r)) {
            continue;
        }
        preg_match('/use ([\w\\\\]+\\\\'.$r[1].');/', $src, $fq);
        $resource = $fq[1] ?? null;
        if (! $resource || ! class_exists($resource)) {
            continue;
        }
        $table = (new ($resource::getModel()))->getTable();

        foreach ($paths[1] as $column) {
            $checkedPaths++;
            if (! Schema::hasColumn($table, $column)) {
                $bad[] = str_replace(base_path().'/', '', $path)." -> {$table}.{$column}";
            }
        }
    }

    expect($checkedPaths)->toBeGreaterThan(15);
    expect($bad)->toBe([], "Derived state paths that name no column — they refill nothing:\n".implode("\n", $bad));
});

it('still resolves every action class to the announcing subclass', function () {
    // If a Filament release switches make() to `new static`, these stop being true and the whole
    // refresh layer disappears without a single call site changing.
    expect(Action::make('x'))->toBeInstanceOf(AuthorizedAction::class)
        ->and(CreateAction::make())->toBeInstanceOf(AnnouncingCreateAction::class)
        ->and(EditAction::make())->toBeInstanceOf(AnnouncingEditAction::class)
        ->and(DeleteAction::make())->toBeInstanceOf(AnnouncingDeleteAction::class);
});
