<?php

use App\Support\Stability;
use Dotenv\Dotenv;
use Illuminate\Support\Facades\Process;

/**
 * Regression — `atriom:stability` must not hand the laptop's `.env` to the pest it spawns.
 *
 * Found on 2026-09-11 by an empty dev database. Laravel loads `.env` through `putenv`, so the
 * artisan process running the command carries `DB_CONNECTION=mysql`, `QUEUE_CONNECTION=database`,
 * `MAIL_MAILER=mailersend` and every credential in `.env` in its REAL environment; a Symfony
 * `Process` hands that to its child; and PHPUnit's `<env name="DB_CONNECTION" value="sqlite"/>`
 * does not win over an existing variable without `force="true"`. So the spawned pest ran the suite
 * against MySQL — ten `mall_management_test_N` scratch databases under `--parallel`, and on the solo
 * re-run of a "flaky" file, `migrate:fresh` on `mall_management` itself. 290 invoices before the
 * tier ran; 0 after. The "flaky SettingsPageConformanceTest" the command documented was that file
 * running on MySQL. And `gate-audit.py` spawns pest once per mutation from the same environment.
 *
 * `Stability::withoutDotenv()` removes every `.env` key from the child's environment (a `false`
 * value is a removal in Symfony Process — `null` and `''` are NOT, both reach the child as an
 * existing empty variable that phpunit's un-forced `<env>` still loses to), so the child sees only
 * what `phpunit.xml` sets, exactly as `vendor/bin/pest` from a bare shell does.
 *
 * Proved through a REAL child that boots Laravel the way phpunit would — `<env>` applied through
 * putenv first, then the framework — and reports which database it would use. `php -r` reporting
 * `getenv()` would prove the environment and not the consequence.
 */
function stabilityChildProbe(): string
{
    // phpunit's `PhpHandler` applies `<env>` with `if (getenv($name) === false) putenv(...)`, so
    // the child applies the same four the way phpunit does and then boots.
    return 'foreach (["APP_ENV=testing","DB_CONNECTION=sqlite","DB_DATABASE=:memory:","MAIL_MAILER=array"] as $e) {'
        .' [$k, $v] = explode("=", $e, 2); if (getenv($k) === false) { putenv($e); $_ENV[$k] = $v; } }'
        .' chdir('.var_export(base_path(), true).'); require "vendor/autoload.php"; $app = require "bootstrap/app.php";'
        .' $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();'
        .' echo "db=", config("database.default"), " mail=", config("mail.default"), " env=", app()->environment(),'
        .' " app_key=", var_export((bool) config("app.key"), true);';
}

/**
 * The environment artisan carries: every `.env` value, as `putenv` left it. This test process is
 * pest, whose own environment already holds phpunit's sqlite values, so the artisan parent has to
 * be SIMULATED explicitly or nothing here can leak and the strip proves nothing.
 *
 * @return array<string, string>
 */
function stabilityArtisanEnvironment(): array
{
    $values = Dotenv::parse((string) file_get_contents(app()->environmentFilePath()));

    return array_map(fn ($v): string => (string) $v, array_filter($values, fn ($v): bool => $v !== null));
}

it('spawns a child that boots on sqlite and the array mailer, whatever this install\'s .env says', function () {
    if (! is_file(app()->environmentFilePath())) {
        $this->markTestSkipped('no .env on this checkout — nothing to leak');
    }

    $artisan = stabilityArtisanEnvironment();

    // The premise: this install's .env really does name a database the suite must not touch.
    expect($artisan['DB_CONNECTION'] ?? null)->not->toBe('sqlite', 'this .env already says sqlite — the leak has nothing to show here');

    // What the command does: the child gets artisan's environment WITH the strip applied over it.
    $result = Process::env(array_merge($artisan, Stability::withoutDotenv()))
        ->run(['php', '-r', stabilityChildProbe()]);

    expect($result->successful())->toBeTrue($result->errorOutput())
        ->and(trim($result->output()))->toBe('db=sqlite mail=array env=testing app_key=true');
});

it('still leaks without the strip — the control that proves the probe can see the difference', function () {
    if (! is_file(app()->environmentFilePath())) {
        $this->markTestSkipped('no .env on this checkout');
    }

    $artisan = stabilityArtisanEnvironment();
    $leaked = Process::env($artisan)->run(['php', '-r', stabilityChildProbe()]);

    expect($leaked->successful())->toBeTrue($leaked->errorOutput())
        ->and(trim($leaked->output()))->toStartWith('db='.$artisan['DB_CONNECTION']);
});

it('strips nothing that is not in .env, and lets a tier keep the keys it sets on purpose', function () {
    $env = Stability::withoutDotenv();

    expect($env)->not->toHaveKey('PATH')
        ->and(array_unique(array_values($env)))->toBe([false]);

    // The first cut composed the MySQL tier's keys as `withoutDotenv() + [...]`, and PHP's array
    // union keeps the LEFT value for a duplicate key — so that tier would have run on sqlite with
    // every one of its tests skipping, reported as a tier that examined nothing.
    $kept = Stability::withoutDotenv(['DB_CONNECTION' => 'mysql', 'DB_DATABASE' => 'mall_management_qa']);

    expect($kept['DB_CONNECTION'])->toBe('mysql')
        ->and($kept['DB_DATABASE'])->toBe('mall_management_qa')
        ->and($kept['MAIL_MAILER'] ?? false)->toBeFalse();
});

it('carries the strip on every process in Stability that runs pest, directly or through a script', function () {
    // The helper being right proves nothing about the three call sites; the integrity tier was
    // the one the first cut missed, and a fourth spawn would be missed the same way. Read the
    // source: every `Process::…->run(` whose command names pest or gate-audit must chain
    // `->env(self::withoutDotenv(`, and the artisan children must NOT (they are meant to see
    // this install's .env).
    $source = sourceWithoutComments(app_path('Support/Stability.php'));

    preg_match_all('/Process::[^;]*?->run\(([^;]*?)\);/s', $source, $m, PREG_SET_ORDER);

    expect($m)->not->toBeEmpty('no Process::…->run( found — the sweep is not reading the file');

    $unstripped = [];
    $overStripped = [];

    foreach ($m as [$call, $command]) {
        // The python audit's command names only `$script`; the script's PATH is bound a few
        // lines above the call, so read the whole call for it.
        $runsTests = str_contains($command, 'vendor/bin/pest') || str_contains($call, 'gate-audit') || str_contains($command, '$script');
        $stripped = str_contains($call, '->env(self::withoutDotenv(');

        if ($runsTests && ! $stripped) {
            $unstripped[] = trim($command);
        }
        if (! $runsTests && $stripped) {
            $overStripped[] = trim($command);
        }
    }

    expect($unstripped)->toBe([], "these spawn a test run with this install's .env in its environment:\n  ".implode("\n  ", $unstripped))
        ->and($overStripped)->toBe([], "these artisan children were stripped of the .env they need:\n  ".implode("\n  ", $overStripped));
});
