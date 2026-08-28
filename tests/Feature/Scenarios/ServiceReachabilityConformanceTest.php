<?php

use App\Support\ServiceReachability;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

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

// ───────────── A reachable CLASS is not a reachable METHOD (2026-08-28) ─────────────

/**
 * `WriteOffInvoiceService::reverse()` — bad-debt recovery — was 40 lines, fully written and tested,
 * and called by **two test files and nothing else**. This gate said the class was reachable, because
 * the `write_off` action calls `write()`. Nobody asked about the other public method, so a written-off
 * debt the tenant later paid could only be re-billed (double-counting revenue) or booked as
 * miscellaneous income (losing the AR history).
 *
 * Scoped to services that MOVE MONEY: an unreachable method on a report builder is dead code, and an
 * unreachable one here is a money operation nobody can perform.
 */
it('finds a caller for every public method of every money service', function () {
    // Files that can START work, plus the other services (a service called only by another service
    // is fine when that one is reachable, which the class-level closure above already proves).
    $files = collect(ServiceReachability::ENTRY_POINTS)
        ->flatMap(fn (string $dir) => is_dir(base_path($dir)) ? File::allFiles(base_path($dir)) : [])
        ->merge(File::allFiles(app_path('Services')))
        ->filter(fn ($f) => $f->getExtension() === 'php')
        ->mapWithKeys(fn ($f) => [$f->getPathname() => $f->getContents()]);

    $orphans = [];
    $examined = 0;

    foreach (File::allFiles(app_path('Services')) as $file) {
        $name = $file->getFilenameWithoutExtension();

        if ($file->getExtension() !== 'php' || ! Str::startsWith($name, ServiceReachability::MONEY_SERVICE_PATTERNS)) {
            continue;
        }

        // **The call has to be BOUND to this service**, and getting that wrong twice is why this
        // comment is long. A bare `->reverse(` search matches `SettleCustodyService::reverse()` and
        // `ApplyDepositToInvoiceService::reverse()`; narrowing to files that merely NAME the service
        // is no better, because `EditInvoice` names four money services and calls `->reverse(` on two
        // of them. Both drafts stayed GREEN with the bad-debt recovery unwired — the exact defect
        // this gate was written for. **A gate that cannot fail on its own motivating example is
        // worth nothing.**
        //
        // So the call must resolve through the container to THIS class: either directly, or through
        // a variable assigned from it — the two shapes this codebase actually writes.
        $calls = [];
        foreach ($files as $path => $body) {
            if ($path === $file->getPathname() || ! str_contains($body, $name.'::class')) {
                continue;
            }

            // app(FooService::class)->bar(...)
            preg_match_all('/app\(\s*'.preg_quote($name, '/').'::class\s*\)\s*->\s*(\w+)\(/', $body, $direct);
            $calls = array_merge($calls, $direct[1]);

            // $svc = app(FooService::class);  …  $svc->bar(...)
            //
            // An AMBIGUOUS variable counts for nothing: `EditInvoice` resolves four money services,
            // and while two of them were both held in `$svc` a call on either read as a call on
            // both — which is how the third draft of this gate ALSO stayed green with the bad-debt
            // recovery unwired. Requiring the name to belong to one service in the file is what
            // finally gave it teeth, and it costs a call site nothing but a better variable name.
            preg_match_all('/\$(\w+)\s*=\s*app\(\s*'.preg_quote($name, '/').'::class\s*\)/', $body, $vars);
            foreach (array_unique($vars[1]) as $var) {
                preg_match_all('/\$'.preg_quote($var, '/').'\s*=\s*app\(\s*(\w+)::class/', $body, $owners);
                if (count(array_unique($owners[1])) > 1) {
                    continue;
                }
                preg_match_all('/\$'.preg_quote($var, '/').'\s*->\s*(\w+)\(/', $body, $viaVar);
                $calls = array_merge($calls, $viaVar[1]);
            }
        }
        $calls = array_flip($calls);

        preg_match_all('/^    public function (\w+)\(/m', $file->getContents(), $m);

        foreach ($m[1] as $method) {
            if (in_array($method, ['__construct', '__invoke'], true)) {
                continue;
            }

            $examined++;

            if (isset($calls[$method])) {
                continue;
            }

            // Called by a SIBLING method on the same class. The class-level closure above already
            // proves the class is reachable, so an internally-called public method is reached
            // through whatever entry point reaches its caller — the same transitive rule that lets
            // a service called only by another service pass. Without this the gate reports
            // `ApplyDepositToInvoiceService::apply()`, which `settleOpenAr()` calls, as an orphan.
            // It does NOT rescue the shape this gate exists for: nothing called
            // `WriteOffInvoiceService::reverse()` from anywhere, inside the class or out.
            if (str_contains($file->getContents(), "\$this->{$method}(")) {
                continue;
            }
            if (array_key_exists($name.'::'.$method, ServiceReachability::EXEMPT_METHODS)) {
                continue;
            }

            $orphans[] = "{$name}::{$method}() has no caller outside its own class — it is a money "
                .'operation nobody can start. Wire it to an action, or delete it.';
        }
    }

    // **Assert the sweep found something before reporting on it.** This project has had three gates
    // go quietly blind — a green report over a set they had stopped collecting — and the tell was
    // never the gate, it was the symptom it existed to prevent. 21 methods across 13 services when
    // this was written; the floor sits below that so ordinary churn does not trip it, and well above
    // zero so a broken pattern match does.
    expect($examined)->toBeGreaterThan(10, 'The money-service sweep matched almost nothing — check '
        .'ServiceReachability::MONEY_SERVICE_PATTERNS against the filenames in app/Services.');

    expect($orphans)->toBe([], "\n".implode("\n", $orphans));
});
