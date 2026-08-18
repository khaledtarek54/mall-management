<?php

use App\Support\ServiceReachability;

/**
 * Every service must be reachable from something a person or a schedule can start.
 *
 * The gate behind {@see ServiceReachability} — see that class for why it exists and what it caught.
 * In short: every other gate here checks that a thing is CLASSIFIED; none checked that a thing is
 * REACHABLE, and four fully-built, fully-tested services turned out to be unusable.
 *
 * Kept to plain file scanning rather than reflection: the point is to see what the CODE says, and a
 * container-resolved class does not announce its callers to reflection either.
 */

/** Every PHP file under a path, relative to the project root. */
function reachFiles(string $relativeDir): array
{
    $base = base_path($relativeDir);
    if (! is_dir($base)) {
        return [];
    }

    $out = [];
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($base)) as $file) {
        if (str_ends_with($file->getPathname(), '.php')) {
            $out[] = $file->getPathname();
        }
    }

    return $out;
}

it('reaches every service from something a person or a schedule can start', function () {
    $serviceFiles = reachFiles('app/Services');
    expect($serviceFiles)->not->toBeEmpty(); // the sweep must actually sweep something

    // basename => path. Basenames are unique across app/Services (the helper-uniqueness culture
    // extends here); if that ever stops being true the assertion below catches it.
    $services = [];
    foreach ($serviceFiles as $path) {
        $services[basename($path, '.php')] = $path;
    }
    expect(count($services))->toBe(count($serviceFiles), 'Two services share a basename — the scanner keys on it.');

    $contents = [];
    foreach ($serviceFiles as $path) {
        $contents[$path] = file_get_contents($path);
    }

    // Seed: everything the entry-point layers mention.
    $reachable = [];
    $frontier = [];
    foreach (ServiceReachability::ENTRY_POINTS as $dir) {
        foreach (reachFiles($dir) as $path) {
            $src = file_get_contents($path);
            foreach ($services as $name => $_) {
                if (! isset($reachable[$name]) && preg_match('/\b'.preg_quote($name, '/').'\b/', $src)) {
                    $reachable[$name] = true;
                    $frontier[] = $name;
                }
            }
        }
    }

    // Closure: a reachable service's own file can reach further services.
    while ($frontier !== []) {
        $name = array_pop($frontier);
        $src = $contents[$services[$name]] ?? '';

        foreach ($services as $candidate => $_) {
            if (! isset($reachable[$candidate]) && $candidate !== $name
                && preg_match('/\b'.preg_quote($candidate, '/').'\b/', $src)) {
                $reachable[$candidate] = true;
                $frontier[] = $candidate;
            }
        }
    }

    $exemptNames = [];
    foreach (array_keys(ServiceReachability::EXEMPT) as $fqcn) {
        $exemptNames[] = str_contains($fqcn, '\\') ? class_basename($fqcn) : $fqcn;
    }

    $orphans = array_values(array_diff(array_keys($services), array_keys($reachable), $exemptNames));
    sort($orphans);

    expect($orphans)->toBe([], "These services cannot be started by any operator, schedule, job, route or model hook — they are built, possibly tested, and unusable:\n  - ".implode("\n  - ", $orphans)."\n\nWire one to a Filament action, a console command or the scheduler; or register it in App\\Support\\ServiceReachability::EXEMPT with a concrete reason. A green test file is not reachability — that is exactly what hid BillUnitOwnershipsService.");
});

it('rejects an exemption for a service that is actually reachable', function () {
    // The mirror of the rule above: a stale exemption silently turns the gate off for that class,
    // which is how a registry rots. Same shape as ScreenGuides rejecting a stale EXEMPT entry.
    $names = array_map(
        fn (string $f) => basename($f, '.php'),
        reachFiles('app/Services'),
    );

    foreach (array_keys(ServiceReachability::EXEMPT) as $fqcn) {
        $base = str_contains($fqcn, '\\') ? class_basename($fqcn) : $fqcn;
        expect($names)->toContain($base, "ServiceReachability::EXEMPT lists {$fqcn}, which is not a service under app/Services. Remove the stale entry.");
    }
})->skip(ServiceReachability::EXEMPT === [], 'No exemptions registered — nothing to check.');

it('states a reason for every exemption', function () {
    foreach (ServiceReachability::EXEMPT as $fqcn => $reason) {
        expect(trim($reason))->not->toBe('', "{$fqcn} is exempt with no reason.")
            // "later" is the exact word VendorScorecardService's backlog entry used for a month.
            ->and(strlen(trim($reason)))->toBeGreaterThan(20, "{$fqcn}'s exemption reason is too thin to review: {$reason}");
    }
})->skip(ServiceReachability::EXEMPT === [], 'No exemptions registered — nothing to check.');
