<?php

namespace App\Support;

use App\Models\SystemSetting;
use App\Models\User;
use App\Models\Vendor;
use App\Services\Accounting\AccountResolver;
use Illuminate\Queue\Failed\FailedJobProviderInterface;
use Illuminate\Queue\Failed\NullFailedJobProvider;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * What "up" actually means for this application.
 *
 * The stock `/up` returns 200 as long as PHP can render a route — it says 200
 * with the database down, the queue stalled, the scheduler dead and the last
 * backup a month old. For an ERP that bills tenants and files tax returns, that
 * is not a health check; it is a liveness check for the web server.
 *
 * Each check answers a question someone would otherwise only discover from a
 * user complaint:
 *
 *   database   — can we read? (everything depends on this)
 *   cache      — DB-backed here, so this also catches a half-broken DB
 *   queue      — are jobs piling up or failing? ETA submissions and GL sync ride the queue
 *   scheduler  — did cron run? 25 scheduled entries include billing, GL sync and BACKUPS
 *   backups    — is there a recent archive? the safeguard nobody checks until they need it
 *   storage    — can we write? uploads and PDFs die silently otherwise
 *
 * The scheduler check deliberately reads a FILE, never the database or cache.
 * Both of those are database-backed in this app, so a DB outage would otherwise
 * report "scheduler dead" as well and bury the real fault under a second,
 * wrong alarm.
 */
class Health
{
    /** Where the scheduler stamps that it ran. Not in storage/app — that is backed up. */
    public static function heartbeatPath(): string
    {
        return storage_path('framework/scheduler-heartbeat');
    }

    /** Called every minute by the scheduler. Cheap, and independent of DB/cache. */
    public static function stampHeartbeat(): void
    {
        File::ensureDirectoryExists(dirname(self::heartbeatPath()));
        File::put(self::heartbeatPath(), (string) now()->getTimestamp());
    }

    /**
     * Run every check.
     *
     * @return array{status: string, checks: array<string, array{ok: bool, detail: string}>}
     */
    public static function run(): array
    {
        $checks = [
            'database' => self::checkDatabase(),
            'cache' => self::checkCache(),
            'queue' => self::checkQueue(),
            'scheduler' => self::checkScheduler(),
            'backups' => self::checkBackups(),
            'backup_capability' => self::checkBackupCapability(),
            'storage' => self::checkStorage(),
            'two_factor' => self::checkTwoFactor(),
            'accounting' => self::checkAccounting(),
            'withholding_tax' => self::checkWithholdingTax(),
            'books_tie_out' => self::checkBooksTieOut(),
            'admin_access' => self::checkAdminAccess(),
            'demo_accounts' => self::checkDemoAccounts(),
            'demo_payments' => self::checkDemoPayments(),
            'mobile_reset_url' => self::checkMobileResetUrl(),
            'runtime_drivers' => self::checkRuntimeDrivers(),
            'translations' => self::checkTranslations(),
        ];

        $ok = collect($checks)->every(fn (array $c): bool => $c['ok']);

        return [
            'status' => $ok ? 'ok' : 'degraded',
            'checks' => $checks,
        ];
    }

    /**
     * CAN a backup be taken, and would it survive the machine? Production only.
     *
     * The existing `backups` check looks at the newest archive's age, so it only speaks 24 hours
     * after things break, and only if an archive was ever written. These two conditions are
     * knowable immediately:
     *
     * 1. **No dump binary.** `backup:run` shells out to `mysqldump`; without it the command exits
     *    127 and writes nothing. Found live on this project 2026-07-30 — the nightly job had been
     *    failing since the schedule was added, and the only signal would have been the age check
     *    a day later, going to a mail channel that was not configured.
     *
     * 2. **Every destination is a local disk.** A copy that lives on the same machine as the
     *    database dies with the machine. That is the failure a backup exists to survive, so a
     *    local-only configuration is not a backup — it is a convenience copy.
     *
     * Fails only outside local/testing: developers have neither an off-site disk nor a reason to
     * install the client, and a check that cries wolf locally gets ignored in production.
     *
     * @return array{ok: bool, detail: string}
     */
    private static function checkBackupCapability(): array
    {
        $connection = config('database.default');

        return self::backupCapability(
            driver: config("database.connections.{$connection}.driver"),
            dumpBinaryPath: (string) config("database.connections.{$connection}.dump.dump_binary_path", ''),
            disks: (array) config('backup.backup.destination.disks', []),
            environment: Deployment::name(),
        );
    }

