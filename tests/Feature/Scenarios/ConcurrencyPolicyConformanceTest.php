<?php

use App\Support\ConcurrencyPolicy;

/**
 * Concurrency was the one invariant class with no gate — and the one this project has been bitten by.
 *
 * `SQLiteGrammar::compileLock()` returns `''` and the suite runs on sqlite `:memory:`, so all 111
 * `lockForUpdate()` call sites are **inert in every test run**. Production is unaffected — MySQL
 * honours them — but *deleting a lock turned nothing red*, in the exact area where the two races
 * that actually happened here live (the unit double-booking, the Paymob double-charge).
 *
 * This gate closes the deletion hole: every locking file is registered with the number of locks it
 * holds, so one dropped in a refactor fails the build. `Tests\Support\LockSpy` goes further for the
 * `PROVEN` set by making the lock observable on SQLite, so those are tested rather than declared.
 */
function lockingFilesOnDisk(): array
{
    $files = [];

    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(app_path()));
    foreach ($iterator as $file) {
        if (! $file->isFile() || $file->getExtension() !== 'php') {
            continue;
        }

        $count = preg_match_all('/->(?:lockForUpdate|sharedLock)\(|Cache::lock\(/', (string) file_get_contents($file->getPathname()));

        if ($count > 0) {
            $files[str_replace(base_path().'/', '', $file->getPathname())] = $count;
        }
    }

    ksort($files);

    return $files;
}

it('registers every file that takes a database lock', function () {
    // Fail on UNCLASSIFIED: a new critical section forces somebody to say what it protects, rather
    // than inheriting whichever assumption the author had in mind.
    $unregistered = array_diff_key(lockingFilesOnDisk(), ConcurrencyPolicy::expected());

    expect(array_keys($unregistered))->toBe([],
        "These files take a lock and are not in App\\Support\\ConcurrencyPolicy:\n  ".
        implode("\n  ", array_keys($unregistered)));
});

it('fails when a registered file stops locking — the deletion this gate exists for', function () {
    // The whole point. A lock removed during a refactor is invisible on sqlite: every test still
    // passes, and the race only appears in production under load.
    $onDisk = lockingFilesOnDisk();

    $vanished = array_values(array_diff(array_keys(ConcurrencyPolicy::expected()), array_keys($onDisk)));

    expect($vanished)->toBe([],
        "These registered critical sections no longer take any lock — was that intended?\n  ".
        implode("\n  ", $vanished).
        "\nIf the file was deleted or genuinely no longer needs a lock, remove its registry entry ".
        'in the same commit. If not, the guard is gone.');
});

it('pins the number of locks in each file, so one dropped from many still fails', function () {
    // File-level presence is not enough: `CreditNoteService` holds eleven. Losing one of those is
    // exactly the refactor accident this catches, and the count is what makes it visible.
    $onDisk = lockingFilesOnDisk();
    $expected = ConcurrencyPolicy::expected();

    $drift = [];
    foreach ($expected as $file => $count) {
        if (! isset($onDisk[$file]) || $onDisk[$file] === $count) {
            continue;
        }
        $drift[] = "{$file}: registry says {$count}, source has {$onDisk[$file]}";
    }

    expect($drift)->toBe([],
        "Lock count drift — confirm the change was intended, then update the registry:\n  ".
        implode("\n  ", $drift));
});

it('states what each proven critical section protects', function () {
    // A registry of paths is a list. The race is what the next person reads before deciding whether
    // the lock can go.
    $thin = collect(ConcurrencyPolicy::PROVEN)
        ->filter(fn (array $e): bool => strlen(trim($e['protects'])) < 60)
        ->keys()->all();

    expect($thin)->toBe([], 'PROVEN entries with no real description of the race: '.implode(', ', $thin));
});

it('keeps the two tiers disjoint, so a section cannot be half-classified', function () {
    $both = array_intersect(array_keys(ConcurrencyPolicy::PROVEN), array_keys(ConcurrencyPolicy::REGISTERED));

    expect(array_values($both))->toBe([], 'Listed in both PROVEN and REGISTERED: '.implode(', ', $both));
});
