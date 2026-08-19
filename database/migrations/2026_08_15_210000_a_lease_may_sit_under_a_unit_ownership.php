<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A lease may sit UNDER a unit ownership — the owner's own tenant.
 *
 * This is Yardi's construct, not a new one: in Voyager Condo/Co-Op & HOA, when an owner lets his
 * unit out, the lessee is recorded as a **sub-record under the owner's unit**. The lessee exists for
 * access, contact, violations, SLA and occupancy — and **the owner remains liable for the
 * assessments**. Owner of record ≠ occupant of record.
 *
 * One nullable column, and deliberately nothing else.
 *
 * ## What was nearly built here, and why it is not
 *
 * The plan for this phase called for a second column, `leases.revenue_mode`
 * (`operator_collects` / `owner_collects`), so the billing engine could skip a lease whose rent the
 * owner collects himself. **Yardi has no such flag, and the codebase does not need one:**
 *
 *  - Whether the operator collects the rent is a term of the MANAGEMENT AGREEMENT, which this system
 *    already records as `unit_ownerships.management_mode`. A flag on the lease would be a second
 *    place to state one fact — and the two would eventually disagree.
 *  - `MonthlyBillingService` returns early when a lease has no active charge row
 *    (`MonthlyBillingService::planInvoiceForLease`, the `$applicableCharges->isEmpty()` branch), so a
 *    lease we do not bill rent on **already bills nothing**. A `revenue_mode` predicate in the
 *    billing path would have been dead code — the inert-configuration mistake this module has
 *    already avoided twice.
 *
 * So the link is the whole change. What follows from it is read from the ownership.
 *
 * @see docs/modules/37-unit-owners.md (corrected)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('leases', function (Blueprint $table) {
            // restrictOnDelete, matching every other reference to an ownership: a holding that a
            // tenancy hangs off is not something anyone may remove out from under it.
            $table->foreignId('unit_ownership_id')->nullable()->after('unit_id')->constrained()->restrictOnDelete();
            $table->index('unit_ownership_id');
        });
    }

    public function down(): void
    {
        Schema::table('leases', function (Blueprint $table) {
            $table->dropConstrainedForeignId('unit_ownership_id');
        });
    }
};