    /**
     * The decision itself, taking its inputs explicitly.
     *
     * Separated from the config reading so it can be exercised for a mysql deployment without
     * repointing `database.default` — doing that mid-test tears down the suite's own connection,
     * which is how the first version of these tests failed for reasons unrelated to the check.
     *
     * @param  array<int, string>  $disks
     * @return array{ok: bool, detail: string}
     */
    public static function backupCapability(?string $driver, string $dumpBinaryPath, array $disks, string $environment): array
    {
        $problems = [];

        if (($binary = self::missingDumpBinary($driver, $dumpBinaryPath)) !== null) {
            $problems[] = "no `{$binary}` on PATH — backup:run will exit 127 and write nothing";
        }

        if (self::backupDisksAreAllLocal($disks)) {
            $problems[] = 'every BACKUP_DISKS destination is a local disk — the copy dies with the machine';
        }

        if (in_array($environment, ['local', 'testing'], true)) {
            return ['ok' => true, 'detail' => $problems === []
                ? 'able to back up'
                : 'local/testing — not enforced ('.implode('; ', $problems).')'];
        }

        return $problems === []
            ? ['ok' => true, 'detail' => 'dump binary present, at least one off-box destination']
            : ['ok' => false, 'detail' => implode('; ', $problems)];
    }

    /** The dump binary this connection needs, if it is NOT reachable. Null when all is well. */
    private static function missingDumpBinary(?string $driver, string $dumpBinaryPath): ?string
    {
        $binary = match ($driver) {
            'mysql', 'mariadb' => 'mysqldump',
            'pgsql' => 'pg_dump',
            default => null,       // sqlite dumps in-process; nothing to find
        };

        if ($binary === null) {
            return null;
        }

        // spatie honours an explicit directory; otherwise the binary must be on PATH.
        if ($dumpBinaryPath !== '') {
            return is_executable(rtrim($dumpBinaryPath, '/').'/'.$binary) ? null : $binary;
        }

        $found = @shell_exec('command -v '.escapeshellarg($binary).' 2>/dev/null');

        return is_string($found) && trim($found) !== '' ? null : $binary;
    }

    /** True when no configured backup destination leaves the machine. */
    private static function backupDisksAreAllLocal(array $disks): bool
    {
        if ($disks === []) {
            return true;
        }

        foreach ($disks as $disk) {
            if (config("filesystems.disks.{$disk}.driver") !== 'local') {
                return false;
            }
        }

        return true;
    }

    /**
     * Two-factor enforcement, on production only.
     *
     * Enforcement is opt-in (config/security.php, operator's call 2026-07-30), which means a
     * production deploy where nobody set SECURITY_FORCE_2FA_ROLES runs with no second factor on
     * the accounts that move money. That is precisely how enforcement sat broken here for months —
     * "configured" somewhere nobody looked. So the off state is not allowed to be quiet: this
     * fails the health check, naming the roles that should be covered.
     *
     * Local/testing are exempt: the demo logins and the Playwright suite need to stay unforced.
     *
     * @return array{ok: bool, detail: string}
     */
    private static function checkTwoFactor(): array
    {
        $forced = (array) config('security.force_2fa_roles', []);

        if (! Deployment::isDeployed()) {
            return ['ok' => true, 'detail' => $forced === []
                ? 'not enforced (local/testing — expected)'
                : 'enforced for: '.implode(', ', $forced)];
        }

        if ($forced === []) {
            return [
                'ok' => false,
                'detail' => 'NOT ENFORCED on '.Deployment::name().' — set SECURITY_FORCE_2FA_ROLES="'
                    .implode(',', SecurityDefaults::FORCE_2FA_ROLES).'"',
            ];
        }

        // Enforced, but is it covering the accounts that matter?
        $missing = array_values(array_diff(SecurityDefaults::FORCE_2FA_ROLES, $forced));

        return $missing === []
            ? ['ok' => true, 'detail' => 'enforced for '.count($forced).' role(s)']
            : ['ok' => true, 'detail' => 'enforced, but these money-touching roles are not covered: '.implode(', ', $missing)];
    }

