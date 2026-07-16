<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SLA penalties against an external maintenance company (FR-CM-08) — module 26.
 *
 * The FRD says only "automatically flag and calculate a penalty when a CM request exceeds
 * its configured SLA duration". It never says on what BASIS, and the three readings behave
 * differently enough that guessing one would be a rewrite if wrong — so the basis is
 * configuration on the vendor's contract, not a decision baked into this code.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vendor_contracts', function (Blueprint $table) {
            // none = this contract carries no SLA penalty (the default: penalties are opt-in
            // per contract, because most contracts won't have one negotiated).
            $table->enum('sla_penalty_basis', ['none', 'flat', 'per_day', 'percent_of_value'])
                ->default('none')->after('value');

            // Meaning depends on the basis: flat = EGP once; per_day = EGP per day late;
            // percent_of_value = % of the job's value.
            $table->decimal('sla_penalty_rate', 14, 2)->default(0)->after('sla_penalty_basis');
        });

        Schema::table('maintenance_work_orders', function (Blueprint $table) {
            // What the job is worth — the vendor's quote. Only needed for the
            // percent_of_value basis, hence nullable: most jobs never carry one.
            $table->decimal('job_value', 14, 2)->nullable()->after('description');
        });

        Schema::create('maintenance_penalties', function (Blueprint $table) {
            $table->id();

            // ONE penalty per work order. This unique index is what makes an accruing
            // penalty safe: the hourly scan re-computes and updates the same row rather
            // than inserting a new one, so `sla_breach_notified_at` (a once-per-record
            // stamp, fine for a one-shot alert) never has to serve as the accrual key.
            $table->foreignId('maintenance_work_order_id')->unique()
                ->constrained('maintenance_work_orders')->cascadeOnDelete();

            $table->foreignId('asset_id')->constrained('assets')->cascadeOnDelete();
            $table->foreignId('vendor_id')->constrained('vendors')->restrictOnDelete();
            // The contract the terms came from. nullOnDelete + the terms copied below, so
            // the penalty still explains itself if the contract is later removed or renegotiated.
            $table->foreignId('vendor_contract_id')->nullable()->constrained('vendor_contracts')->nullOnDelete();

            // The terms AS APPLIED, frozen onto the row. Re-deriving from the contract at
            // read time would silently restate history when someone renegotiates the rate.
            $table->enum('basis', ['flat', 'per_day', 'percent_of_value']);
            $table->decimal('rate', 14, 2);
            $table->unsignedInteger('hours_over_sla');
            $table->decimal('amount', 14, 2);
            $table->string('currency', 3)->default('EGP');

            // pending  = accruing / not yet charged   (recomputed by the scan)
            // final    = the job closed; the amount is frozen
            // waived   = an operator decided not to charge it (with a reason)
            $table->enum('status', ['pending', 'final', 'waived'])->default('pending');
            $table->dateTime('finalised_at')->nullable();
            $table->dateTime('waived_at')->nullable();
            $table->foreignId('waived_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('waive_reason')->nullable();

            $table->timestamps();

            $table->index(['asset_id', 'status'], 'maintenance_penalties_asset_status_index');
            $table->index('vendor_id', 'maintenance_penalties_vendor_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('maintenance_penalties');

        Schema::table('maintenance_work_orders', function (Blueprint $table) {
            $table->dropColumn('job_value');
        });

        Schema::table('vendor_contracts', function (Blueprint $table) {
            $table->dropColumn(['sla_penalty_basis', 'sla_penalty_rate']);
        });
    }
};
