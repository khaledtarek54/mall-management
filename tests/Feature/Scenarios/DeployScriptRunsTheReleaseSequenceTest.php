<?php

/**
 * The deploy script has to contain the steps whose omission is SILENT.
 *
 * `deploy.sh` exists for one reason, stated in its own header: a release typed by hand fails by a
 * step being quietly skipped rather than by an error. That argument only holds while the script
 * actually contains the steps — and on 2026-08-23 it did not. It ran the nine commands
 * `PRODUCTION-RUNBOOK.md §2` lists in its code block and skipped the two the prose beside that block
 * calls REQUIRED:
 *
 *   * `atriom:install --force` — a migration creates an EMPTY catalogue table and a seeder fills
 *     it, so an upgraded box got the schema and none of the content. Worse, it re-syncs the RBAC
 *     catalogue: a permission that exists only in the seeder file leaves its screen absent from the
 *     navigation for everyone, INCLUDING super_admin, because `canAccess()` just returns false.
 *     That is how the Trades and Failure-code registers shipped invisible on 2026-08-20.
 *   * `atriom:rebuild-search` — the fold blob is written on save, so an upgrade leaves every
 *     existing row on the previous release's fold and the search bar reports that a record does not
 *     exist.
 *
 * Neither raises an error, which is exactly why they belong in a script rather than in a doc.
 *
 * This gate is about the SCRIPT, not about the deploy: it cannot prove a box deployed correctly.
 * What it can do is fail the build when a step this project has already been bitten by disappears
 * from the one place that runs it.
 */
/**
 * The EXECUTABLE script — every `#` comment line removed.
 *
 * This matters more than it looks. `deploy.sh`'s own header explains why `queue:restart` and
 * `npm run build` must not be skipped, so both strings appear in PROSE hundreds of bytes before
 * they appear as commands: a gate reading the whole file would be satisfied by a script that only
 * TALKS about restarting workers, and the first ordering assertion written that way compared the
 * position of a comment against the position of a command.
 */
$deploy = fn (): string => implode("\n", array_filter(
    file(base_path('deploy.sh'), FILE_IGNORE_NEW_LINES),
    fn (string $line): bool => ! str_starts_with(ltrim($line), '#'),
));

it('is present and executable', function () {
    // NOTE on style: every assertion in this file is a PHPUnit one, because Pest's `toContain()`
    // and `toBeGreaterThan()` take FURTHER NEEDLES rather than a failure message — passing a
    // sentence as the second argument searches the script for that sentence, and the test fails
    // for a reason that has nothing to do with the thing being checked. A gate whose failure
    // message is wrong is a gate somebody deletes.
    $this->assertFileExists(base_path('deploy.sh'));
    $this->assertTrue(is_executable(base_path('deploy.sh')),
        'deploy.sh is not executable — `./deploy.sh` would fail with Permission denied');
});

it('runs every step whose omission fails silently', function () use ($deploy) {
    // needle => what breaks, in production, with no error, when it is missing.
    $required = [
        'composer install --no-dev' => 'the release runs on the previous version of every dependency',
        'npm ci' => 'the lockfile stops being the release',
        'npm run build' => 'BOTH PANELS render as unstyled HTML, and /handbook answers 503',
        'filament:assets' => "Filament's own JS and icons stay on the previous package version",
        'migrate --force' => 'the new code runs against the old schema',
        'atriom:install --force' => 'a new catalogue arrives empty and a new permission hides its screen from EVERYONE',
        'config:cache' => 'the previous release\'s cached config survives, which reads as a bad .env',
        'route:cache' => 'route resolution stays on the previous release',
        'view:cache' => 'the first request compiles every view',
        'event:cache' => 'listeners stay on the previous release',
        // Measured 2026-09-02 over 25 real FPM requests each way, toggled twice: WITH it a page
        // was best 0.045s / median 0.063s, WITHOUT best 0.096s / median 0.144s. Filament otherwise
        // rediscovers 66 resources and 35 pages, and Blade rescans its icon sets, on EVERY request.
        // It is NOT covered by `optimize`, and the `optimize:clear` above deletes it — so its
        // omission is silent and permanent: the panel runs at half speed with nothing in any log.
        'filament:optimize' => 'Filament rediscovers every resource, page and icon on EVERY request — roughly doubling page time, silently',
        'atriom:rebuild-search' => 'every existing row keeps the previous release\'s fold and the search bar says the record does not exist',
        'queue:restart' => 'workers keep running the OLD code against the NEW schema',
        'atriom:preflight' => 'the deploy reports no verdict at all',
    ];

    $script = $deploy();
    $found = 0;

    foreach ($required as $needle => $consequence) {
        $this->assertStringContainsString($needle, $script,
            "deploy.sh no longer runs `{$needle}` — {$consequence}");
        $found++;
    }

    // The gate must assert it swept something: a needle list that silently emptied would pass.
    $this->assertSame(count($required), $found);
    $this->assertGreaterThan(10, $found);
});

it('orders the steps the way a release actually depends on them', function () use ($deploy) {
    $script = $deploy();
    $at = fn (string $needle): int => strpos($script, $needle);

    // Reference data is seeded INTO the schema the migration just created.
    $this->assertGreaterThan($at('migrate --force'), $at('atriom:install --force'),
        'atriom:install must run AFTER migrations — it seeds rows into tables the migration creates');

    // Assets are built before the site goes down, so downtime is the migration and nothing else.
    $this->assertLessThan($at('artisan down'), $at('npm run build'),
        'the asset build belongs BEFORE maintenance mode — building while down is downtime for nothing');

    // Workers must not pick up the new code before the schema it expects exists.
    $this->assertGreaterThan($at('migrate --force'), $at('queue:restart'),
        'workers must be restarted AFTER the schema moves, not before');

    // The re-fold is a data pass that is safe live; holding the site down for it trades a silent
    // bug for real downtime.
    $this->assertGreaterThan($at('artisan up'), $at('atriom:rebuild-search'),
        'the search re-fold belongs AFTER the site is live — it is safe there and costs no downtime');

    // The verdict is last, or it is a verdict on a half-deployed box.
    $this->assertGreaterThan($at('queue:restart'), $at('atriom:preflight'),
        'the preflight is the deploy\'s verdict and must run last');
});

it('keeps the runbook and the script talking about the same sequence', function () use ($deploy) {
    // The runbook's prose is what told us these two steps were required while the script omitted
    // them. If a step leaves the script, the doc that promises it must move in the same commit.
    $runbook = file_get_contents(base_path('docs/operations/PRODUCTION-RUNBOOK.md'));
    $script = $deploy();

    foreach (['atriom:install', 'npm run build', 'queue:restart', 'migrate --force'] as $step) {
        $this->assertStringContainsString($step, $runbook,
            "PRODUCTION-RUNBOOK.md no longer documents `{$step}`, which deploy.sh still runs");
        $this->assertStringContainsString($step, $script,
            "deploy.sh no longer runs `{$step}`, which PRODUCTION-RUNBOOK.md still promises");
    }
});
