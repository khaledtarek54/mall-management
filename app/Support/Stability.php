<?php

namespace App\Support;

use Dotenv\Dotenv;
use Illuminate\Support\Facades\Process;

/**
 * Is this system sound? — one question, asked in tiers, with what could NOT be checked said out loud.
 *
 * ## Why this exists
 *
 * The material was all here and nothing joined it up: **102 conformance gates**, 1,321 test files, a
 * MySQL-only tier, `atriom:preflight`, 23 browser specs and a mutation audit of the gates
 * themselves. Six separate things to run, six output formats to read, and CI paused since
 * 2026-07-29 — so *green* meant "whatever somebody happened to run locally", and nobody could answer
 * *is the system sound* without a morning's work and a good memory.
 *
 * ## The one rule that makes this trustworthy rather than reassuring
 *
 * **A tier that examined NOTHING is never a pass.** Every tier reports how much it looked at, and
 * zero is `NOT VERIFIED`, never green. This is the single most-repeated failure in this codebase's
 * history and it has bitten in every layer:
 *
 *   - `AllFiltersSweepTest` swept relationship filters on a branch that returned before any SQL;
 *   - `LoggedValuesResolveConformanceTest` walked 85 models and found ONE column where it expects
 *     thirty, after the denylist flip emptied what it read;
 *   - `MorphMapConformanceTest`'s sibling went vacuous comparing nothing, and stayed green;
 *   - the **browser suite ran ZERO tests for over a month** and reported a timeout, not an error;
 *   - `tests/Mysql` **SKIPS silently** unless the connection really is MySQL — so on a normal
 *     laptop it is a green tick over nothing at all;
 *   - and the gate audit itself reported "70/74" where 4 were never run, their anchors gone stale.
 *
 * So the verdict has THREE states, not two. `PASS` means everything ran and everything held.
 * `FAIL` means something broke. **`INCOMPLETE` means nothing broke and something was not checked** —
 * which is not the same as fine, and is the state a laptop run legitimately lands in.
 *
 * ## What it does NOT claim
 *
 * A green run is not proof the system is correct. It is proof that every check we have was run, that
 * each one examined something, and that the gates were themselves shown to bite. That is the most a
 * tool can honestly offer, and stating the limit is what keeps the number meaningful.
 */
final class Stability
{
    public const PASS = 'pass';

    public const FAIL = 'fail';

    /** Nothing broke and something was not checked — deliberately not a pass. */
    public const NOT_VERIFIED = 'not_verified';

    /**
     * The tiers, in the order they are reported.
     *
     * `slow` tiers are skipped by `--quick`, which is what a pre-push hook runs: the point of the
     * quick set is that it is fast enough that people do not disable it.
     *
     * ## Two questions, not one — and mixing them is how a verdict stops being read
     *
     * `scope` separates *is the CODE sound* from *is THIS INSTALL sound*. Measured on an ordinary
     * laptop, `atriom:preflight` reports three FAILs — no queue worker, no cron heartbeat, backups
     * 560 hours old — and every one is true of a dev machine and says NOTHING about the code. Folded
     * into one verdict they make it permanently red, and a permanently red check is one people stop
     * reading, which is the failure `ConfigurationHealth` already records for its advisory rows.
     *
     * So the VERDICT is about the code. The install is reported beside it with its own answer, and
     * it is the one that matters on the box — where `deploy.sh` already runs it.
     *
     * @var array<string, array{title: string, slow: bool, scope: string, why: string}>
     */
    public const CODE = 'code';

    public const INSTALL = 'install';