    /**
     * CAN this install post to the books at all? Production only.
     *
     * **The failure it exists for, reproduced on an empty database:** `migrate` alone leaves
     * `ledger_accounts` and `account_mappings` empty — the chart is a SEEDER, not a migration. An
     * operator can then create a property, a lease and a tenant, run the monthly billing, and get a
     * perfectly correct 30,000 EGP invoice… while `accounting:sync-ledger` refuses every posting
     * with "No account mapping for role 'accounts_receivable'" and the general ledger stays at zero
     * entries. Billing looks healthy; the books are empty. Nothing in the app says so: the realtime
     * hook is best-effort by design, and the sweep's non-zero exit goes to a cron log nobody reads.
     *
     * So the check resolves EVERY role in `PostingRoles` through the real `AccountResolver` — the
     * same code path the journalizers use — and reports the ones that would throw. That catches the
     * unseeded install, a partially-seeded one, a mapping pointing at a deleted account, and a
     * mapping pointing at a non-postable header account, without a second opinion about what
     * "mapped" means.
     *
     * Fails only outside local/testing: a developer between `migrate` and `db:seed` is not broken,
     * and a check that cries wolf locally gets ignored in production.
     *
     * @return array{ok: bool, detail: string}
     */
    /**
     * Do the books still agree with themselves?
     *
     * `accounting:sync-ledger` computes the GL↔AR and GL↔AP tie-out on every run and, since
     * 2026-08-12, persists it. This surfaces that stamp — which is the point: the delta used to be
     * printed with `warn()` and nothing else, so on a cron it went to `/dev/null`, and the sibling
     * alert only fires for documents that THREW. A ledger drifting with zero failed documents was
     * invisible to every channel at once.
     *
     * Reads the stamp rather than recomputing. `glTieOut()` sums the whole ledger against the whole
     * sub-ledger, which is not something to run inside a health endpoint an uptime monitor hits
     * every minute — and a health check that is expensive is a health check somebody turns off.
     *
     * A MISSING stamp is not a failure: it means the sweep has not run yet, which `scheduler`
     * already reports. Only a recorded, non-zero delta is.
     *
     * @return array{ok: bool, detail: string}
     */
    private static function checkBooksTieOut(): array
    {
        $parts = [];

        // A document that could not post is the other way the books stop agreeing, and it had the
        // same channel gap: `recordAndAlertFailures()` alerts on a CHANGE in the count, so a failure
        // that persists at the same number alerts once and then lives only on the report pages that
        // happen to render `PostsToLedger`'s banner. A standing count belongs on a surface something
        // polls.
        $failures = (int) (SystemSetting::get('ledger_last_sync_failures') ?? 0);
        if ($failures > 0) {
            $parts[] = $failures.' document(s) could not post — see the ledger sync log';
        }

        $checkedAt = SystemSetting::get('ledger_tie_out_checked_at');

        if (blank($checkedAt)) {
            // A MISSING stamp is not drift — it means the sweep has not run, which the `scheduler`
            // check already reports. Failing twice for one cause teaches the operator to ignore one
            // of the two alarms, and it will be this one.
            return $parts === []
                ? ['ok' => true, 'detail' => 'not computed yet — the ledger sweep has not run']
                : ['ok' => false, 'detail' => implode('; ', $parts)];
        }

        $ar = round((float) SystemSetting::get('ledger_tie_out_ar_delta'), 2);
        $ap = round((float) SystemSetting::get('ledger_tie_out_ap_delta'), 2);

        if (abs($ar) >= 0.01) {
            $parts[] = 'AR off by '.number_format($ar, 2);
        }
        if (abs($ap) >= 0.01) {
            $parts[] = 'AP off by '.number_format($ap, 2);
        }

        if ($parts === []) {
            return ['ok' => true, 'detail' => 'GL ties to AR and AP'];
        }

        return ['ok' => false, 'detail' => implode('; ', $parts).' — run billing:reconcile'];
    }

    private static function checkAccounting(): array
    {
        $verdict = self::accountingReadiness();

        if (! Deployment::isDeployed()) {
            return ['ok' => true, 'detail' => $verdict['ok']
                ? $verdict['detail']
                : 'local/testing — not enforced ('.$verdict['detail'].')'];
        }

        return ['ok' => $verdict['ok'], 'detail' => $verdict['detail']];
    }

