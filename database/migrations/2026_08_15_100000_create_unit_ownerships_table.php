<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Unit ownership — the buyer (مالك وحدة) who bought a shop rather than renting one.
 *
 * A peer of `leases`, not a variant of it: a lease is a term with rent, an ownership is a tenure
 * without one. Both give a party rights over a unit and both raise AR, which is what
 * `App\Contracts\BillableAgreement` names.
 *
 * The owner is a `tenants` row — that table is the AR PARTY table, and `party_type` says which kind
 * of party. This is Yardi's own answer (its ledger belongs to a customer record, and in the condo
 * product the owner simply IS that record type), and it is what lets payments, credit notes,
 * deposits, cheques, ageing, the portal and the mobile API serve an owner without learning that
 * owners exist.
 *
 * @see docs/plans/08-unit-owners.md
 */
return new class extends Migration
{
    public function up(): void
    {
        // Which kind of party a `tenants` row is. Defaults to `retailer`, so every existing row is
        // correct without a backfill and nothing that queries tenants changes meaning.
        // A unit owner is frequently a PERSON, not a company, and the retailer identifiers (tax card,
        // commercial register) do not identify one. `tenants.national_id` already exists for exactly
        // that — the party record needed nothing added for an individual owner, only a way to say
        // which kind of party it is.
        Schema::table('tenants', function (Blueprint $table) {
            $table->string('party_type', 32)->default('retailer')->after('type')->index();
        });

        Schema::create('unit_ownerships', function (Blueprint $table) {
            $table->id();
            $table->string('reference')->unique();

            // Property isolation rides on asset_id directly rather than through unit.asset_id: an
            // ownership outlives the unit's remeasurement and is read by the billing sweep, where a
            // join per row is the N+1 the CAM path already had to fix.
            $table->foreignId('asset_id')->constrained()->restrictOnDelete();
            $table->foreignId('unit_id')->constrained()->restrictOnDelete();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();

            // §8 Q1 as a row. Billing is identical across all three — only the tenure bounds differ,
            // and started_at/ended_at already carry those. A usufruct (حق انتفاع) is legally a long
            // lease and a freehold (تمليك) is a sale, but the operator bills both the same صيانة.
            $table->string('tenure_type', 32)->default('freehold');

            $table->string('status', 32)->default('contracted');

            // Which of the four owner states this is — it decides who bills whom, not what is owed.
            $table->string('management_mode', 32)->default('vacant');

            // §8 Q2 as a row, the same shape as CamExpensePool::denominator_basis. `area` is today's
            // CAM behaviour, so a mixed owned/leased building reconciles the way it already does.
            $table->string('assessment_basis', 32)->default('area');

            // Co-owners (spouses, partners). Sums to 100 per unit per date; not enforced at the DB
            // because a mid-sale day legitimately has both segments open on the same date.
            $table->decimal('ownership_share_pct', 5, 2)->default(100);

            // Yardi's participation interest — read only when assessment_basis = participation.
            // Null falls back to area, which is why an unconfigured deed is not a zero share.
            $table->decimal('participation_pct', 7, 4)->nullable();

            // The sale paperwork.
            $table->string('purchase_contract_number')->nullable();
            $table->date('purchase_date')->nullable();
            $table->decimal('purchase_price', 14, 2)->nullable();
            $table->date('handover_date')->nullable();

            // Tenure — inclusive bounds, either side null = unbounded. A RESALE sets ended_at; it
            // never deletes the row, or every statement and invoice that quoted this ownership loses
            // its basis. Same rule as asset_owner, and the transfer service states it.
            $table->date('started_at')->nullable();
            $table->date('ended_at')->nullable();

            // The management agreement, folded in rather than given its own table: it is 1:1 with
            // the ownership in practice (§5.1). Null on both = no agency, the owner simply owns.
            // Split it out the day one ownership needs two.
            //
            // `remittance_frequency` deliberately does NOT ship here. Nothing reads it until the
            // agency phase, and a column an operator can set that no code consults is the inert-
            // configuration bug this codebase has already been bitten by — saved, visible, and
            // wired to nothing. It arrives with the run that uses it.
            $table->decimal('management_fee_pct', 5, 2)->nullable();
            $table->string('fee_basis', 32)->nullable();

            $table->unsignedSmallInteger('payment_terms_days')->default(7);
            $table->string('currency', 3)->default('EGP');
            $table->text('notes')->nullable();

            $table->string('search_text')->nullable()->index();
            $table->timestamps();
            $table->softDeletes();

            // The billing sweep's access path: every live ownership in one property.
            $table->index(['asset_id', 'status']);
            // The unit's ownership history, and the "who owns this today" probe.
            $table->index(['unit_id', 'started_at', 'ended_at']);
            $table->index('tenant_id');
        });

        // The two tables that can point BACK at an ownership. Added now, nullable and unwritten,
        // for one reason that is not speculative: `DeletionPolicy` classifies UnitOwnership as
        // WHEN_UNUSED blocked by exactly these, and `RefusesDeletionWhenReferenced` QUERIES them at
        // delete time. Declaring the relations without the columns would give a guard that passes
        // its conformance check (which only asserts the relation exists) and throws a SQL error the
        // first time anybody actually deletes something.
        //
        // Making `invoices.lease_id` NULLABLE — so an owner with no lease can be billed at all — is
        // the other half, and it belongs to phase 2 with the code that writes these columns.
        Schema::table('invoices', function (Blueprint $table) {
            $table->foreignId('unit_ownership_id')->nullable()->after('lease_id')->constrained()->restrictOnDelete();
        });

        Schema::table('charges', function (Blueprint $table) {
            $table->foreignId('unit_ownership_id')->nullable()->after('lease_id')->constrained()->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('charges', function (Blueprint $table) {
            $table->dropConstrainedForeignId('unit_ownership_id');
        });

        Schema::table('invoices', function (Blueprint $table) {
            $table->dropConstrainedForeignId('unit_ownership_id');
        });

        Schema::dropIfExists('unit_ownerships');

        Schema::table('tenants', function (Blueprint $table) {
            $table->dropColumn('party_type');
        });
    }
};
