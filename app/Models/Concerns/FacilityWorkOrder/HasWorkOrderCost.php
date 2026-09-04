<?php

namespace App\Models\Concerns\FacilityWorkOrder;

use App\Models\Expense;
use App\Models\FacilityWorkOrderLabour;
use App\Models\VendorBill;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;

/**
 * **The work order as a COST OBJECT** (Maximo §4) — what this job cost, in three buckets.
 *
 * `recomputeCosts()` is the single source of truth, written the way `Invoice::recomputeTotals()` is:
 * several independent channels change the number, so exactly one method computes it and every
 * channel calls it. Never set an `act_*` column anywhere else.
 *
 * **This posts NOTHING.** The money is already in the ledger through `StockMovement`,
 * `VendorBill`/`Expense` and `Payroll`; these columns are a management dimension over posted money,
 * and a journalizer here would post every maintenance cost twice AND BALANCED.
 * `WorkOrderIsACostObjectNotAGlSourceTest` fails the build on it.
 *
 * Extracted from `FacilityWorkOrder` on 2026-08-20 — see {@see TracksPmCompliance} for the reason.
 */
trait HasWorkOrderCost
{
    /** Laravel calls this automatically for a trait named like the method. */
    public static function bootHasWorkOrderCost(): void
    {
        // The planned total is a function of its three parts, so it is derived on EVERY save.
        // `recomputeCosts()` is called by the COST channels — labour, parts, bills — and none of
        // them touches an estimate, so without this an operator editing `est_service_cost` left
        // the stored total at its previous value and `costVariance()` reported against a stale
        // figure. `saveQuietly()` does not fire this, which is exactly right: the recompute path
        // calls the derivation directly and cannot loop.
        static::saving(fn (self $order) => $order->deriveEstimatedTotal());
    }

    /** Hours reported against this job. {@see FacilityWorkOrderLabour} */
    public function labour(): HasMany
    {
        return $this->hasMany(FacilityWorkOrderLabour::class);
    }

    /** Contractor invoices raised against this job — the service bucket. */
    public function vendorBills(): HasMany
    {
        return $this->hasMany(VendorBill::class);
    }

    /** Direct/petty-cash costs booked to this job — the service bucket's other road. */
    public function expenses(): HasMany
    {
        return $this->hasMany(Expense::class);
    }