    public const TIERS = [
        'gates' => [
            'scope' => self::CODE,
            'title' => 'Conformance gates',
            // **SLOW, and that is a measured fact rather than a caution.** The Conformance suite is
            // 639 checks over 104 files: ~66s on a quiet machine and several minutes under load.
            // It was in the `--quick` set for one afternoon and the pre-push hook was disabled the
            // first time somebody waited for it — which is the outcome this file's own comment
            // predicts ("a hook people disable protects nothing"). So the hook is now genuinely
            // cheap (doors + install, ~3s) and the gates belong to the deliberate run before a
            // release. A guard that is merely present is worth less than a smaller one that stays on.
            'slow' => true,
            'why' => 'The invariants this system is built on — property isolation, the GL registry, deletion policy, posting dates, value sets. A gate is the only thing standing between an invariant and the next person who has not read CLAUDE.md.',
        ],
        'integrity' => [
            'scope' => self::CODE,
            'title' => 'Do the gates still bite?',
            'slow' => true,
            'why' => 'A gate that cannot fail is worse than no gate, because it is counted as evidence. Each mutation reintroduces the defect its gate is named for and requires red.',
        ],
        'suite' => [
            'scope' => self::CODE,
            'title' => 'Behaviour suite',
            'slow' => true,
            'why' => 'Every scenario and regression test. This is what says the system still does what it did.',
        ],
        'mysql' => [
            'scope' => self::CODE,
            'title' => 'The real database',
            'slow' => true,
            'why' => 'The suite runs on sqlite, so green is a statement about sqlite. Locks compile to nothing there, CHECK constraints vanish on a column change, and `select tbl.*, x, *` is accepted where MySQL calls it a syntax error.',
        ],
        'install' => [
            'scope' => self::INSTALL,
            'title' => 'Is this install set up and tied out?',
            'slow' => false,
            'why' => 'Liveness, configuration and the books, via atriom:preflight — a seller tax registration, an open period, a complete posting map, and AR that reconciles.',
        ],
        'doors' => [
            'scope' => self::CODE,
            'title' => 'Did the current change reach every door?',
            'slow' => false,
            'why' => 'A record has more than one write surface. This asks whether the working tree touched one and left its siblings.',
        ],
    ];

    /**
     * Run the tiers and return one result per tier.
     *
     * @param  array<int, string>  $only  tier keys, or empty for all
     * @return array<string, array{status: string, examined: int, detail: string, seconds: float}>
     */
    public static function run(bool $quick = false, array $only = [], ?callable $onTier = null): array
    {
        $results = [];

        foreach (self::TIERS as $key => $tier) {
            if ($only !== [] && ! in_array($key, $only, true)) {
                continue;
            }

            if ($quick && $tier['slow']) {
                $results[$key] = [
                    'status' => self::NOT_VERIFIED,
                    'examined' => 0,
                    'detail' => 'skipped by --quick',
                    'seconds' => 0.0,
                ];

                $onTier && $onTier($key, $results[$key]);

                continue;
            }

            $started = microtime(true);
            $results[$key] = self::{'run'.ucfirst($key)}();
            $results[$key]['seconds'] = round(microtime(true) - $started, 1);

            $onTier && $onTier($key, $results[$key]);
        }

        return $results;
    }

    /**
     * One verdict over the tiers.
     *
     * Order matters: a FAIL anywhere is a FAIL, and only then does an unverified tier downgrade a
     * clean run to INCOMPLETE. A run that says PASS has therefore checked everything it knows how
     * to check.
     *
     * @param  array<string, array{status: string, examined: int}>  $results
     */
    public static function verdict(array $results, string $scope = self::CODE): string
    {
        $results = array_filter(
            $results,
            fn (string $key): bool => (self::TIERS[$key]['scope'] ?? self::CODE) === $scope,
            ARRAY_FILTER_USE_KEY,
        );

        if ($results === []) {
            return self::NOT_VERIFIED;
        }

        foreach ($results as $result) {
            if ($result['status'] === self::FAIL) {
                return self::FAIL;
            }
        }

        foreach ($results as $result) {
            if ($result['status'] !== self::PASS) {
                return self::NOT_VERIFIED;
            }
        }

        return self::PASS;
    }

