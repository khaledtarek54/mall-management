<?php

namespace App\Console\Commands;

use App\Services\Reconciliation\BooksReconciliationService;
use Illuminate\Console\Command;

/**
 * `php artisan billing:reconcile [--month=YYYY-MM]`
 *
 * Read-only audit that confirms the AR books tie out (stored totals match what
 * the source records imply). Exits non-zero if any check fails, so it can gate a
 * monthly close in CI or a pre-filing step.
 */
class ReconcileBooksCommand extends Command
{
    protected $signature = 'billing:reconcile
        {--month= : Optional YYYY-MM to scope to invoices issued that month}
        {--deep : Also verify every posting document ledger entry matches its current state (all-time; slower)}';

    protected $description = 'Independently re-derive the AR books and confirm stored totals tie out (read-only)';

    public function handle(BooksReconciliationService $service): int
    {
        $report = $service->run($this->option('month'), (bool) $this->option('deep'));

        $this->newLine();
        $this->info("Books reconciliation — period: {$report['period']}");
        $this->newLine();

        $this->table(['', 'Check', 'Result'], array_map(fn ($c) => [
            $c['passed'] ? '✓' : '✗',
            $c['label'],
            $c['passed'] ? 'OK' : count($c['discrepancies']).' discrepancy(ies)',
        ], $report['checks']));

        $t = $report['controlTotals'];
        $this->newLine();
        $this->line('Control totals (reconcile these against your books):');
        $this->table(['Metric', 'Value (EGP)'], [
            ['Invoices', $t['invoiceCount']],
            ['Total invoiced', number_format($t['invoiced'], 2)],
            ['Collected (paid)', number_format($t['collected'], 2)],
            ['Credits applied', number_format($t['creditApplied'], 2)],
            ['Outstanding AR (tenant ledger)', number_format($t['outstandingAR'], 2)],
            // ── THE TABLE MUST RECONCILE ON ITS FACE (2026-08-25) ─────────────────────────────
            //
            // The service computes `creditOutstanding` and `netAR` and says in writing why: "so the
            // accountant doesn't read AR gross". This table then printed the gross figure and threw
            // both away — under a heading that says "reconcile these against your books".
            //
            // Measured on the seeded portfolio: the table offered 1,481,825.54 while the AR control
            // account held 1,477,325.54. The 4,500 is two unapplied credit notes, which correctly
            // credited AR when they were ISSUED (Yardi posts a credit memo the same way) and are
            // still standing against the tenant. Both numbers were right; nothing on the page said
            // how they related, so the one figure an accountant is invited to tie out could not be
            // tied out. Showing the reconciling item is what an AR-to-GL reconciliation IS.
            ['  less unapplied credit notes', $t['creditOutstanding'] > 0
                ? '('.number_format($t['creditOutstanding'], 2).')'
                : number_format(0, 2)],
            ['= Net AR (should equal the GL)', number_format($t['netAR'], 2)],
            ['VAT total', number_format($t['vatTotal'], 2)],
        ]);

        $failed = collect($report['checks'])->reject(fn ($c) => $c['passed']);
        if ($failed->isNotEmpty()) {
            $this->newLine();
            $this->error('Discrepancies:');
            foreach ($failed as $c) {
                $this->line("  [{$c['key']}] {$c['label']}");
                foreach (array_slice($c['discrepancies'], 0, 20) as $disc) {
                    $this->line("    - {$disc['ref']}: {$disc['detail']}");
                }
                if (count($c['discrepancies']) > 20) {
                    $this->line('    … '.(count($c['discrepancies']) - 20).' more');
                }
            }
            $this->newLine();
            $this->error('✗ Books do NOT tie out.');

            return self::FAILURE;
        }

        $this->newLine();
        $this->info('✓ Books tie out — all checks passed.');

        return self::SUCCESS;
    }
}
