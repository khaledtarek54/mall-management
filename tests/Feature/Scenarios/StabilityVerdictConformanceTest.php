<?php

use App\Support\Stability;

/**
 * `atriom:stability` — the rules that make its verdict worth reading.
 *
 * The tiers themselves are other people's tests; what is proved HERE is the arithmetic on top, and
 * every one of these is a property that, if it broke, would turn the command into a green tick over
 * nothing — which is the thing it was built to stop.
 */
it('never calls a tier that examined nothing a pass', function () {
    // THE rule. Every vacuity failure in this codebase's history looks like this from the outside:
    // a check that ran, exited zero, and had looked at nothing. `tests/Mysql` SKIPS silently off
    // MySQL; the browser suite ran ZERO specs for a month; a filter sweep swept a branch that
    // returned before any SQL. An exit code cannot tell those from a real pass — a count can.
    $verdict = Stability::verdict([
        'gates' => ['status' => Stability::NOT_VERIFIED, 'examined' => 0],
    ]);

    expect($verdict)->toBe(Stability::NOT_VERIFIED);
});

it('reports INCOMPLETE rather than folding it into a pass', function () {
    // Three states, not two. A two-state tool would have to call this either PASS (a lie) or FAIL
    // (which trains people to ignore it, the failure ConfigurationHealth records for advisory rows).
    expect(Stability::verdict([
        'gates' => ['status' => Stability::PASS, 'examined' => 600],
        'suite' => ['status' => Stability::NOT_VERIFIED, 'examined' => 0],
    ]))->toBe(Stability::NOT_VERIFIED);

    // ...and a genuine failure outranks an unchecked tier, or a broken build could hide behind a
    // skipped one.
    expect(Stability::verdict([
        'gates' => ['status' => Stability::FAIL, 'examined' => 600],
        'suite' => ['status' => Stability::NOT_VERIFIED, 'examined' => 0],
    ]))->toBe(Stability::FAIL);

    expect(Stability::verdict([
        'gates' => ['status' => Stability::PASS, 'examined' => 600],
        'doors' => ['status' => Stability::PASS, 'examined' => 1],
    ]))->toBe(Stability::PASS);
});

it('keeps the code verdict free of this install’s own problems', function () {
    // Measured on an ordinary laptop: `atriom:preflight` reports three FAILs — no queue worker, no
    // cron heartbeat, backups 560 hours old — every one true of a dev machine and none of them a
    // statement about the code. Folded together the verdict is permanently red, and a permanently
    // red check is one people stop reading.
    $results = [
        'gates' => ['status' => Stability::PASS, 'examined' => 600],
        'doors' => ['status' => Stability::PASS, 'examined' => 1],
        'install' => ['status' => Stability::FAIL, 'examined' => 5],
    ];

    expect(Stability::verdict($results, Stability::CODE))->toBe(Stability::PASS)
        ->and(Stability::verdict($results, Stability::INSTALL))->toBe(Stability::FAIL);
});

it('asks a scope it has no tier for rather than answering yes', function () {
    // An empty set is not a pass. Without this, filtering to a scope that no tier declares would
    // report PASS over nothing at all — the vacuity bug one level up, in the arithmetic itself.
    expect(Stability::verdict(['gates' => ['status' => Stability::PASS, 'examined' => 1]], Stability::INSTALL))
        ->toBe(Stability::NOT_VERIFIED);
});

it('declares every tier fully, and can run each one it declares', function () {
    expect(Stability::TIERS)->not->toBeEmpty();

    foreach (Stability::TIERS as $key => $tier) {
        expect($tier)->toHaveKeys(['title', 'slow', 'scope', 'why'])
            ->and($tier['scope'])->toBeIn([Stability::CODE, Stability::INSTALL])
            // The `why` is not decoration: a tier that goes red has to explain what it was
            // protecting, or the reader cannot tell an emergency from a nuisance.
            ->and(strlen($tier['why']))->toBeGreaterThan(60);

        // A tier with no runner is a row in a registry that can never report — the inert-settings
        // shape. `run()` dispatches by convention, so the convention is asserted.
        expect(method_exists(Stability::class, 'run'.ucfirst($key)))
            ->toBeTrue("Stability::TIERS declares `{$key}` and there is no run".ucfirst($key).'() to run it');
    }
});

it('has a mutation for every conformance gate, so a green gate is evidence', function () {
    // **This is the property the whole stability idea rests on.** A gate that cannot fail is worse
    // than no gate, because it is counted as evidence — and coverage decays silently: on
    // 2026-09-10 it was 70 of 102, with four mutation definitions whose anchors had gone stale, and
    // the audit's own headline "70/74 caught" read like four failures when it was four gates never
    // run at all.
    //
    // Closed to 104 of 104 the same day. Without this assertion the next gate somebody writes is
    // unproven the moment it lands, and nobody finds out until they go looking — which is exactly
    // how it got to 32 uncovered.
    $mutations = json_decode((string) file_get_contents(base_path('docs/qa/scripts/gate-mutations.json')), true) ?: [];

    expect($mutations)->not->toBeEmpty('gate-mutations.json is missing or unreadable');

    $covered = array_flip(array_column($mutations, 'gate'));

    $gates = array_merge(
        glob(base_path('tests/Feature/*/*ConformanceTest.php')) ?: [],
        glob(base_path('tests/Feature/*ConformanceTest.php')) ?: [],
    );

    // The premise: a sweep that discovered nothing would report full coverage over an empty set.
    expect(count($gates))->toBeGreaterThan(90);

    $unproven = [];

    foreach ($gates as $gate) {
        $relative = str_replace(base_path().'/', '', $gate);

        if (! isset($covered[$relative])) {
            $unproven[] = $relative;
        }
    }

    expect($unproven)->toBe([],
        "These conformance gates have no mutation, so nothing shows they can fail:\n  - "
        .implode("\n  - ", $unproven)
        ."\n\nAdd an entry to docs/qa/scripts/gate-mutations.json that reintroduces the defect the "
        ."gate is named for, and run `python3 docs/qa/scripts/gate-audit.py` to confirm it goes RED.",
    );

    // ...and no mutation may name a gate that no longer exists, which would inflate the count.
    foreach (array_keys($covered) as $gate) {
        expect(file_exists(base_path($gate)))->toBeTrue("gate-mutations.json names a gate that is gone: {$gate}");
    }
});

it('finds the conformance gates it claims to run', function () {
    // The premise, as a FLOOR rather than an exact count — the shape TableSortPolicyConformanceTest
    // gets wrong, where a hardcoded 147 turns every legitimate new table into a red build and
    // trains people to bump the number instead of reading it.
    expect(count(Stability::gateFiles()))->toBeGreaterThan(90);
});
