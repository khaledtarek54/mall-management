<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Throwable;

/**
 * The one command a box has to pass before it is trusted with data.
 *
 * ## Why a command rather than a checklist
 *
 * Gate 5 and Gate 6 of `docs/qa/PRE-STAGING-QA.md` were four artisan calls and a health check spread
 * across three documents (`GO-LIVE.md`, `STAGING.md`, the QA plan). Every one of them exists, every
 * one exits non-zero on its own — and a list of five things a person has to remember to run in order
 * is a list that gets run once, on the day someone writes it down. This is the same move
 * `composer qa` made for the QA harness: the checks were never the missing part, running them was.
 *
 * ## What it runs, and why each one
 *
 * | Step | Answers |
 * |---|---|
 * | `atriom:health` | Is the box itself alive — DB, cache, queue worker, **scheduler heartbeat**, backups, storage, translations? |
 * | `atriom:audit-charge-schedules` | Are there leases whose charge rows overlap (they bill NOTHING), gap, or carry no start date? |
 * | `atriom:audit-property-dimension` | Are there money documents filed against NO property? They show on every mall and reach no owner statement |
 * | `billing:reconcile --deep` | Do the books agree with the documents — AR, the four settlement channels, CAM, deposits, and every posted entry against its current source? |
 *
 * `--quick` drops the last one. It is the only step that scales with HISTORY rather than with the
 * size of the portfolio, which makes it right for a cutover and wrong for the fifth code-only
 * release of an afternoon — and `deploy.sh` runs this on every release, so the fast set is what
 * makes running it there sustainable rather than something someone eventually comments out.
 *
 * The two audits are **pre-import** questions and the reconciliation is a **post-import** one, which
 * is why the command is worth running on both sides of a data load: before, it proves the box is
 * clean; after, it proves the load did not break anything.
 *
 * ## Read-only by default, and that is the point
 *
 * `accounting:sync-ledger --all` is the obvious fifth step and it is deliberately behind `--sync`,
 * because it **writes**: it posts every document that has no entry yet. A check that silently
 * repairs what it is checking cannot tell you the box was broken — the same shape as a
 * reconciliation whose expected value is derived from its own subject (pre-staging QA, F-08). Run it
 * with `--sync` when you have just restored a database and WANT the backfill; run it without when
 * you are asking a question.
 *
 * ## What it deliberately does NOT do
 *
 * It does not check credentials, backup destinations or the demo password — those are
 * `docs/operations/GO-LIVE.md` rows that no command can verify from inside the app (a `.env` value being
 * present says nothing about whether it is the right one). `atriom:health` covers the ones that ARE
 * observable, and `docs/operations/STAGING-CUTOVER.md` carries the rest as an ordered runbook.
 */
class PreflightCommand extends Command
{
    protected $signature = 'atriom:preflight
        {--quick : Skip the deep reconciliation — health and the two data audits only}
        {--sync : Also run `accounting:sync-ledger --all`, which WRITES — use after restoring a database}
        {--stop-on-failure : Stop at the first failing step instead of running them all}';

    protected $description = 'Run every pre-deploy gate in order — health, the two data audits, and the books reconciliation.';

    /**
     * Each step, in the order a person would run them.
     *
     * Health first: if the queue is dead or the scheduler never ran, the audits below are measuring
     * a box that would not have processed anything anyway, and knowing that changes how you read
     * everything after it.
     *
     * @var array<int, array{command: string, args: array<string, mixed>, why: string}>
     */
    private const STEPS = [
        [
            'command' => 'atriom:health',
            'args' => [],
            'why' => 'the box itself — DB, cache, queue worker, scheduler heartbeat, backups, storage, translations',
        ],
        [
            'command' => 'atriom:audit-charge-schedules',
            'args' => [],
            'why' => 'leases whose charge rows overlap (they bill NOTHING), gap, or carry no start date',
        ],
        [
            'command' => 'atriom:audit-property-dimension',
            'args' => [],
            'why' => 'money documents filed against no property — on every mall, on no owner statement',
        ],
        [
            'command' => 'billing:reconcile',
            'args' => ['--deep' => true],
            'why' => 'the books against the documents — AR, all four settlement channels, CAM, deposits',
            // The only slow step, and the only one that scales with HISTORY rather than with the
            // size of the portfolio: `--deep` re-derives every posted entry all-time. That is right
            // for a cutover and wrong for a code-only release, which is what `--quick` is for.
            'slow' => true,
        ],
    ];

    public function handle(): int
    {
        $steps = self::STEPS;

        if ($this->option('quick')) {
            // `deploy.sh` runs this on every release. The two audits are count queries over
            // indexed columns, so they cost nothing and answer the questions a release can
            // actually break; the deep reconciliation stays a cutover and weekly-scheduled job.
            $steps = array_values(array_filter($steps, fn (array $s): bool => ! ($s['slow'] ?? false)));
        }

        if ($this->option('sync')) {
            // Last, never first. Backfilling before the audits would post entries for the very
            // documents the audits are about to call malformed.
            $steps[] = [
                'command' => 'accounting:sync-ledger',
                'args' => ['--all' => true],
                'why' => 'backfill every document that has no ledger entry yet (WRITES)',
            ];
        }

        $results = [];
        $failed = 0;

        foreach ($steps as $step) {
            $this->newLine();
            $this->components->info("{$step['command']} — {$step['why']}");

            [$code, $error] = $this->runStep($step['command'], $step['args']);

            $results[] = [
                $step['command'],
                $code === self::SUCCESS ? 'PASS' : 'FAIL',
                $error ?? ($code === self::SUCCESS ? '' : "exit {$code}"),
            ];

            if ($code !== self::SUCCESS) {
                $failed++;

                if ($this->option('stop-on-failure')) {
                    break;
                }
            }
        }

        $this->newLine();
        $this->table(['Step', 'Result', 'Detail'], $results);

        if ($failed > 0) {
            // Named rather than counted: "2 steps failed" makes the reader scroll back up for the
            // one thing they need, which on a deploy is the moment they are least able to.
            $this->error(sprintf(
                '%d of %d preflight step(s) FAILED: %s',
                $failed,
                count($results),
                collect($results)->where(1, 'FAIL')->pluck(0)->join(', '),
            ));

            return self::FAILURE;
        }

        $this->newLine();
        $this->info('Preflight clean — every gate passed.');

        if ($this->option('quick')) {
            $this->line('  (--quick: the deep reconciliation was skipped. Run without it before a cutover.)');
        } elseif (! $this->option('sync')) {
            $this->line('  (Read-only run. Add --sync to backfill the ledger after a database restore.)');
        }

        return self::SUCCESS;
    }

    /**
     * Run one step and report its exit code, converting a THROWN failure into a failed step.
     *
     * A step that throws must not abort the run: the whole value of a preflight is the full picture,
     * and one command blowing up is exactly when you most want to know what the other four said.
     *
     * @param  array<string, mixed>  $args
     * @return array{0: int, 1: string|null}
     */
    private function runStep(string $command, array $args): array
    {
        try {
            return [$this->call($command, $args), null];
        } catch (Throwable $e) {
            $this->error("  {$command} threw: {$e->getMessage()}");

            return [self::FAILURE, 'threw: '.mb_substr($e->getMessage(), 0, 120)];
        }
    }
}
