<?php

namespace App\Console\Commands;

use App\Models\AccountingPeriod;
use App\Models\AccountMapping;
use App\Models\ApprovalRule;
use App\Models\ChargeCode;
use App\Models\Department;
use App\Models\Holiday;
use App\Models\LedgerAccount;
use App\Models\User;
use App\Support\Health;
use Database\Seeders\AccountingSeeder;
use Database\Seeders\ApprovalRulesSeeder;
use Database\Seeders\DepartmentSeeder;
use Database\Seeders\ExpenseCategorySeeder;
use Database\Seeders\HolidaySeeder;
use Database\Seeders\PaymentMethodSeeder;
use Database\Seeders\RolesPermissionsSeeder;
use Database\Seeders\UtilityTariffSeeder;
use Illuminate\Console\Command;
use Illuminate\Console\ConfirmableTrait;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * Prepare a migrated database for real data — the whole first-deploy sequence, in one command.
 *
 * **Why this exists.** The reference data this system cannot work without ships as SEEDERS, not
 * migrations: the roles and their 182-permission catalogue, the chart of accounts, the semantic
 * account mappings, the charge codes and an open fiscal year. Until 2026-08-11 the runbook told a
 * deployer to run `RolesPermissionsSeeder` and nothing else — and a database in that state BILLS
 * PERFECTLY AND POSTS NOTHING. The invoice is correct, `accounting:sync-ledger` refuses every entry
 * with "No account mapping for role 'accounts_receivable'", and the general ledger stays at zero.
 * The realtime posting hook is best-effort and the sweep's exit code goes to a cron log, so the
 * first person to notice would be the accountant asking for a trial balance a month later.
 *
 * A checklist of three commands is a checklist someone half-follows. This is one command that runs
 * them in order and then **verifies the result**, exiting non-zero if the install still cannot post.
 *
 * **Idempotent by construction** — both seeders are `updateOrCreate`/`firstOrCreate`, and the fiscal
 * calendar only opens periods it lacks. Running it on a live system re-asserts the reference data
 * without touching a single business row.
 *
 * **It never seeds demo data.** `DemoSeeder` is demo tenants, demo leases and eight published
 * logins; that is what `migrate --seed` would have given a production box, and it is why this
 * command exists rather than a `--seed` flag in the runbook.
 */
class InstallCommand extends Command
{
    use ConfirmableTrait;

