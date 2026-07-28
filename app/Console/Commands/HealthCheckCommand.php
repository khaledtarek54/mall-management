<?php

namespace App\Console\Commands;

use App\Support\Health;
use Illuminate\Console\Command;

/**
 * The same checks as /health, from the CLI.
 *
 * Useful in the deploy runbook (verify a box before sending it traffic) and as a
 * cron-callable probe on hosts where an external monitor cannot reach the app.
 * Exits non-zero when anything is unhealthy, so it composes with `&&`.
 */
class HealthCheckCommand extends Command
{
    protected $signature = 'atriom:health';

    protected $description = 'Check the database, cache, queue, scheduler, backups and storage';

    public function handle(): int
    {
        $result = Health::run();

        $this->table(
            ['Check', 'Status', 'Detail'],
            collect($result['checks'])->map(fn (array $c, string $name): array => [
                $name,
                $c['ok'] ? 'OK' : 'FAIL',
                $c['detail'],
            ])->values()->all(),
        );

        if ($result['status'] === 'ok') {
            $this->info('Healthy.');

            return self::SUCCESS;
        }

        $this->error('Unhealthy — see the FAIL rows above.');

        return self::FAILURE;
    }
}
