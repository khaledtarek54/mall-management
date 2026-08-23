<?php

namespace App\Services;

use App\Models\Expense;
use App\Models\RecurringExpense;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Book the costs that come round every period (EG-33 / T-8).
 *
 * There was no recurring-expense concept anywhere in this system; recurrence existed only on the
 * revenue side. This is the counterpart to `MonthlyBillingService` for money going OUT — Yardi's
 * Recurring Payables.
 *
 * ## It creates expenses; it posts nothing itself
 *
 * An {@see Expense} is already a registered GL source, so the cost reaches the ledger through the
 * journalizer that exists. Registering this service's schedule as a source would post every levy
 * twice and balance both times.
 *
 * ## Generating a statutory cost twice is real money, so this is belt AND braces
 *
 * 1. The schedule row is taken with `lockForUpdate()`, so two workers cannot both decide a period
 *    is due.
 * 2. The due date is re-derived INSIDE the transaction, after the lock — a value read before the
 *    wait is answered from a snapshot taken before the other writer committed, which is the exact
 *    trap `Unit::isActivelyLeasedForUpdate()` exists for.
 * 3. `expenses.(recurring_expense_id, expense_date)` is UNIQUE, so even a bug here fails loudly
 *    rather than double-booking.
 *
 * ## It catches up one period per run, deliberately
 *
 * A schedule switched off for six months and switched back on must not mint six back-dated expenses
 * in one night — six journal entries into periods that may be closed, on a cost nobody re-reads. It
 * books the oldest outstanding period and the sweep asks again tomorrow, so a real gap closes at a
 * visible pace.
 */
class GenerateRecurringExpensesService
{
    /**
     * Generate whatever is due on or before `$on`.
     *
     * @return array{generated: int, skipped: int, expenses: array<int, Expense>}
     */
    public function generate(?CarbonImmutable $on = null, ?int $assetId = null): array
    {
        $on ??= CarbonImmutable::now()->startOfDay();

        $schedules = RecurringExpense::query()
            ->active()
            ->when($assetId !== null, fn ($q) => $q->where('asset_id', $assetId))
            ->orderBy('id')
            ->pluck('id');

        $generated = [];
        $skipped = 0;

        foreach ($schedules as $id) {
            $expense = $this->generateOne($id, $on);

            $expense === null ? $skipped++ : $generated[] = $expense;
        }

        return ['generated' => count($generated), 'skipped' => $skipped, 'expenses' => $generated];
    }

    /** One schedule, under its own lock, so a slow property does not hold up the rest. */
    private function generateOne(int $scheduleId, CarbonImmutable $on): ?Expense
    {
        return DB::transaction(function () use ($scheduleId, $on): ?Expense {
            /** @var RecurringExpense|null $schedule */
            $schedule = RecurringExpense::query()->whereKey($scheduleId)->lockForUpdate()->first();

            if ($schedule === null || ! $schedule->is_active) {
                return null;
            }

            // Re-derived AFTER the lock, never carried across it. Under MySQL's REPEATABLE READ a
            // plain read inside a transaction answers from the snapshot taken at its start, so a
            // due date computed before the wait would not see the other worker's commit.
            $due = $schedule->nextDueOn($on);

            if ($due === null) {
                return null;
            }

            $expense = Expense::create([
                'asset_id' => $schedule->asset_id,
                'recurring_expense_id' => $schedule->id,
                'category' => $schedule->category,
                'description' => $schedule->description,
                'amount' => $schedule->amount,
                'tax_code' => $schedule->tax_code,
                'expense_date' => $due,
                // `recorded`, which is what posts it to the ledger. That is the point of the
                // schedule: a statutory cost with a known amount and date books itself, exactly as
                // Yardi's recurring payables do. `expenses.status` accepts only recorded|cancelled
                // anyway — the ValueSets listener refuses anything else on save.
                'status' => 'recorded',
            ]);

            // Stamped only after the expense exists, and inside the same transaction — a stamp
            // written first would skip a period if the insert then failed.
            $schedule->forceFill(['last_generated_on' => $due])->save();

            return $expense;
        });
    }
}
