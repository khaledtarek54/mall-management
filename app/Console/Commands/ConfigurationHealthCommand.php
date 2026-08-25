<?php

namespace App\Console\Commands;

use App\Support\ConfigurationHealth;
use Illuminate\Console\Command;

/**
 * The same checks as `/admin/configuration-health`, from the CLI — so the deploy can read them.
 *
 * `App\Support\ConfigurationHealth` answers *"is this install SET UP"*, which is a different
 * question from `App\Support\Health`'s *"is this box ALIVE"*, and its own class docblock says so:
 * a perfectly healthy installation issues tax invoices with no registration number on them and
 * bills every tenant through a floor rate because nobody classified the charge codes, and neither
 * shows up as an outage.
 *
 * **Eight checks existed and lived only on a screen.** Nothing outside a browser could run them:
 * no command, no route, and `atriom:preflight` — the gate `deploy.sh` runs on every release —
 * asked `atriom:health` and the two data audits and stopped there. So an install could pass every
 * automated pre-deploy gate with no seller TRN, an incomplete posting map and no open accounting
 * period, and the only thing standing between that and a real month was somebody remembering to
 * open a page. That is the same shape as the finding one level up, recorded in `deploy.sh`: until
 * 2026-08-19 the preflight step was `atriom:health` alone, so a release could leave leases whose
 * charge rows bill nothing and report "healthy" because the box was alive. Liveness, correctness
 * and CONFIGURATION fail differently, and only two of the three were on the gate.
 *
 * ## Only BLOCKING rows decide the exit code
 *
 * `advisory` means the system is working and could work better — no billing-enquiries address, a
 * roster awaiting its first payroll run. Those are true of most installs for a while, and a step
 * that is permanently red is a step people stop reading, which would cost more than the advisories
 * are worth. `--strict` is there for a cutover, when the answer really is "everything, please".
 *
 * Reports rather than refuses, exactly as the page does: an unconfigured Atriom still bills
 * correctly on its defaults. What this removes is the silence.
 */
class ConfigurationHealthCommand extends Command
{
    protected $signature = 'atriom:config-health
        {--strict : Fail on advisory rows too — for a cutover, where "working" is not the bar}';

    protected $description = 'Check what is not configured yet — tax identity, charge codes, posting map, open period, payroll rates';

    public function handle(): int
    {
        $checks = ConfigurationHealth::run();

        $this->table(
            ['Check', 'Severity', 'Status', 'What it means'],
            array_map(fn (array $c): array => [
                $c['key'],
                $c['severity'],
                $c['ok'] ? 'OK' : 'FAIL',
                // The impact sentence, not the raw detail — a row reading "seller_tax_identity ·
                // FAIL · (blank)" tells the reader nothing they can act on, and the detail IS blank
                // on several checks precisely because the failure is an absence.
                ConfigurationHealth::sentenceFor($c),
            ], $checks),
        );

        $failing = array_values(array_filter(
            $checks,
            fn (array $c): bool => ! $c['ok']
                && ($this->option('strict') || $c['severity'] === ConfigurationHealth::BLOCKING),
        ));

        if ($failing === []) {
            $advisories = count(array_filter($checks, fn (array $c): bool => ! $c['ok']));

            $this->info($advisories === 0
                ? 'Configured — every check passed.'
                : "No blocking gaps. {$advisories} advisory row(s) above — re-run with --strict to fail on those too.");

            return self::SUCCESS;
        }

        // Named rather than counted, for the reason `atriom:preflight` gives for doing the same:
        // on a deploy, "3 checks failed" makes the reader scroll back up at the moment they are
        // least able to.
        $this->error(sprintf(
            '%d configuration gap(s) — %s. Fix at /admin/configuration-health.',
            count($failing),
            implode(', ', array_column($failing, 'key')),
        ));

        return self::FAILURE;
    }
}