    protected $signature = 'atriom:install
        {--force : Run without the production confirmation}
        {--admin-email= : Create the first administrator with this email}
        {--admin-name= : That administrator\'s display name}
        {--admin-password= : Their password (a strong one is generated and printed if omitted)}';

    protected $description = 'Seed the reference data a fresh install needs (roles, chart of accounts, mappings, charge codes, fiscal year), create the first administrator, and verify it can post';

    public function handle(): int
    {
        // Laravel's own production guard — this writes to the database, so it asks first.
        if (! $this->confirmToProceed()) {
            return self::FAILURE;
        }

        $this->components->info('Seeding the reference data a real install needs (never demo data).');

        $this->callSilent('db:seed', ['--class' => RolesPermissionsSeeder::class, '--force' => true]);
        $this->components->twoColumnDetail('Roles + permissions',
            Role::count().' roles · '.Permission::count().' permissions');

        // The spend-approval ladder. Missing here until 2026-08-11, and its absence was SILENT:
        // with `approval_rules` empty, ApprovalPolicy::permissionFor() returns null and
        // canApprove() returns true for ANY amount — so FR-CM-11 (spare-part tiers) and
        // FR-PROC-02 (purchase-request tiers) simply did not exist in production. Base RBAC still
        // applied, so this was lost value-tiering rather than open season; but `required_permission`
        // froze as null, so the audit trail could not even show which tier had been required.
        // Every approval test seeds this itself, which is why a green suite never noticed.
        $this->callSilent('db:seed', ['--class' => ApprovalRulesSeeder::class, '--force' => true]);
        $this->components->twoColumnDetail('Approval ladder', ApprovalRule::count().' bands');

        // Departments are reference data too, and DepartmentResource::canCreate() returns false
        // because the set is "seeded" — so on an install that skipped this the table stayed empty
        // FOREVER with no in-app remedy, and tenant-request auto-routing was permanently off.
        $this->callSilent('db:seed', ['--class' => DepartmentSeeder::class, '--force' => true]);

        // The utility tariffs a mall recharges against. Seeded WITHOUT rates — a published figure is
        // the operator's to confirm — but seeded, because until 2026-08-20 nothing created a tariff
        // at all and every meter therefore priced a reading at 0.00, which the billing service then
        // correctly refused. The catalogue existing is what turns that into a screen asking to be
        // priced rather than a feature that appears to do nothing.
        $this->callSilent('db:seed', ['--class' => UtilityTariffSeeder::class, '--force' => true]);

        $this->components->twoColumnDetail('Departments', Department::count().' departments');

        // Egypt's fixed-date public holidays, this year and next. Without this a fresh deploy
        // ships an EMPTY calendar, and a missing holiday is completely silent — an SLA measured
        // straight across Eid, with nothing on any screen to say why. The moon-sighted dates are
        // deliberately not seeded; the operator adds those, which the screen guide says.
        $this->callSilent('db:seed', ['--class' => PaymentMethodSeeder::class, '--force' => true]);
        $this->callSilent('db:seed', ['--class' => ExpenseCategorySeeder::class, '--force' => true]);
        $this->callSilent('db:seed', ['--class' => HolidaySeeder::class, '--force' => true]);
        $this->components->twoColumnDetail('Holidays', Holiday::count().' fixed-date holidays (the moon-sighted ones are yours to add)');

        $this->callSilent('db:seed', ['--class' => AccountingSeeder::class, '--force' => true]);
        $this->components->twoColumnDetail('Chart of accounts', LedgerAccount::count().' accounts');
        $this->components->twoColumnDetail('Account mappings', AccountMapping::count().' posting roles mapped');
        $this->components->twoColumnDetail('Charge codes', ChargeCode::count().' codes');
        $this->components->twoColumnDetail('Fiscal calendar', AccountingPeriod::count().' periods open');

        // The point of the command: not "did the seeders run" but "can this database post?".
        // Asked through the same resolver the journalizers use, and WITHOUT the local/testing gate
        // the health check applies — an installer that reports fine on a box it just failed to set
        // up would be the same class of silence this replaced.
        $verdict = Health::accountingReadiness();

        $this->newLine();

        if (! $verdict['ok']) {
            $this->components->error('This install still cannot post to the general ledger: '.$verdict['detail']);

            return self::FAILURE;
        }

        $this->components->info('Ready to post: '.$verdict['detail'].'.');

        $this->ensureAnAdministratorExists();

        // Not a failure — an install can legitimately be a demo box — but it must not be silent on
        // the machine that will hold real tenants' money.
        if ($demo = Health::demoAccountEmails()) {
            $this->components->warn(Health::demoAccountWarning($demo));
        }

        $this->reportBackupCapability();

        $this->newLine();
        $this->components->bulletList([
            'Next: create the first property, then import or enter real tenants and leases.',
            'Run `php artisan atriom:health` before sending traffic — it also checks cron, the queue worker and backups.',
        ]);

        return self::SUCCESS;
    }

    /**
     * Say, here, whether this box can actually back itself up.
     *
     * **This command used to answer the question by suggesting another command.** The last line of
     * the install was *"run `php artisan atriom:health` — it also checks cron, the queue worker and
     * backups"*, and on the real deployment nobody did: `mysqldump` was absent, `backup:run` exited
     * 127, and **twelve days passed with no archive written at all** while the health check sat
     * there reporting it correctly to no one. The mechanism was never the problem. Nothing forced
     * the question at the only moment somebody was certain to be looking.
     *
     * So the installer asks it. `Health::backupCapability()` is one call away and this command
     * already refuses to finish when the database cannot post — the same standard applied to the
     * safeguard that decides whether any of that data survives a dead disk.
     *
     * **Reported, not fatal**, for the same reason the demo-account warning is: configuring backups
     * after installing is a legitimate order of operations, and an installer that refused would
     * simply be run with the check disabled. What it must not be is one bullet among four.
     */
    private function reportBackupCapability(): void
    {
        $verdict = Health::backupCapability(
            driver: config('database.connections.'.config('database.default').'.driver'),
            dumpBinaryPath: (string) config('database.connections.'.config('database.default').'.dump.dump_binary_path', ''),
            disks: (array) config('backup.backup.destination.disks', []),
            environment: (string) app()->environment(),
        );

        $this->newLine();

        if ($verdict['ok']) {
            $this->components->info('Backups: '.$verdict['detail'].'.');

            return;
        }

        // Each problem on its own line: the two causes are fixed in different places (the deploy
        // image vs BACKUP_DISKS) and running them together into one sentence is how half of a
        // two-part fix gets applied.
        $this->components->error('THIS INSTALL CANNOT BACK ITSELF UP.');

        foreach (explode('; ', $verdict['detail']) as $problem) {
            $this->components->bulletList([$problem]);
        }

        $this->components->warn(
            'The first hardware failure would lose every invoice, payment and ledger entry. '
            .'Fix before real tenant data is entered — see docs/operations/GO-LIVE.md §1.1.'
        );
    }

    /**
     * Make sure SOMEONE can sign in.
     *
     * `DemoSeeder` is the only thing in this codebase that has ever created a `User` — so a
     * production box that correctly refuses demo data finishes the documented first deploy with an
     * **empty users table and no way to reach `/admin`**. The runbook never mentioned it; the fix
     * would have been someone SSH-ing in with tinker, inventing a password, and remembering to
     * attach `super_admin` by hand.
     *
     * Three paths, and none of them creates an account nobody asked for:
     * - a super_admin already exists → say so and leave it alone (idempotent re-runs);
     * - `--admin-email` given → create it, generating a strong password when none was supplied and
     *   printing it ONCE (never a default, never a published one — that is the demo-login problem);
     * - nothing given → ask when a human is present, warn loudly with the exact flags when not.
     *
     * Not a hard failure: an install that will import its users from elsewhere is legitimate. The
     * `admin_access` health check is what keeps the empty state from being quiet afterwards.
     */
    private function ensureAnAdministratorExists(): void
    {
        if (User::role('super_admin')->exists()) {
            $this->components->twoColumnDetail('Administrator', 'already present — unchanged');

            return;
        }

        $email = $this->option('admin-email');

        // `--force` is the scripted path (deploy pipelines, CI, this project's own tests): it must
        // never block on a prompt. A human without it gets asked.
        $mayPrompt = ! $this->option('force') && $this->input->isInteractive();

        if ($email === null && $mayPrompt) {
            if (! $this->confirm('No administrator exists yet — nobody can sign in. Create one now?', true)) {
                $this->warnNoAdministrator();

                return;
            }

            $email = $this->ask('Email');
        }

        if ($email === null) {
            $this->warnNoAdministrator();

            return;
        }

        $name = $this->option('admin-name')
            ?? ($mayPrompt ? $this->ask('Name', 'Administrator') : 'Administrator');

        $password = $this->option('admin-password');
        $generated = $password === null;
        $password ??= Str::password(20);

        $validator = Validator::make(
            ['email' => $email, 'name' => $name, 'password' => $password],
            [
                'email' => ['required', 'email', 'unique:users,email'],
                'name' => ['required', 'string', 'max:255'],
                'password' => ['required', 'string', 'min:12'],
            ],
        );

        if ($validator->fails()) {
            $this->components->error('Administrator not created: '.implode(' ', $validator->errors()->all()));

            return;
        }

        $user = User::create([
            'name' => $name,
            'email' => $email,
            'password' => Hash::make($password),
            'email_verified_at' => now(),
        ]);
        $user->assignRole('super_admin');

        $this->components->twoColumnDetail('Administrator', $email.' (super_admin)');

        if ($generated) {
            // Printed once, on the operator's own terminal, and never stored anywhere we could
            // later be asked to reveal it.
            $this->newLine();
            $this->components->warn("Password (shown once — store it now): {$password}");
        }
    }

    private function warnNoAdministrator(): void
    {
        $this->components->warn(
            'No administrator exists, so nobody can sign in to /admin. Create one with: '
            .'php artisan atriom:install --admin-email=you@example.com --admin-name="Your Name"'
        );
    }
}