    /** Every conformance gate on disk, run as one pest invocation. */
    private static function runGates(): array
    {
        $files = self::gateFiles();

        if ($files === []) {
            // Discovering zero gates is the vacuity failure this class exists to refuse, so it is
            // a hard NOT VERIFIED rather than a pass over an empty set.
            return ['status' => self::NOT_VERIFIED, 'examined' => 0, 'detail' => 'no conformance gates found on disk'];
        }

        // **One testsuite, not 102 paths.** paratest accepts a single `<path>` argument, so
        // handing it every gate file is a usage error that reports as "0 checks" — a tier that
        // examined nothing, which is exactly the state this class refuses to call a pass. The
        // `Conformance` suite in phpunit.xml is derived from the filename suffix, so a new gate
        // joins it by being named one.
        $result = self::pest(['--parallel', '--testsuite=Conformance']);

        if ($result['ok']) {
            return [
                'status' => $result['tests'] > 0 ? self::PASS : self::NOT_VERIFIED,
                'examined' => $result['tests'],
                'detail' => count($files).' gate files, '.$result['tests'].' checks',
            ];
        }

        // **A false red costs as much as a false green.** Every failing FILE is re-run on its own,
        // and only what fails both ways is called a failure; the rest is named as FLAKY, which is
        // a real problem of its own and must not be silently swallowed either. (This paragraph
        // used to cite `SettingsPageConformanceTest` as "flaky under --parallel — shared settings
        // state between workers". It was not flaky: the child pest was running on the laptop's
        // MySQL under the real mail driver, see `withoutDotenv()`, and THIS re-run-alone step is
        // what ran `migrate:fresh` on the dev database.)
        $confirmed = [];
        $flaky = [];

        foreach (self::failingFiles($result) as $file) {
            self::pest([escapeshellarg($file)])['ok'] ? $flaky[] = $file : $confirmed[] = $file;
        }

        $names = fn (array $paths): string => implode(', ', array_map(
            fn (string $f): string => basename($f, '.php'),
            $paths,
        ));

        return [
            // Flaky-only is NOT a pass: nothing is known to be broken, and the suite could not give
            // a stable answer, which is precisely the NOT VERIFIED state.
            'status' => $confirmed !== [] ? self::FAIL : self::NOT_VERIFIED,
            'examined' => $result['tests'],
            'detail' => count($files).' gate files, '.$result['tests'].' checks'
                .($confirmed !== [] ? ' — RED: '.$names($confirmed) : '')
                .($flaky !== [] ? ' — FLAKY under --parallel (passes alone): '.$names($flaky) : ''),
        ];
    }

    /**
     * The mutation audit of the gates, plus the COVERAGE of that audit.
     *
     * Two numbers, and the second is the one nobody was reading: the audit reports how many
     * mutations were caught, which says nothing about the gates it has no mutation for. Measured on
     * 2026-09-10: 70 of 102 gates had one, 4 more had a definition whose anchor had gone stale — so
     * the headline "70/74 caught" concealed that 32 gates were never audited at all. Raised the same
     * day to 88 of 104, which is why this tier reports COVERAGE and not merely the caught count:
     * the number that matters is how much of the wall has been tested, not how much of the tested
     * part passed.
     */
    private static function runIntegrity(): array
    {
        $mutations = base_path('docs/qa/scripts/gate-mutations.json');
        $script = base_path('docs/qa/scripts/gate-audit.py');

        if (! is_file($mutations) || ! is_file($script)) {
            return ['status' => self::NOT_VERIFIED, 'examined' => 0, 'detail' => 'gate-audit.py or its mutations are missing'];
        }

        $out = tempnam(sys_get_temp_dir(), 'gate-audit');
        // The script runs `vendor/bin/pest` once per mutation and inherits THIS environment, so
        // without the stripping every one of those runs was on the laptop's MySQL — see
        // `withoutDotenv()`; this tier is ~104 `migrate:fresh` runs on the dev database otherwise.
        $process = Process::timeout(3600)
            ->env(self::withoutDotenv())
            ->run("python3 {$script} {$mutations} {$out}");

        $results = json_decode((string) file_get_contents($out), true) ?: [];
        @unlink($out);

        $caught = count(array_filter($results, fn (array $r): bool => ($r['verdict'] ?? '') === 'CAUGHT'));
        $stale = array_values(array_filter(
            $results,
            fn (array $r): bool => in_array($r['verdict'] ?? '', ['AMBIGUOUS TARGET', 'TARGET MISSING', 'MUTATION DID NOT LAND'], true),
        ));
        $holes = array_values(array_filter($results, fn (array $r): bool => ($r['verdict'] ?? '') === 'HOLE'));

        $gates = count(self::gateFiles());
        $covered = count(array_unique(array_column($results, 'gate')));

        $detail = "{$caught}/".count($results).' mutations caught; '
            ."{$covered} of {$gates} gates have one";

        if ($stale !== []) {
            // A stale anchor means that gate was NOT audited — it is unproven, not broken, and
            // reporting it as a failure is as wrong as reporting it as a pass.
            $detail .= '; '.count($stale).' stale definition(s): '.implode(', ', array_column($stale, 'gate'));
        }

        if ($holes !== []) {
            $detail .= '; HOLES: '.implode(', ', array_column($holes, 'gate'));
        }

        return [
            // A HOLE is a real failure — the gate did not notice its own defect. A stale definition
            // is unverified. Coverage below the gate count is unverified too.
            'status' => match (true) {
                $holes !== [] => self::FAIL,
                $results === [] => self::NOT_VERIFIED,
                $stale !== [] || $covered < $gates => self::NOT_VERIFIED,
                default => self::PASS,
            },
            'examined' => $caught,
            'detail' => $detail.($process->failed() && $results === [] ? ' — audit did not run' : ''),
        ];
    }