    /**
     * **The single source of truth for what this job cost.**
     *
     * Written the way `Invoice::recomputeTotals()` is, and for the same reason: several independent
     * channels change the number, so exactly one method may compute it and every channel calls it.
     * Never set an `act_*` column anywhere else.
     *
     * THREE CHANNELS, and adding a fourth means adding it here AND wiring its model events:
     *
     *   labour   — `facility_work_order_labour`, hours x the craft rate frozen at entry
     *   material — part draws the STOCK LEDGER says really moved (`FacilityWorkOrderPart::counted`)
     *   service  — vendor bills + expenses that POST (`VendorBill::postable`, `Expense::postable`)
     *
     * **Each channel's "does this row count" is the CHILD's question**, answered by the child's own
     * scope rather than restated here — the three clauses had drifted into three second opinions,
     * two of them wrong. See the comments in the method body for what each one cost.
     *
     * **NET of tax, and net of any SLA penalty applied to the bill.** VAT is recoverable and is not
     * a cost of the job; a penalty credited against a contractor's invoice genuinely reduces what
     * the work cost us, and `SlaPenaltyJournalizer` already credits the same expense account, so
     * taking it off here keeps this figure and the ledger telling the same story.
     *
     * **A cancelled document costs nothing** — excluded, exactly as `VendorBill::recompute()`
     * excludes a voided payment.
     *
     * This posts NOTHING. See the migration docblock: the money is already in the ledger through
     * three other documents, and these columns are a management dimension over it.
     */
    public function recomputeCosts(): void
    {
        // ── ONE STATEMENT, because a read-modify-write here loses money (SW-212) ───────────────
        //
        // This used to be four plain aggregates followed by `saveQuietly()`. Two writers on one job
        // — a vendor-bill payment and an SLA penalty application, which really do land together —
        // each computed from their own snapshot and the last one won, so a job's cost silently
        // dropped a bill or a penalty.
        //
        // **A `lockForUpdate()` on the work order does NOT fix it.** Under MySQL's REPEATABLE READ
        // the waiter's consistent-read snapshot was fixed at its FIRST read, so it re-reads the
        // children from before it waited — the F-09 finding, and the reason
        // `Unit::isActivelyLeasedForUpdate()` exists beside its plain twin. Making the four child
        // aggregates locking reads does fix it and introduces a **deadlock**: `VendorBill::saved`
        // already holds the bill row and would then want the work order, while a labour write holds
        // the work order and wants the bills. (Measured — and measured to be present in THIS design
        // too: two transactions that each insert a child take a shared FK lock on the parent and
        // then deadlock on the S→X upgrade. So the deadlock is a reason not to prefer locking child
        // reads, not a property that distinguishes the two designs.)
        //
        // So the aggregates are computed INSIDE the update, where MySQL documents a SELECT within a
        // DML statement as reading like READ COMMITTED — measured: after another transaction
        // committed +2,000, the plain aggregate read 3,000 and the same aggregate inside an UPDATE
        // read 5,000. That is the whole of the fix.
        //
        // **The sub-selects are compiled from the RELATIONS, never hand-written.** All four children
        // soft-delete, and hand-written SQL would quietly count trashed rows — a second definition
        // of "what this job cost", which is the one thing this method exists to prevent. Laravel
        // compiles them per driver, so MySQL and SQLite get their own dialect from one source.
        [$labourHours, $b1] = $this->costAggregate($this->labour(), 'coalesce(sum(hours), 0)');
        [$labourCost, $b2] = $this->costAggregate($this->labour(), 'coalesce(sum(cost), 0)');

        // ── WHICH CHILD ROWS COUNT IS THE CHILD'S OWN QUESTION, NOT THIS METHOD'S ─────────────
        //
        // All three clauses used to be written out here, and two of them had drifted from the
        // definition the child model already owned — a second truth about the same money, which is
        // the one thing this method exists to prevent.
        //
        //   parts   — `counted()` is the scope `partsCost()` reads and the one module 26 documents
        //             ("a voided draw's movement → `counted()` stops charging the job"). It follows
        //             the STOCK LEDGER: an approved draw counts only while its movement is live,
        //             because voiding puts the stock back on the shelf. The clause here asked only
        //             for the STATUS, so the stored column went on charging a job for parts that
        //             had been returned while `partsCost()` beside it said zero (SW-071).
        //   bills   — `postable()` is `VendorBill`'s own "has this any GL effect at all", i.e. NOT
        //             `draft` and NOT `cancelled`. `vendor_bills.status` DEFAULTS to `draft`
        //             (measured on the live schema: `show columns from vendor_bills like 'status'`
        //             → Default: draft), so a bill keyed and not yet approved was counted as ACTUAL
        //             cost the moment it was saved — money the ledger has never seen, on a column
        //             whose whole premise is that it is a management dimension OVER posted money.
        //             `AssessSlaPenaltyService::jobValue()` reads it, so a percent-of-value SLA
        //             penalty was priced off an invoice nobody had approved, and `assess()` freezes
        //             a penalty once the job goes terminal — so approving the bill later never
        //             re-priced it (SW-072).
        //   expenses — `postable()` too. `expenses.status` holds only `recorded|cancelled` today,
        //             so `!= cancelled` was right by coincidence; an ALLOWLIST is what keeps it
        //             right when a third status arrives, and it errs toward not counting money the
        //             books have not recognised.
        //
        // A `pending` part request is a proposal and a `rejected` one never happened — both are
        // outside `counted()` for that reason.
        [$material, $b3] = $this->costAggregate(
            $this->parts()->counted(),
            'coalesce(sum(value), 0)',
        );

        [$bills, $b4] = $this->costAggregate(
            $this->vendorBills()->postable(),
            'coalesce(sum(subtotal - coalesce(penalty_applied_amount, 0)), 0)',
        );

        [$expenses, $b5] = $this->costAggregate(
            $this->expenses()->postable(),
            'coalesce(sum(amount), 0)',
        );

        // The estimate stays in PHP, where its null semantics survive: "nobody estimated anything"
        // is not zero, and `coalesce` in SQL would flatten the two together.
        //
        // **And it is written ONLY when this instance actually moved it.** Writing it every time
        // reintroduced the very lost update this method exists to remove, one column across:
        // measured, an operator saving `est_service_cost = 900` mid-flight had their 1,000 replaced
        // by a 100 read from a snapshot fixed before they typed it. `find()` inside a transaction
        // reads from that transaction's snapshot, which can be minutes old, so the window is not
        // microseconds. The old `saveQuietly()` was safe here by accident — an unchanged column is
        // not dirty, so it never reached the SET list — and that accident is now the rule.
        $this->deriveEstimatedTotal();
        $estimateMoved = $this->isDirty('est_total_cost');

        // `act_total_cost` repeats its three terms rather than referring to the columns beside it:
        // **MySQL evaluates SET assignments left to right and lets a later one read an earlier one;
        // SQLite reads the ORIGINAL row throughout.** A total written as
        // `act_labour_cost + act_material_cost + act_service_cost` would therefore be right on the
        // real database and one recompute behind in every test.
        $connection = $this->getConnection();
        $grammar = $connection->getQueryGrammar();
        $table = $grammar->wrapTable($this->getTable());
        $col = fn (string $name): string => $grammar->wrap($name);

        $connection->update(
            "update {$table} set "
            .$col('act_labour_hours')." = round({$labourHours}, 2), "
            .$col('act_labour_cost')." = round({$labourCost}, 2), "
            .$col('act_material_cost')." = round({$material}, 2), "
            .$col('act_service_cost')." = round(({$bills}) + ({$expenses}), 2), "
            .$col('act_total_cost')." = round(({$labourCost}) + ({$material}) + ({$bills}) + ({$expenses}), 2), "
            .($estimateMoved ? $col('est_total_cost').' = ?, ' : '')
            .$col($this->getUpdatedAtColumn()).' = ? '
            .'where '.$col($this->getKeyName()).' = ?',
            [
                ...$b1, ...$b2, ...$b3, ...$b4, ...$b5,        // the SET expressions, in order
                ...$b2, ...$b3, ...$b4, ...$b5,                // …and again for the total
                ...($estimateMoved ? [$this->est_total_cost] : []),
                $this->freshTimestampString(),
                $this->getKey(),
            ],
        );

        // The row is now authoritative and this instance is not, so the derived columns are refilled
        // to match what `saveQuietly()` used to leave in memory. (No caller in `app/`, `database/`
        // or `tests/` actually reads one off the instance — every one re-queries — so this is for
        // parity, not for a consumer.)
        //
        // **`syncOriginalAttributes($those)`, never `syncOriginal()`.** The bare form syncs the
        // WHOLE attribute array, so a caller holding an unsaved edit had it silently marked clean:
        // measured, `notes` set in memory read `isDirty() === false` afterwards and the next
        // `save()` wrote nothing. The value stays on the model, which is what makes it invisible.
        $derived = (array) $this->newQueryWithoutScopes()
            ->whereKey($this->getKey())
            ->first(['act_labour_hours', 'act_labour_cost', 'act_material_cost', 'act_service_cost', 'act_total_cost'])
            ?->getAttributes();

        if ($derived !== []) {
            $this->forceFill($derived)->syncOriginalAttributes(array_keys($derived));
        }
    }

