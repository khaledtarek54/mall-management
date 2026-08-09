<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Make every legacy charge row self-describing (story LS-06).
 *
 * **Behaviour is unchanged, which is the whole requirement.** `charges.start_date` has always been
 * nullable, and `MonthlyBillingService::chargeAppliesToPeriod()` has always read a null start as
 * "in force from the beginning" — so a row stamped with the lease commencement applies to exactly
 * the same months it applied to before. Billing cannot tell the difference, and the LS-06
 * equivalence test asserts that by billing the same month either way and comparing the invoices
 * line by line.
 *
 * **What it buys.** A schedule whose rows all carry dates can be ordered, compared and reasoned
 * about. Null sorts first on MySQL and last on SQLite, so "the row in force" and the rent roll's
 * "next step" could answer differently on the two databases we run — a difference that would show
 * up as a test that passes locally and a rent roll that is wrong in production.
 *
 * **`end_date` is deliberately left alone**, which is a deviation from the story's wording ("`to` =
 * expiry or existing `end_date`"). Stamping the lease expiry would stop a **holdover** lease
 * billing on the day its term ended — Atriom bills holdover from the same charge rows
 * (`ConvertLeaseToHoldoverService`), so a null end date is load-bearing, not an omission. An
 * open-ended row stays open-ended.
 *
 * `leases.commencement_date` is NOT NULL, so every charge has a date to inherit; the only row this
 * skips is one whose lease has gone.
 */
return new class extends Migration
{
    public function up(): void
    {
        // A correlated subquery, NOT `->join(...)->update(...)`: SQLite compiles a join-update to
        // `UPDATE charges SET … WHERE rowid IN (SELECT …)`, where the SET clause can no longer see
        // the joined table. The join form runs on MySQL and fails on SQLite — green on the
        // developer's machine, red in the test suite, and exactly the class of cross-database
        // difference this migration exists to remove.
        DB::table('charges')
            ->whereNull('start_date')
            // `whereExists` rather than a bare update: a charge whose lease has gone would
            // otherwise have NULL written back into it by the subquery.
            ->whereExists(fn ($q) => $q->from('leases')
                ->whereColumn('leases.id', 'charges.lease_id'))
            ->update([
                'start_date' => DB::raw('(select commencement_date from leases where leases.id = charges.lease_id)'),
            ]);
    }

    /**
     * Restore the shape `up()` created: an open-ended row starting on its lease's commencement.
     *
     * This cannot distinguish a row it stamped from one the schedule service legitimately created
     * with the same shape — nothing recorded which was which. That is harmless precisely because of
     * the claim above: for billing, a null start and a commencement-dated start are the same row.
     */
    public function down(): void
    {
        DB::table('charges')
            ->whereNull('end_date')
            ->whereExists(fn ($q) => $q->from('leases')
                ->whereColumn('leases.id', 'charges.lease_id')
                ->whereColumn('leases.commencement_date', 'charges.start_date'))
            ->update(['start_date' => null]);
    }
};