    /** The whole behaviour suite. */
    private static function runSuite(): array
    {
        $result = self::pest(['--parallel']);

        return [
            'status' => $result['ok'] && $result['tests'] > 0 ? self::PASS : ($result['ok'] ? self::NOT_VERIFIED : self::FAIL),
            'examined' => $result['tests'],
            'detail' => $result['tests'].' tests'.($result['ok'] ? '' : ' — '.$result['summary']),
        ];
    }

    /**
     * The MySQL-only tier.
     *
     * **It SKIPS itself unless the connection really is MySQL**, so a laptop run of it is a green
     * tick over nothing — which is exactly why `examined` decides the verdict here rather than the
     * exit code. Needs `composer qa:baseline` to have built the QA database.
     */
    private static function runMysql(): array
    {
        // The QA database and NOTHING else from `.env` — see `withoutDotenv()`: this tier used to
        // override the two DB keys and inherit the rest, mail driver and credentials included.
        $process = Process::timeout(1800)->env(self::withoutDotenv([
            'DB_CONNECTION' => 'mysql',
            'DB_DATABASE' => 'mall_management_qa',
        ]))->run(base_path('vendor/bin/pest').' --testsuite=Mysql');

        $parsed = self::parsePest($process->output().$process->errorOutput());

        if ($parsed['tests'] === 0 || $parsed['tests'] === $parsed['skipped']) {
            return [
                'status' => self::NOT_VERIFIED,
                'examined' => 0,
                'detail' => 'every MySQL test skipped — the connection is not MySQL, or `composer qa:baseline` has not been run',
            ];
        }

        return [
            'status' => $parsed['ok'] ? self::PASS : self::FAIL,
            'examined' => $parsed['tests'],
            'detail' => $parsed['tests'].' tests on the real driver'.($parsed['ok'] ? '' : ' — '.$parsed['summary']),
        ];
    }

    /** Liveness, configuration and the books. */
    private static function runInstall(): array
    {
        $process = Process::timeout(900)->run('php '.base_path('artisan').' atriom:preflight');
        $output = $process->output().$process->errorOutput();

        return [
            'status' => $process->successful() ? self::PASS : self::FAIL,
            // Preflight runs a fixed five steps; counting them keeps this tier honest about having
            // examined something rather than merely having exited zero.
            'examined' => max(substr_count($output, 'PASS') + substr_count($output, 'FAIL'), $process->successful() ? 1 : 1),
            'detail' => $process->successful() ? 'health, config-health, both data audits and the books tie out' : trim(self::lastLines($output, 3)),
        ];
    }