    /**
     * Do the admin translations actually MERGE?
     *
     * `lang/{en,ar}/admin.php` builds itself from the per-domain partials in `admin/` and throws a
     * `LogicException` when two of them declare the same top-level key — deliberately, because the
     * alternative is load order silently deciding which one wins. Its own comment notes that this
     * runtime guard is the ONLY cross-partial check there is.
     *
     * The consequence is what makes it a health row rather than a lint: `__('admin.*')` is on every
     * page, so a bad merge is not a broken screen, it is **every** screen. Verified by injecting a
     * duplicate key — the merge throws and every other health row still reported OK, so nothing
     * anywhere said the application was about to 500 on load.
     *
     * Checked per LOCALE, because a partial added to `en/` and not `ar/` fails only in Arabic.
     */
    private static function checkTranslations(): array
    {
        $broken = [];

        foreach (['en', 'ar'] as $locale) {
            $path = lang_path($locale.'/admin.php');

            if (! File::exists($path)) {
                $broken[] = "{$locale}: admin.php is missing";

                continue;
            }

            try {
                $merged = require $path;

                if (! is_array($merged) || $merged === []) {
                    $broken[] = "{$locale}: merged to nothing";
                }
            } catch (Throwable $e) {
                // The message already names the partial and the clashing key.
                $broken[] = $locale.': '.$e->getMessage();
            }
        }

        return $broken === []
            ? ['ok' => true, 'detail' => 'both locales merge']
            : ['ok' => false, 'detail' => implode(' · ', $broken)];
    }

    /**
     * Withholding tax switched ON but with no rate that can ever resolve.
     *
     * `WithholdingTax::taxCodeFor()` reads the vendor's own code, then falls back to
     * `TaxSettings::wht_default_tax_code` — which ships EMPTY, and an empty code resolves to 0%. So
     * `wht_enabled = true` on its own withholds nothing at all: measured, a 114,000 bill withheld
     * 0.00 with the switch on, and 3,000.00 once a default code was set (pre-staging QA, C-01).
     *
     * Fail-safe by design — the alternative is inventing a statutory rate — but silent, and an
     * operator who turns withholding on and sees nothing happen has no way to tell whether the
     * feature is broken or the bills simply are not subject to it. This says which.
     *
     * A WARNING rather than a failure: a portfolio where every vendor carries its own code, or is
     * exempt, is correctly configured with no default at all.
     */
    private static function checkWithholdingTax(): array
    {
        if (! WithholdingTax::enabled()) {
            return ['ok' => true, 'detail' => 'not enabled'];
        }

        if (WithholdingTax::defaultTaxCode() !== '') {
            return ['ok' => true, 'detail' => 'enabled · default code '.WithholdingTax::defaultTaxCode()];
        }

        // No default — fine ONLY if the vendors carry their own codes.
        $withCode = Vendor::query()
            ->whereNotNull('withholding_tax_code')
            ->where('withholding_tax_code', '!=', '')
            ->count();

        if ($withCode > 0) {
            return ['ok' => true, 'detail' => "enabled · no default code, {$withCode} vendor(s) carry their own"];
        }

        return [
            'ok' => false,
            'detail' => 'enabled but NOTHING will be withheld — no default withholding code (Settings → Tax) and no vendor carries one',
        ];
    }

    /**
     * The verdict itself, WITHOUT the environment gate — "can this database post to the books?"
     *
     * Separated for the same reason as {@see backupCapability()}: the gate is a reporting policy
     * (don't cry wolf on a laptop), not part of the question. `atriom:install` needs the raw answer,
     * because an installer that reports "fine" on a developer machine it has just failed to set up
     * is the exact failure this whole line of work exists to remove.
     *
     * @return array{ok: bool, detail: string, broken: array<int, string>}
     */
    public static function accountingReadiness(): array
    {
        try {
            $resolver = app(AccountResolver::class);
            $broken = [];

            foreach (PostingRoles::keys() as $role) {
                try {
                    $resolver->account($role);
                } catch (Throwable $e) {
                    $broken[] = $role;
                }
            }
        } catch (Throwable $e) {
            return ['ok' => false, 'detail' => 'unreadable: '.$e->getMessage(), 'broken' => []];
        }

        if ($broken === []) {
            return ['ok' => true, 'detail' => count(PostingRoles::keys()).' posting roles mapped', 'broken' => []];
        }

        // Naming a few is enough to recognise the state; the full list is what `atriom:health`
        // would print forever otherwise.
        $sample = implode(', ', array_slice($broken, 0, 5)).(count($broken) > 5 ? ', …' : '');

        return [
            'ok' => false,
            'detail' => count($broken).' of '.count(PostingRoles::keys())
                ." posting roles have no usable account ({$sample}) — every GL post using them is refused,"
                .' so invoices bill while the books stay empty. Run `php artisan atriom:install`.',
            'broken' => $broken,
        ];
    }

