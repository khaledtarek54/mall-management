<?php

namespace App\Console\Commands;

use App\Support\Stability;
use Illuminate\Console\Command;

/**
 * `atriom:stability` — is this system sound, and what could NOT be checked?
 *
 * The tiers and the rules live in {@see Stability}; this is presentation and the exit code.
 *
 * **Three exit codes, because there are three answers.** 0 is PASS, 1 is FAIL, and **2 is
 * INCOMPLETE** — nothing broke and something was not checked. A tool that folded INCOMPLETE into
 * PASS would be the thing this whole command exists to stop: a green tick over a tier that examined
 * nothing. A pre-push hook can treat 2 as acceptable on a laptop while a release path does not.
 *
 * It writes a STAMP for the current commit (`--stamp`), which is what lets the push hook know
 * whether this exact tree was ever verified. The stamp records the SHA, the verdict and which tiers
 * actually ran — a stamp that says PASS over three skipped tiers is not the same claim as a full
 * run, and flattening the two is how a green stamp comes to mean nothing.
 */
class StabilityCommand extends Command
{
    protected $signature = 'atriom:stability
        {--quick : only the fast tiers — what a pre-push hook runs}
        {--tier=* : run only these tiers}
        {--stamp : record the verdict for the current commit}
        {--json= : also write the machine-readable verdict here}';

    protected $description = 'One verdict over every check this project has — and an explicit list of what it could not verify';

    public function handle(): int
    {
        $this->components->info('Checking system stability'.($this->option('quick') ? ' (quick)' : ''));
        $this->newLine();

        $results = Stability::run(
            quick: (bool) $this->option('quick'),
            only: (array) $this->option('tier'),
            onTier: fn (string $key, array $r) => $this->reportTier($key, $r),
        );

        // **The verdict is about the CODE.** The install is a separate question with a separate
        // answer, reported beside it — see Stability::TIERS for why folding them together makes the
        // whole thing unreadable on a laptop.
        $verdict = Stability::verdict($results, Stability::CODE);

        $this->newLine();
        $this->renderVerdict($verdict, $results);
        $this->renderInstall($results);

        if ($this->option('json')) {
            file_put_contents((string) $this->option('json'), json_encode([
                'verdict' => $verdict,
                'commit' => self::head(),
                'at' => now()->toIso8601String(),
                'tiers' => $results,
            ], JSON_PRETTY_PRINT));
        }

        if ($this->option('stamp')) {
            $this->stamp($verdict, $results);
        }

        return match ($verdict) {
            Stability::PASS => self::SUCCESS,
            Stability::FAIL => self::FAILURE,
            default => 2,
        };
    }

    private function reportTier(string $key, array $result): void
    {
        $tier = Stability::TIERS[$key];

        [$icon, $colour] = match ($result['status']) {
            Stability::PASS => ['✓', 'green'],
            Stability::FAIL => ['✗', 'red'],
            default => ['?', 'yellow'],
        };

        $this->line(sprintf(
            '  <fg=%s>%s</> <options=bold>%-30s</> <fg=gray>%s</>',
            $colour,
            $icon,
            $tier['title'],
            $result['seconds'] > 0 ? $result['seconds'].'s' : '',
        ));

        $this->line('     <fg=gray>'.$result['detail'].'</>');
    }

    /**
     * The install's own answer, never folded into the code verdict.
     *
     * On a laptop this is routinely red for reasons that are true of a laptop — no queue worker, no
     * cron heartbeat, backups 560 hours old, no seller TRN in the dev database — and none of them
     * says anything about the code. Stated as its own question so it cannot drown the signal.
     */
    private function renderInstall(array $results): void
    {
        $install = array_intersect_key($results, array_filter(
            Stability::TIERS,
            fn (array $t): bool => $t['scope'] === Stability::INSTALL,
        ));

        if ($install === []) {
            return;
        }

        $verdict = Stability::verdict($results, Stability::INSTALL);

        $this->newLine();
        $this->line(match ($verdict) {
            Stability::PASS => '  <fg=green>OK  This install is set up and its books tie out.</>',
            Stability::FAIL => '  <fg=yellow>!   This INSTALL has problems — a separate question from the code above.</>',
            default => '  <fg=gray>?   This install was not checked.</>',
        });

        foreach ($install as $result) {
            $this->line('      <fg=gray>'.$result['detail'].'</>');
        }

        if ($verdict === Stability::FAIL) {
            $this->line('      <fg=gray>On a laptop this is the dev database. On the box it is the one that matters,</>');
            $this->line('      <fg=gray>and deploy.sh already runs it.</>');
        }
    }

    private function renderVerdict(string $verdict, array $results): void
    {
        $code = array_filter(
            $results,
            fn (string $k): bool => Stability::TIERS[$k]['scope'] === Stability::CODE,
            ARRAY_FILTER_USE_KEY,
        );

        $unverified = array_keys(array_filter($code, fn (array $r): bool => $r['status'] === Stability::NOT_VERIFIED));
        $failed = array_keys(array_filter($code, fn (array $r): bool => $r['status'] === Stability::FAIL));

        if ($verdict === Stability::PASS) {
            $this->components->info('PASS — every check ran, every one examined something, and the gates were shown to bite.');
            $this->line('  <fg=gray>That is not proof the system is correct. It is proof that every check we have was run.</>');

            return;
        }

        if ($verdict === Stability::FAIL) {
            $this->components->error('FAIL — '.implode(', ', array_map(fn ($k) => Stability::TIERS[$k]['title'], $failed)));

            foreach ($failed as $key) {
                $this->line('  <fg=red>'.Stability::TIERS[$key]['title'].'</>: '.$results[$key]['detail']);
                $this->line('     <fg=gray>'.Stability::TIERS[$key]['why'].'</>');
            }

            return;
        }

        // The state that matters most, and the one a two-state tool would have hidden.
        $this->components->warn('INCOMPLETE — nothing broke, and this was NOT checked:');

        foreach ($unverified as $key) {
            $this->line('  <fg=yellow>'.Stability::TIERS[$key]['title'].'</>: '.$results[$key]['detail']);
            $this->line('     <fg=gray>'.Stability::TIERS[$key]['why'].'</>');
        }

        $this->newLine();
        $this->line('  <fg=gray>Incomplete is not the same as fine. It is the honest answer for a laptop run,</>');
        $this->line('  <fg=gray>and the reason a release path must not treat exit 2 as a pass.</>');
    }

    /**
     * Record the verdict against the current commit, as a git NOTE.
     *
     * A note travels with `git push origin refs/notes/atriom-stability` and adds no churn to the
     * tree, which a committed stamp file would — and a stamp nobody can see from the box is not a
     * gate, it is a local diary.
     */
    private function stamp(string $verdict, array $results): void
    {
        $ran = array_keys(array_filter($results, fn (array $r): bool => $r['status'] !== Stability::NOT_VERIFIED));

        $payload = json_encode([
            'verdict' => $verdict,
            'tiers_run' => $ran,
            'at' => now()->toIso8601String(),
        ]);

        exec('git notes --ref=atriom-stability add -f -m '.escapeshellarg((string) $payload).' HEAD 2>&1', $out, $status);

        $status === 0
            ? $this->components->info('Stamped '.substr(self::head(), 0, 8).' as '.strtoupper($verdict).'.')
            : $this->components->warn('Could not stamp the commit: '.implode(' ', $out));
    }

    private static function head(): string
    {
        exec('git rev-parse HEAD 2>/dev/null', $out);

        return trim($out[0] ?? '');
    }
}
