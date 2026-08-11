<?php

namespace App\Console\Commands;

use App\Models\AccountingPeriod;
use App\Models\AccountMapping;
use App\Models\ChargeCode;
use App\Models\LedgerAccount;
use App\Support\Health;
use Database\Seeders\AccountingSeeder;
use Database\Seeders\RolesPermissionsSeeder;
use Illuminate\Console\Command;
use Illuminate\Console\ConfirmableTrait;
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

    protected $signature = 'atriom:install {--force : Run without the production confirmation}';

    protected $description = 'Seed the reference data a fresh install needs (roles, chart of accounts, mappings, charge codes, fiscal year) and verify it can post';

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

        // Not a failure — an install can legitimately be a demo box — but it must not be silent on
        // the machine that will hold real tenants' money.
        if ($demo = Health::demoAccountEmails()) {
            $this->components->warn(Health::demoAccountWarning($demo));
        }

        $this->newLine();
        $this->components->bulletList([
            'Next: create the first property, then import or enter real tenants and leases.',
            'Run `php artisan atriom:health` before sending traffic — it also checks cron, the queue worker and backups.',
        ]);

        return self::SUCCESS;
    }
}