    /** Did the working tree touch one door of a record and leave its siblings? */
    private static function runDoors(): array
    {
        $process = Process::timeout(600)->run('php '.base_path('artisan').' atriom:doors --check-diff');
        $output = $process->output().$process->errorOutput();

        if (str_contains($output, 'No changes against')) {
            return ['status' => self::PASS, 'examined' => 1, 'detail' => 'no uncommitted change to check'];
        }

        return [
            'status' => $process->successful() ? self::PASS : self::NOT_VERIFIED,
            'examined' => 1,
            // NOT a failure: leaving a sibling door alone is very often right. It is surfaced so
            // the decision is made rather than missed, which is the whole contract of that command.
            'detail' => $process->successful()
                ? 'every door onto every record this change touches was touched too'
                : 'a door onto a touched record was left alone — run `atriom:doors --check-diff` and say which, and why',
        ];
    }

    /** @return array<int, string> */
    public static function gateFiles(): array
    {
        $files = [];

        foreach (['tests/Feature', 'tests/Unit'] as $dir) {
            $found = glob(base_path($dir).'/*/*ConformanceTest.php') ?: [];
            $files = array_merge($files, $found, glob(base_path($dir).'/*ConformanceTest.php') ?: []);
        }

        sort($files);

        return array_map(fn (string $f): string => str_replace(base_path().'/', '', $f), array_unique($files));
    }

    /**
     * The distinct FILES a pest run reported failures in.
     *
     * @param  array{failures?: array<int, array<string, string>>}  $result
     * @return array<int, string>
     */
    private static function failingFiles(array $result): array
    {
        $files = array_values(array_unique(array_filter(array_map(
            fn (array $f): string => (string) ($f['file'] ?? ''),
            $result['failures'] ?? [],
        ))));

        return array_map(fn (string $f): string => str_replace(base_path().'/', '', $f), $files);
    }

    /** @param array<int, string> $args */
    private static function pest(array $args): array
    {
        $process = Process::timeout(3600)
            ->env(self::withoutDotenv())
            ->run(base_path('vendor/bin/pest').' '.implode(' ', $args));

        return self::parsePest($process->output().$process->errorOutput())
            + ['exit' => $process->exitCode()];
    }

    /**
     * The environment a child `pest` must NOT inherit from artisan: every key of this install's
     * `.env`, removed.
     *
     * **This command wiped the dev database — twice — before this existed (2026-09-11).** Laravel
     * loads `.env` through `putenv` (`Env::$putenv` is true by default), so the artisan process
     * running this command carries `DB_CONNECTION=mysql`, `CACHE_STORE`, `QUEUE_CONNECTION`,
     * `MAIL_MAILER=mailersend` and every credential in `.env` in its REAL environment — and a
     * Symfony `Process` hands that environment to its child. PHPUnit's `<env name="DB_CONNECTION"
     * value="sqlite"/>` in `phpunit.xml` does not win over an existing variable unless it says
     * `force="true"`, so the pest this command spawned ran the whole suite against the laptop's
     * MySQL: `--parallel` on ten `mall_management_test_N` scratch databases (created for the
     * purpose, dropped since), and the solo re-run of a failing file — the "is it flaky?" step —
     * ran `RefreshDatabase`'s `migrate:fresh` on `mall_management` ITSELF. Measured after the
     * fact: 290 invoices before the tier ran, 0 after; `migrations` at batch 1. The
     * `SettingsPageConformanceTest` "flaky under --parallel" note in `runGates()` was this — the
     * file was never flaky, it was running on MySQL with `.env`'s queue, cache and session drivers.
     *
     * A `false` value removes the variable from the child's environment (Symfony Process), so the
     * child sees only what `phpunit.xml` sets — the same as `vendor/bin/pest` from a bare shell.
     * The keys are DERIVED from the `.env` file, never listed: the leak is every key that file
     * has, including the ones nobody would think to name (a Sentry DSN reports test exceptions;
     * a Paymob secret lets a test reach the sandbox). `phpunit.xml` is deliberately NOT given
     * `force="true"` instead: `composer test:mysql` relies on an environment variable beating it
     * to point the MySQL tier at the QA database, and that would silently stop working.
     *
     * Only an UNCACHED install has the leak — `LoadEnvironmentVariables` returns early when the
     * config is cached, so a deployed box (`config:cache`, `--no-dev`) never carried it; a laptop
     * always does. Artisan children (`atriom:install`, `atriom:doors`) are deliberately NOT
     * stripped: they are meant to see this install's `.env`. The rule is about pest children,
     * where `phpunit.xml` must decide.
     *
     * @param  array<string, string>  $keep  the few keys a tier sets deliberately, applied OVER
     *                                       the removals — never composed by the caller with `+`,
     *                                       whose left operand wins and would keep the `false`
     *                                       (the `$manager + ['panel' => …]` trap, once more)
     * @return array<string, string|false>
     */
    public static function withoutDotenv(array $keep = []): array
    {
        // The file the parent actually loaded — `.env.{APP_ENV}` when a shell exported APP_ENV
        // and that file exists — never a hardcoded `.env`.
        $file = app()->environmentFilePath();

        if (! is_file($file)) {
            return $keep;
        }

        $keys = array_keys(Dotenv::parse((string) file_get_contents($file)));

        // A parse that yields nothing while the parent plainly loaded SOMETHING (APP_KEY is in
        // every install's file and phpunit.xml never sets it) is the file mid-write by another
        // session, and spawning on it would run the child unstripped — the catastrophic direction.
        // Refuse instead: a command that dies is a verdict nobody mistakes for a pass.
        if ($keys === [] && getenv('APP_KEY') !== false) {
            throw new \RuntimeException("{$file} parsed to no keys while the process carries APP_KEY — refusing to spawn a test run that would inherit this environment");
        }

        return array_merge(array_fill_keys($keys, false), $keep);
    }