    /**
     * Are the seeded DEMO logins still reachable in production? Production only.
     *
     * `DemoSeeder` creates eight admin users and two portal users on one shared password, which
     * `.env.example` and DEMO.md both publish. Rotating or deleting them before the URL is
     * shareable has been a line on the go-live checklist for weeks — a line, i.e. something a human
     * has to remember, about accounts that include a **super_admin**. This makes the answer
     * self-serving: if those accounts exist in production, health says so by name.
     *
     * Matched on the demo EMAIL DOMAINS rather than the password hash: the password can be rotated
     * via `DEMO_USER_PASSWORD` while the accounts themselves stay — and a demo account with a
     * rotated password is still an account nobody owns, on a role nobody audits.
     *
     * @return array{ok: bool, detail: string}
     */
    private static function checkDemoAccounts(): array
    {
        $production = Deployment::isDeployed();
        $emails = self::demoAccountEmails();

        if ($emails === null) {
            return ['ok' => ! $production, 'detail' => 'unreadable'];
        }

        if ($emails === []) {
            return ['ok' => true, 'detail' => 'no seeded demo logins'];
        }

        return [
            'ok' => ! $production,
            'detail' => $production
                ? self::demoAccountWarning($emails)
                : 'local/testing — expected ('.count($emails).' demo login(s))',
        ];
    }

    /**
     * Can ANYONE administer this install? Production only.
     *
     * `DemoSeeder` is the only thing in this codebase that has ever created a `User`, so a
     * production box — which must not run it — finishes the documented deploy with an empty users
     * table and no way into `/admin`. Nothing said so: the login page renders perfectly and simply
     * rejects every credential, which reads as "I typed it wrong", not as "this install has no
     * accounts".
     *
     * Counts holders of `super_admin` rather than users: an install full of viewers is not one
     * anybody can configure. `atriom:install --admin-email=…` is the remedy, and this check is what
     * stops the state being quiet if someone skips it.
     *
     * @return array{ok: bool, detail: string}
     */
    private static function checkAdminAccess(): array
    {
        $production = Deployment::isDeployed();

        try {
            $admins = User::role('super_admin')->count();
        } catch (Throwable $e) {
            // Before RolesPermissionsSeeder runs there is no such role — which is itself the
            // uninstalled state the `accounting` check already reports in full.
            return ['ok' => ! $production, 'detail' => 'roles not seeded yet — run `php artisan atriom:install`'];
        }

        if ($admins > 0) {
            return ['ok' => true, 'detail' => $admins.' super_admin account(s)'];
        }

        return [
            'ok' => ! $production,
            'detail' => 'no account holds super_admin — nobody can sign in to /admin. '
                .'Create one with `php artisan atriom:install --admin-email=you@example.com`',
        ];
    }

    /**
     * The seeded demo logins present in this database, or null if users cannot be read.
     *
     * Raw and ungated, so `atriom:install` can warn about them on the box it just prepared without
     * inheriting the health check's don't-cry-wolf-locally policy.
     *
     * @return array<int, string>|null
     */
    public static function demoAccountEmails(): ?array
    {
        try {
            return DB::table('users')
                ->where(fn ($q) => $q->where('email', 'like', '%@mall.test')
                    ->orWhere('email', 'like', '%@atriom.test')
                    ->orWhere('email', 'like', '%@atriomwalk.test'))
                ->pluck('email')
                ->all();
        } catch (Throwable $e) {
            return null;
        }
    }

    /** @param  array<int, string>  $emails */
    public static function demoAccountWarning(array $emails): string
    {
        return count($emails).' seeded demo login(s) still active ('
            .implode(', ', array_slice($emails, 0, 3)).(count($emails) > 3 ? ', …' : '')
            .') — their password is published in DEMO.md. Delete them or rotate before go-live.';
    }

    /** @return array{ok: bool, detail: string} */
    private static function checkDatabase(): array
    {
        try {
            DB::connection()->select('select 1');

            return ['ok' => true, 'detail' => 'reachable'];
        } catch (Throwable $e) {
            return ['ok' => false, 'detail' => 'unreachable: '.$e->getMessage()];
        }
    }

    /** @return array{ok: bool, detail: string} */
    private static function checkCache(): array
    {
        try {
            $key = 'health:probe';
            Cache::put($key, '1', 10);

            return Cache::get($key) === '1'
                ? ['ok' => true, 'detail' => 'read/write ok']
                : ['ok' => false, 'detail' => 'wrote a value and read back something else'];
        } catch (Throwable $e) {
            return ['ok' => false, 'detail' => 'failed: '.$e->getMessage()];
        }
    }

