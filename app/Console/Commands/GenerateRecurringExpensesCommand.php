<?php

namespace App\Console\Commands;

use App\Services\GenerateRecurringExpensesService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/**
 * Book the statutory and fixed costs due today (EG-33).
 *
 * Scheduled DAILY rather than monthly, for the reason `BillingDay` learned: the schedules do not
 * share a day. One property's real-estate tax instalment falls in March and September, another's
 * licence renews in July, a retainer runs on the 25th. A monthly run would have to pick one day and
 * be wrong for everyone else.
 *
 * A command that exists and is not scheduled is the failure this codebase has already shipped once —
 * `BillUnitOwnershipsService` was fully built, fully tested and billed nobody for the whole of
 * module 37's life because nothing called it.
 */
class GenerateRecurringExpensesCommand extends Command
{
    protected $signature = 'expenses:generate-recurring
                            {--date= : Generate as at this date instead of today}
                            {--asset= : Only this property}';

    protected $description = 'Create the expenses due from recurring cost schedules';

    public function handle(GenerateRecurringExpensesService $service): int
    {
        $on = $this->option('date')
            ? CarbonImmutable::parse($this->option('date'))->startOfDay()
            : CarbonImmutable::now()->startOfDay();

        $result = $service->generate($on, $this->option('asset') ? (int) $this->option('asset') : null);

        // Reported even when nothing was due: a silent no-op is indistinguishable from a schedule
        // that is not running at all, which is how a missing caller stays missing.
        $this->info("Recurring costs on {$on->toDateString()}: {$result['generated']} booked, {$result['skipped']} not due.");

        foreach ($result['expenses'] as $expense) {
            $this->line("  expense  {$expense->number} · {$expense->description} · {$expense->total}");
        }

        // Named separately because they are in a DIFFERENT state and need a different next action:
        // an expense is posted, a supplier bill is a draft waiting for the invoice to arrive
        // against it. One combined count would read as "12 costs booked" and hide that four of
        // them are sitting in the AP register unapproved.
        foreach ($result['bills'] as $bill) {
            $this->line("  bill     {$bill->number} · {$bill->vendor?->name} · {$bill->description} · {$bill->total} (draft)");
        }

        return self::SUCCESS;
    }
}