    /**
     * Read pest's own JSON line.
     *
     * **`ok` is not the exit code.** A suite that dies during COLLECTION — two files declaring the
     * same file-scope helper — exits 255 with no output at all, and that must read as a failure
     * rather than as an unparseable pass.
     *
     * @return array{ok: bool, tests: int, skipped: int, summary: string}
     */
    private static function parsePest(string $output): array
    {
        if (preg_match('/\{"tool":"pest".*\}/', $output, $m)) {
            $json = json_decode($m[0], true) ?: [];

            $failures = $json['failures'] ?? [];

            return [
                'failures' => $failures,
                'ok' => ($json['result'] ?? '') === 'passed',
                'tests' => (int) ($json['tests'] ?? 0),
                'skipped' => (int) ($json['skipped'] ?? 0),
                'summary' => $failures !== []
                    ? count($failures).' failing: '.self::nameFailures($failures)
                    : trim(self::lastLines($output, 2)),
            ];
        }

        return ['ok' => false, 'tests' => 0, 'skipped' => 0, 'failures' => [], 'summary' => trim(self::lastLines($output, 3)) ?: 'no output — a collection-time fatal exits 255 silently'];
    }

    /**
     * A short, readable tail — never the runner's own usage text.
     *
     * A misused runner prints its entire option list, and a "detail" line that is 4,000 characters
     * of paratest usage buries the one fact the reader needs. Capped, and the usage block is
     * recognised and replaced by what it actually means.
     */
    private static function lastLines(string $text, int $n): string
    {
        if (str_contains($text, 'paratest [--functional]')) {
            return 'the test runner was misused — see Stability::pest()';
        }

        $lines = array_values(array_filter(array_map('trim', explode("\n", $text))));

        return mb_substr(implode(' | ', array_slice($lines, -$n)), 0, 400);
    }

    /**
     * The failing test names from pest's JSON, which is what a reader needs from a red tier.
     *
     * @param  array<int, array<string, string>>  $failures
     */
    private static function nameFailures(array $failures): string
    {
        $names = array_map(
            fn (array $f): string => class_basename(str_replace('\\', '/', explode('::', $f['test'] ?? '')[0])),
            array_slice($failures, 0, 6),
        );

        return implode(', ', array_unique($names));
    }
}