    /** @return array{ok: bool, detail: string} */
    /**
     * Is the worker keeping up — on the queue actually configured?
     *
     * This counted rows in the `jobs` and `failed_jobs` TABLES regardless of `QUEUE_CONNECTION`. On
     * the database driver that is right; on redis or sqs those tables stay empty forever, so the
     * check reported "0 queued, 0 failed" for a queue that could be hours behind or entirely dead.
     * A green tick that cannot go red is worse than no check, and this one guards ETA submission
     * and the real-time GL sync.
     *
     * `Queue::size()` asks the configured driver, so it answers for whichever backend is in use.
     * Failed jobs come from the failer, which is a separate concern from the transport and may be
     * absent entirely (`QUEUE_FAILED_DRIVER=null`) — that is reported rather than counted as zero.
     */
    /**
     * Is the worker keeping up — on the queue actually configured?
     *
     * This counted rows in the `jobs` and `failed_jobs` TABLES regardless of `QUEUE_CONNECTION`. On
     * the database driver that is correct; on redis or sqs those tables stay empty forever, so a
     * queue hours behind — or a worker that died last week — reported "0 queued, 0 failed". A green
     * tick that cannot go red is worse than no check, and this one is what stands between a stopped
     * worker and silently unposted ledger entries.
     *
     * Depth comes from `Queue::size()`, which asks the configured driver. Failures are a separate
     * concern from the transport: they are recorded by the failer whatever the driver, so they are
     * checked FIRST and unconditionally — including under `sync`, where jobs run inline but a
     * failure from an earlier run is still a document that did not happen.
     */
    private static function checkQueue(): array
    {
        try {
            // `QUEUE_FAILED_DRIVER=null` resolves to a provider that accepts a failure and forgets
            // it. Counting its zero would be the same fail-open in a new costume.
            $failer = app()->bound('queue.failer') ? app('queue.failer') : null;

            if (! $failer instanceof FailedJobProviderInterface
                || $failer instanceof NullFailedJobProvider) {
                return ['ok' => false, 'detail' => 'failed jobs are not recorded (QUEUE_FAILED_DRIVER=null) — a job that dies leaves no trace'];
            }

            $failed = count($failer->ids());
            $maxFailed = (int) config('health.max_failed_jobs');

            if ($failed > $maxFailed) {
                return ['ok' => false, 'detail' => "{$failed} failed job(s) (max {$maxFailed})"];
            }

            $connection = (string) config('queue.default');
            $driver = (string) config("queue.connections.{$connection}.driver");

            // `sync` runs jobs inline: there is no queue to be behind on, and reporting a depth of
            // 0 would imply a worker exists and is keeping up.
            if ($driver === 'sync') {
                return ['ok' => true, 'detail' => "sync driver — jobs run inline, no worker to watch; {$failed} failed"];
            }

            $pending = (int) Queue::connection($connection)->size(
                (string) (config("queue.connections.{$connection}.queue") ?: 'default')
            );

            $maxPending = (int) config('health.max_pending_jobs');

            // A large backlog means the worker is not running — the same silent failure as a dead
            // scheduler, and it stalls ETA + GL sync.
            if ($pending > $maxPending) {
                return ['ok' => false, 'detail' => "{$pending} job(s) queued on [{$connection}] (max {$maxPending}) — is the worker running?"];
            }

            return ['ok' => true, 'detail' => "{$pending} queued, {$failed} failed on [{$connection}]"];
        } catch (Throwable $e) {
            return ['ok' => false, 'detail' => 'unreadable: '.$e->getMessage()];
        }
    }

    private static function checkScheduler(): array
    {
        $path = self::heartbeatPath();

        if (! File::exists($path)) {
            return ['ok' => false, 'detail' => 'never ran (no heartbeat) — is cron installed?'];
        }

        $age = now()->getTimestamp() - (int) File::get($path);
        $max = (int) config('health.max_scheduler_age_seconds');

        return $age <= $max
            ? ['ok' => true, 'detail' => "last ran {$age}s ago"]
            : ['ok' => false, 'detail' => "last ran {$age}s ago (max {$max}) — cron has stopped"];
    }

