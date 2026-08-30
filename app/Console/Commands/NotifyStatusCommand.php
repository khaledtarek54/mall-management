<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Support\Discord;
use App\Support\Health;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

/**
 * Post to Discord when the box's health CHANGES — never on every run.
 *
 * ## Why change-detection is the whole design
 *
 * `atriom:health` is degraded on a correct staging box by design: `two_factor`, `demo_accounts`
 * and `backup_capability` are all expected red on posture A ([STAGING.md §5]). A command that
 * posted whenever health was not green would fire every run, for ever, about nothing — and
 * `STAGING.md` already states the consequence in the context of uptime monitors: *a monitor that
 * is always red is a monitor nobody reads, including the production one beside it*. Alert fatigue
 * would not merely make this useless, it would make the box less observable than having no alert
 * at all.
 *
 * So the unit of news is the SET of failing checks, and only a change to that set is worth
 * anyone's attention. A box that has been degraded the same way for a month is silent.
 *
 * ## Recoveries are news too
 *
 * Reporting only breakage teaches people that silence means broken. Both directions are posted,
 * so silence means "the same as when you last heard from me", which is a statement someone can
 * actually rely on.
 *
 * ## The state file
 *
 * A file, not the cache: a Redis flush would make every check look newly broken and post a wall
 * of false alarms. It sits beside the scheduler heartbeat and is git-ignored for the same reason
 * that one had to be — an untracked file under `storage/` makes the working tree dirty, and
 * `deploy.sh` refuses a dirty tree, so an un-ignored state file silently blocks every release.
 * That is not hypothetical: it happened with the heartbeat on 2026-08-30.
 *
 * First run on a box posts once, whatever the state, so the operator learns the alerting works
 * rather than inferring it from a silence that is indistinguishable from a broken webhook.
 */
class NotifyStatusCommand extends Command
{
    protected $signature = 'atriom:notify-status {--force : post the current state even if nothing changed}';

    protected $description = 'Post to Discord when the health check\'s set of failing rows changes.';

    public static function statePath(): string
    {
        return storage_path('framework/status-notified.json');
    }

    public function handle(): int
    {
        if (! Discord::enabled()) {
            $this->info('No DISCORD_WEBHOOK_URL configured — nothing to notify.');

            return self::SUCCESS;
        }

        $health = Health::run();

        /** @var array<string, array{ok: bool, detail?: string}> $checks */
        $checks = $health['checks'] ?? [];

        $failing = collect($checks)
            ->reject(fn (array $c): bool => (bool) ($c['ok'] ?? false))
            ->keys()
            ->sort()
            ->values()
            ->all();

        $previous = $this->previouslyReported();
        $first = $previous === null;

        if (! $first && $previous === $failing && ! $this->option('force')) {
            $this->info('No change since the last notification ('.count($failing).' failing).');

            return self::SUCCESS;
        }

        $broke = array_values(array_diff($failing, $previous ?? []));
        $fixed = array_values(array_diff($previous ?? [], $failing));

        $lines = [];

        foreach ($broke as $name) {
            $lines[] = '🔴 **'.$name.'** — '.($checks[$name]['detail'] ?? 'failing');
        }

        foreach ($fixed as $name) {
            $lines[] = '🟢 **'.$name.'** — recovered';
        }

        if ($first) {
            $lines[] = $failing === []
                ? '🟢 All checks passing.'
                : 'Currently failing: '.implode(', ', $failing);
        }

        $lines[] = '';
        $lines[] = $failing === []
            ? 'Overall: **healthy**'
            : 'Overall: **degraded** ('.count($failing).' of '.count($checks).' checks failing)';

        $title = $first
            ? 'Status notifications are live'
            : ($broke !== [] ? 'Health degraded' : 'Health recovered');

        $colour = $failing === []
            ? Discord::GREEN
            : ($broke !== [] ? Discord::RED : Discord::AMBER);

        if (Discord::send($title, $lines, $colour)) {
            $this->remember($failing);
            $this->info('Posted: '.$title);

            return self::SUCCESS;
        }

        // Deliberately do NOT remember on a failed post — the next run must retry rather than
        // silently treat an undelivered change as delivered.
        $this->error('Discord did not accept the notification; state not advanced.');

        return self::FAILURE;
    }

    /** @return array<int, string>|null null means "never reported on this box" */
    private function previouslyReported(): ?array
    {
        if (! File::exists(self::statePath())) {
            return null;
        }

        $decoded = json_decode((string) File::get(self::statePath()), true);

        return is_array($decoded) ? array_values(array_filter($decoded, 'is_string')) : null;
    }

    /** @param array<int, string> $failing */
    private function remember(array $failing): void
    {
        File::ensureDirectoryExists(dirname(self::statePath()));
        File::put(self::statePath(), json_encode($failing));
    }
}
