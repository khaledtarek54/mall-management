<?php

namespace App\Console\Commands;

use App\Models\Lease;
use App\Models\TenantSalesDeclaration;
use App\Support\OpsLog;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Raise an ESTIMATED sales declaration for a percentage-rent tenant who never declared.
 *
 * **The leak this closes.** `sales:scan-missing-declarations` chases the tenant and stops there.
 * Nothing bills, so a tenant who simply never files pays no percentage rent at all — silence is a
 * complete and costless way to avoid the charge. Yardi bills an estimate and retro-bills the true
 * figure when it arrives; this is that, minus the retro-billing, which the existing lock/void
 * re-truing already provides.
 *
 * **The estimate is the tenant's own trailing average**, not a landlord guess: the mean of their
 * last three locked declarations. That is defensible to a tenant, self-correcting as they trade,
 * and — critically — it is superseded the moment they file, because a real declaration for the
 * period replaces the estimate rather than adding to it.
 *
 * Deliberately NOT locked. An estimate is a prompt for a decision, not a fact: locking it would
 * bill the tenant on a number nobody has agreed. The operator reviews and locks, which is the same
 * gate every other percentage-rent charge passes through.
 */
class EstimateMissingSalesCommand extends Command
{
    protected $signature = 'sales:estimate-missing
        {--period= : First-of-month YYYY-MM-01 to estimate; defaults to the previous month}
        {--min-history=2 : Minimum locked declarations required before an estimate is defensible}
        {--dry-run : Print what would be raised without writing}';

    protected $description = 'Raise estimated sales declarations for percentage-rent tenants who never declared (idempotent).';

    public function handle(): int
    {
        $periodStart = CarbonImmutable::parse($this->option('period') ?? CarbonImmutable::now()->subMonthNoOverflow()->toDateString())
            ->startOfMonth();
        $periodEnd = $periodStart->endOfMonth();
        $minHistory = (int) $this->option('min-history');
        $dryRun = (bool) $this->option('dry-run');

        // The same definition of "owes a declaration" the reminder scan and the month-end
        // checklist use — one rule, three callers.
        $leases = Lease::missingSalesDeclarationsFor($periodStart, $periodEnd);

        $raised = 0;
        $skipped = 0;

        foreach ($leases as $lease) {
            try {
                $history = TenantSalesDeclaration::query()
                    ->where('lease_id', $lease->id)
                    ->where('status', 'locked')
                    ->whereDate('period_start', '<', $periodStart->toDateString())
                    ->orderByDesc('period_start')
                    ->limit(3)
                    ->pluck('declared_sales');

                // Without history there is no defensible number. Inventing one would be inventing
                // data — the same rule that stops the escalation sweep guessing a CPI figure. The
                // tenant keeps being chased by the reminder scan instead.
                if ($history->count() < $minHistory) {
                    $skipped++;

                    continue;
                }

                $estimate = round((float) $history->avg(), 2);

                if ($estimate <= 0) {
                    $skipped++;

                    continue;
                }

                if ($dryRun) {
                    $this->line(sprintf('  would estimate %s · %s · %s',
                        $lease->reference, $periodStart->format('M Y'), number_format($estimate, 2)));
                    $raised++;

                    continue;
                }

                $this->raise($lease, $periodStart, $periodEnd, $estimate) ? $raised++ : $skipped++;
            } catch (Throwable $e) {
                OpsLog::error('sales_estimate.failed', ['lease_id' => $lease->id, 'error' => $e->getMessage()]);
                $skipped++;
            }
        }

        $this->info(($dryRun ? 'Would raise ' : 'Raised ')."{$raised} estimated declaration(s) for {$periodStart->format('F Y')}; {$skipped} skipped.");

        if ($dryRun) {
            $this->warn('Dry run — nothing was written.');
        }

        return self::SUCCESS;
    }

    private function raise(Lease $lease, CarbonImmutable $periodStart, CarbonImmutable $periodEnd, float $estimate): bool
    {
        return DB::transaction(function () use ($lease, $periodStart, $periodEnd, $estimate) {
            // Re-check inside the transaction: between the query above and here the tenant may
            // have filed, and their real figure must never be overwritten by an estimate.
            $exists = TenantSalesDeclaration::query()
                ->where('lease_id', $lease->id)
                ->whereDate('period_start', $periodStart->toDateString())
                ->lockForUpdate()
                ->exists();

            if ($exists) {
                return false;
            }

            TenantSalesDeclaration::create([
                'lease_id' => $lease->id,
                'period_start' => $periodStart->toDateString(),
                'period_end' => $periodEnd->toDateString(),
                'declared_sales' => $estimate,
                'is_estimate' => true,
                'declared_at' => now(),
                'status' => 'submitted',
                'audit_notes' => 'Estimated by the operator from the tenant\'s last '
                    .'declarations — no return was filed for this period.',
            ]);

            return true;
        });
    }
}