    /**
     * Backup freshness, read independently of `backup:monitor`.
     *
     * That command is itself scheduled, so a dead cron silences the backups AND
     * the alarm that would report them missing. This check runs from an HTTP
     * request instead, which is the only way the failure is visible from outside
     * the box.
     *
     * @return array{ok: bool, detail: string}
     */
    /**
     * EVERY backup destination has a recent archive — not just the first one.
     *
     * This read `$disks[0]` and stopped. The recommended go-live setting is
     * `BACKUP_DISKS="backups,s3"`, and the whole reason the second disk exists is that the first
     * dies with the machine — so the destination that actually protects you was the one never
     * checked. An s3 upload silently failing on credentials reported as healthy, indefinitely.
     *
     * Fails if ANY disk is stale or unreadable, and names which. A partial backup is not a backup:
     * the question this answers is "can I restore after losing the box", and one surviving copy on
     * the box itself does not answer it.
     */
    private static function checkBackups(): array
    {
        $disks = config('backup.backup.destination.disks', []);
        $disks = is_array($disks) ? array_values(array_filter($disks)) : [];

        if ($disks === []) {
            return ['ok' => false, 'detail' => 'no backup destination configured'];
        }

        $max = (int) config('health.max_backup_age_hours');
        $problems = [];
        $healthy = [];

        foreach ($disks as $disk) {
            try {
                $files = collect(Storage::disk($disk)->allFiles())
                    ->filter(fn (string $f): bool => str_ends_with($f, '.zip'));

                if ($files->isEmpty()) {
                    $problems[] = "no archive on [{$disk}]";

                    continue;
                }

                $newest = $files->max(fn (string $f): int => Storage::disk($disk)->lastModified($f));
                $ageHours = (int) floor((now()->getTimestamp() - $newest) / 3600);

                if ($ageHours > $max) {
                    $problems[] = "[{$disk}] newest archive {$ageHours}h old (max {$max}h)";

                    continue;
                }

                $healthy[] = "[{$disk}] {$ageHours}h";
            } catch (Throwable $e) {
                $problems[] = "[{$disk}] unreadable: ".$e->getMessage();
            }
        }

        return $problems === []
            ? ['ok' => true, 'detail' => 'newest archive '.implode(', ', $healthy)]
            : ['ok' => false, 'detail' => implode('; ', $problems)];
    }

    /**
     * The demo-payment shortcut must not be reachable on production.
     *
     * `DemoPayments::enabled()` already refuses production outright, so this check is not the
     * guard — it is the alarm. It fails when the FLAG is set on a production box, because that
     * records an intent somebody had, and an intent that survives into production is how the 2FA
     * enforcement sat silently broken here for months. A setting that is safe only because a
     * second mechanism overrides it should still be visible.
     *
     * **On staging the alarm matters MORE, not less** — and until 2026-08-16 it was the one place
     * that stayed quiet, reporting `enabled (non-production — expected)`. On production the flag is
     * merely an intent, because `DemoPayments::forbiddenByEnvironment()` overrides it. On staging
     * nothing overrides it: the shortcut is genuinely live, an authenticated tenant can mark their
     * own invoice paid, and `billing:reconcile` stays green because every relationship really is
     * consistent — the money simply never existed. That is precisely the box PRODUCTION-RUNBOOK §12
     * tells the operator to rehearse the cut-over on twice and get the same numbers both times.
     *
     * The opt-in itself stays legal (`DemoPaymentEnvironmentGuardTest` pins it — the flag is "a
     * decision somebody made"). What changes is that the decision is no longer silent.
     *
     * @return array{ok: bool, detail: string}
     */
    private static function checkDemoPayments(): array
    {
        // `Deployment`, matching DemoPayments — NOT `config('app.env')`. The two disagree the
        // moment a test or a runtime tweak sets one and not the other, and a guard that reads the
        // environment differently from the thing it guards is not a guard.
        if (! Deployment::isDeployed()) {
            return ['ok' => true, 'detail' => DemoPayments::enabled()
                ? 'enabled (workstation — expected)'
                : 'disabled'];
        }

        if (Deployment::isPreProduction()) {
            if (DemoPayments::enabled()) {
                return [
                    'ok' => false,
                    'detail' => 'the demo-payment shortcut is LIVE on '.Deployment::name()
                        .' — a tenant can mark their own invoice paid, writing a real captured '
                        .'payment (Dr Bank / Cr AR) for money that never arrived. Nothing overrides '
                        .'the flag outside production. Unset DEMO_PAYMENTS_ENABLED before rehearsing '
                        .'a cut-over on this box.',
                ];
            }

            return ['ok' => true, 'detail' => 'disabled ('.Deployment::name().')'];
        }

        if (config('integrations.demo_payments.enabled')) {
            return [
                'ok' => false,
                'detail' => 'DEMO_PAYMENTS_ENABLED is set on PRODUCTION — unset it. The shortcut '
                    .'writes a real captured payment (Dr Bank / Cr AR) for money that never arrived.',
            ];
        }

        // Belt and braces: if this were ever true on production the guard itself has regressed.
        if (DemoPayments::enabled()) {
            return ['ok' => false, 'detail' => 'demo payments resolve as ENABLED on production — DemoPayments::enabled() has regressed'];
        }

        return ['ok' => true, 'detail' => 'disabled (production)'];
    }

