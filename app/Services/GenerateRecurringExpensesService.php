<?php

namespace App\Services;

use App\Models\Expense;
use App\Models\RecurringExpense;
use App\Models\VendorBill;
use App\Support\CatalogueTaxRate;
use App\Support\OpsLog;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Book the costs that come round every period (EG-33 / T-8).
 *
 * There was no recurring-expense concept anywhere in this system; recurrence existed only on the
 * revenue side. This is the counterpart to `MonthlyBillingService` for money going OUT — Yardi's
 * Recurring Payables.
 *
 * ## It creates documents; it posts nothing itself
 *
 * {@see Expense} and {@see VendorBill} are both already registered GL sources, so the cost reaches
 * the ledger through the journalizers that exist. Registering this service's schedule as a source
 * would post every levy twice and balance both times.
 *
 * ## TWO kinds of standing cost, and the vendor is what tells them apart
 *
 * A real-estate tax assessment or a government levy is money leaving with **no creditor** — it
 * mints an `Expense`, `recorded`, which posts. A fixed cleaning retainer or a lift-maintenance
 * contract is a **payable owed to a named supplier** — it mints a `VendorBill`. `expenses` carries
 * no `vendor_id` at all, so naming a vendor on the schedule IS the statement that this is a
 * payable, and there is no second `type` column that could disagree with it.
 *
 * **The supplier bill is a DRAFT and the expense is not**, which is the one asymmetry worth
 * stating. `vendor_bills.reference` is the SUPPLIER's invoice number, unique per vendor, and
 * cannot be invented; and posting `Dr Expense / Cr AP` for an invoice nobody sent is the system
 * inventing a creditor's claim. A statutory levy has no counterparty document to wait for. Yardi
 * stages its recurring payable batch and has a person post it, for the same reason. What the
 * schedule removes either way is the RE-TYPING.
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
     * @return array{generated: int, skipped: int, expenses: array<int, Expense>, bills: array<int, VendorBill>, failures: array<int, string>}
     */
    public function generate(?CarbonImmutable $on = null, ?int $assetId = null): array
    {
        $on ??= CarbonImmutable::now()->startOfDay();

        $schedules = RecurringExpense::query()
            ->active()
            ->when($assetId !== null, fn ($q) => $q->where('asset_id', $assetId))
            ->orderBy('id')
            ->pluck('id');

        $expenses = [];
        $bills = [];
        $skipped = 0;
        $failures = [];

        foreach ($schedules as $id) {
            // PER SCHEDULE, because one schedule's refusal must not silence the others. Without this
            // the run was one poison row away from booking nothing at all: a schedule whose oldest
            // outstanding period falls in a CLOSED accounting period throws from the posting-date
            // guard — natural for a levy entered with a historical `starts_on`, or one switched back
            // on after months — and the throw propagated out of this loop, so every schedule with a
            // higher id was skipped, silently, every night, for ever (the stamp never advances, so
            // it fails identically on the next run). A statutory cost that never books is exactly
            // what EG-33 exists to prevent.
            try {
                $document = $this->generateOne($id, $on);
            } catch (\Throwable $e) {
                $failures[$id] = $e->getMessage();

                continue;
            }

            match (true) {
                $document instanceof Expense => $expenses[] = $document,
                $document instanceof VendorBill => $bills[] = $document,
                default => $skipped++,
            };
        }

        if ($failures !== []) {
            // Reported, not swallowed: a caught exception that nobody is told about is the same
            // silent-missed-cost failure in a quieter coat. The command surfaces this in its output
            // and exits non-zero; the ledger-sync sweep uses the same shape.
            OpsLog::error('recurring-expenses: schedules refused', [
                'count' => count($failures),
                'failures' => $failures,
            ]);
        }

        return [
            'generated' => count($expenses) + count($bills),
            'skipped' => $skipped,
            'expenses' => $expenses,
            'bills' => $bills,
            'failures' => $failures,
        ];
    }

    /** One schedule, under its own lock, so a slow property does not hold up the rest. */
    private function generateOne(int $scheduleId, CarbonImmutable $on): Expense|VendorBill|null
    {
        return DB::transaction(function () use ($scheduleId, $on): Expense|VendorBill|null {
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

            $document = $schedule->billsAVendor()
                ? $this->raiseVendorBill($schedule, $due)
                : $this->recordExpense($schedule, $due);

            // Stamped only after the document exists, and inside the same transaction — a stamp
            // written first would skip a period if the insert then failed.
            $schedule->forceFill(['last_generated_on' => $due])->save();

            return $document;
        });
    }

    /**
     * The input tax on a period's net, resolved for the DOCUMENT's own date.
     *
     * **`recurring_expenses.tax_code` was offered on the form and read by nothing** — the schedule
     * stored the accountant's ruling and both documents were minted with zero tax, so a levy under
     * `VAT_14` booked no recoverable input VAT at all and the code sat on the row explaining a
     * figure that was never derived from it. The inert-field shape this codebase keeps finding.
     *
     * `deriveOnNet()` is the same seam the vendor-bill and expense FORMS use, resolved for the
     * document's date rather than today, so a rate rise entered in advance reaches a schedule by
     * itself and a back-dated period keeps the rate that was in force. Null — no code, or no rate
     * on that date — means zero, which is exactly what an unclassified cost meant before.
     */
    private function taxOn(RecurringExpense $schedule, CarbonImmutable $due): float
    {
        return CatalogueTaxRate::deriveOnNet(
            $schedule->tax_code,
            (float) $schedule->amount,
            $due->toDateString(),
        ) ?? 0.0;
    }

    /** A cost with no creditor: money leaving, posted the moment it is recorded. */
    private function recordExpense(RecurringExpense $schedule, CarbonImmutable $due): Expense
    {
        return Expense::create([
            'asset_id' => $schedule->asset_id,
            'recurring_expense_id' => $schedule->id,
            'category' => $schedule->category,
            'description' => $schedule->description,
            'amount' => $schedule->amount,
            'vat_amount' => $this->taxOn($schedule, $due),
            'tax_code' => $schedule->tax_code,
            'expense_date' => $due,
            // `recorded`, which is what posts it to the ledger. That is the point of the schedule:
            // a statutory cost with a known amount and date books itself, exactly as Yardi's
            // recurring payables do. `expenses.status` accepts only recorded|cancelled anyway —
            // the ValueSets listener refuses anything else on save.
            'status' => 'recorded',
        ]);
    }

    /**
     * A payable owed to a named supplier, staged as a DRAFT for the invoice to arrive against.
     *
     * `reference` is deliberately left EMPTY rather than filled with something invented: it is the
     * supplier's own invoice number, it is unique per vendor, and a generated placeholder there
     * would collide with the real one the day it is entered.
     */
    private function raiseVendorBill(RecurringExpense $schedule, CarbonImmutable $due): VendorBill
    {
        return VendorBill::create([
            'asset_id' => $schedule->asset_id,
            'vendor_id' => $schedule->vendor_id,
            'vendor_contract_id' => $schedule->vendor_contract_id,
            'recurring_expense_id' => $schedule->id,
            'category' => $schedule->category,
            'description' => $schedule->description,
            // `subtotal`, not `amount` — the AP document names its net differently from the
            // expense one, and the model derives `total` from subtotal + VAT on every write.
            'subtotal' => $schedule->amount,
            'vat_amount' => $this->taxOn($schedule, $due),
            'tax_code' => $schedule->tax_code,
            'bill_date' => $due,
            'due_date' => $schedule->dueOn($due),
            'status' => 'draft',
        ]);
    }
}