    /**
     * One child aggregate, as SQL + bindings, taken from the RELATION so its scopes travel.
     *
     * @param  \Illuminate\Database\Eloquent\Relations\Relation<*, *, *>  $relation
     * @return array{0: string, 1: array<int, mixed>}
     */
    private function costAggregate($relation, string $expression): array
    {
        $query = $relation->getQuery()->select(DB::raw($expression));

        return ['('.$query->toSql().')', $query->getBindings()];
    }

    /**
     * The planned total, from its parts.
     *
     * Derived for the same reason the actual one is: an operator who estimated two of three buckets
     * should not also have to add them up — and a stored total nothing re-derives is a second truth
     * about the same money.
     *
     * **Called from `saving` as well as from `recomputeCosts()`, and that is the whole point.** The
     * cost channels are what call `recomputeCosts()`, and none of them touches an estimate — so
     * editing `est_service_cost` on the form left `est_total_cost` at whatever it had been, and
     * `costVariance()` (the number an operator acts on) was computed from the stale figure.
     * Measured on the live database, not theorised.
     */
    private function deriveEstimatedTotal(): void
    {
        $stated = array_filter(
            [$this->est_labour_cost, $this->est_material_cost, $this->est_service_cost],
            fn ($v) => $v !== null,
        );

        $this->est_total_cost = $stated === []
            ? null                                   // nobody estimated anything; NOT zero
            : round(array_sum(array_map('floatval', $stated)), 2);
    }

    /**
     * Planned minus actual on the total, or null when nothing was planned.
     *
     * The number an operator can act on: a job estimated at 4 hours that consumed 14 is the
     * finding, and one showing only "14" is a figure nobody can do anything with.
     */
    public function costVariance(): ?float
    {
        return $this->est_total_cost === null
            ? null
            : round((float) $this->est_total_cost - (float) $this->act_total_cost, 2);
    }
}