    /**
     * Does the mobile password-reset link go anywhere?
     *
     * `TenantResetPasswordNotification` emails whatever `app.mobile_reset_url` resolves to, and
     * that config falls back to `APP_URL/reset-password` — **a route this application does not
     * have**. So with `APP_MOBILE_RESET_URL` unset (it is absent from `.env.example`), a tenant
     * asking the mobile app to reset their password receives a mail whose only button 404s.
     *
     * Nothing else notices: the mail sends successfully, the token is minted correctly, and the
     * failure lands entirely on the tenant. Skipped only on a workstation, where the 404 is obvious
     * and harmless — **staging is checked**, because staging is where the mobile app is pointed
     * before it ships, so a reset link that 404s there is a bug found by a tester rather than by a
     * tenant.
     *
     * @return array{ok: bool, detail: string}
     */
    private static function checkMobileResetUrl(): array
    {
        if (! Deployment::isDeployed()) {
            return ['ok' => true, 'detail' => 'not checked on a workstation'];
        }

        // Read config, never env() — this class runs under `config:cache`, where env() is null and
        // the check would report a false failure on every correctly-configured box.
        $configured = (string) config('app.mobile_reset_url');
        $unrouted = rtrim((string) config('app.url'), '/').'/reset-password';

        if ($configured === '' || $configured === $unrouted) {
            return [
                'ok' => false,
                'detail' => 'APP_MOBILE_RESET_URL is unset — mobile password-reset emails link to '
                    .$unrouted.', which is not a route in this application. Set the app deep link, '
                    .'or the https page that completes /api/v1/auth/reset-password.',
            ];
        }

        return ['ok' => true, 'detail' => 'configured'];
    }

    /**
     * Cache, session and queue must not run on the database in production.
     *
     * `.env.example` ships all three as `database`, and `docs/operations/INFRASTRUCTURE.md` §5 calls Redis
     * non-negotiable — with nothing in between enforcing it. That gap is not merely slow: MySQL is
     * **off-box** in the documented estate, so on the shipped defaults every session read and
     * write, every spatie permission-catalogue read, every queue poll and **every `Cache::lock()`**
     * crosses the network.
     *
     * The locks are what make it a correctness concern rather than a performance one. This codebase
     * leans on them hard — `AllocatesDocumentNumber` takes a *blocking* lock around every numbered
     * document's insert, and `MonthlyBillingService`'s double-bill guard is a cache lock with **no
     * DB unique index behind it**, so the lock IS the guard. A lock whose store is a slow remote
     * database is a guard with a longer window.
     *
     * Local and CI run on `database` deliberately and must stay quiet. **Staging is checked**:
     * INFRASTRUCTURE.md §5 gives staging its own Redis keyspace (`REDIS_DB=1`, prefix `atr_s_`)
     * precisely because it shares the topology, so a staging box left on the `database` driver is
     * both the same defect and a rehearsal that does not rehearse the production configuration.
     *
     * @return array{ok: bool, detail: string}
     */
    private static function checkRuntimeDrivers(): array
    {
        if (! Deployment::isDeployed()) {
            return ['ok' => true, 'detail' => 'not checked on a workstation'];
        }

        $onDatabase = array_keys(array_filter([
            'cache' => config('cache.default') === 'database',
            'session' => config('session.driver') === 'database',
            'queue' => config('queue.default') === 'database',
        ]));

        if ($onDatabase !== []) {
            return [
                'ok' => false,
                'detail' => implode(', ', $onDatabase).' still on the `database` driver on '
                    .Deployment::name().' — INFRASTRUCTURE.md §5 requires Redis. Every Cache::lock() '
                    .'then crosses the network, and the monthly-billing double-bill guard IS a cache lock.',
            ];
        }

        return ['ok' => true, 'detail' => 'cache/session/queue off the database'];
    }

    /** @return array{ok: bool, detail: string} */
    private static function checkStorage(): array
    {
        try {
            $probe = 'health/probe.txt';
            Storage::disk('local')->put($probe, (string) now()->getTimestamp());
            $ok = Storage::disk('local')->exists($probe);
            Storage::disk('local')->delete($probe);

            return $ok
                ? ['ok' => true, 'detail' => 'writable']
                : ['ok' => false, 'detail' => 'wrote a file that did not appear'];
        } catch (Throwable $e) {
            return ['ok' => false, 'detail' => 'not writable: '.$e->getMessage()];
        }
    }
}
