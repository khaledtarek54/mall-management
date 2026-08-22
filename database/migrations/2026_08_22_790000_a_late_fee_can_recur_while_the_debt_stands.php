<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A late fee can be charged again while the debt stands (EG-35, the other half of finding M-8).
 *
 * One fee per invoice, ever: a tenant six months late paid the same penalty as one six days late,
 * and a clause reading *"2% per month while the balance remains outstanding"* could not be
 * expressed. The cap shipped on 2026-08-22; this is its opposite number, and it was deferred then
 * because it needed a schema change on a money link rather than a settings field.
 *
 * ## Two columns, two different questions
 *
 * `invoices.late_fee_for_invoice_id` is the FEE's pointer back at the invoice it penalises — the
 * audit trail, and the thing that makes *"which fees came from this invoice"* answerable. Until now
 * the only record of that was a sentence inside the fee's line description.
 *
 * `invoices.late_fee_invoice_id` stays exactly as it was, on the source, naming the MOST RECENT fee.
 * It is the idempotency stamp and it is what `ChangeImpact` and the existing readers key on. Two
 * links look like two truths and are not: one answers "what did this invoice produce, most
 * recently", the other "what produced this fee". The decision itself reads the back-pointer, so the
 * rule has one home.
 *
 * ## Recurrence is a clause term, on the same three tiers as the rest
 *
 * `leases.late_fee_recurrence_days` → property → portfolio, and **0 means charge once**, which is
 * what every install has done since late fees existed. So nothing changes on deploy, and an
 * operator opts a lease in when its clause says so.
 *
 * Charging repeatedly is not something to switch on for a portfolio by accident — Egyptian practice
 * and the usury rules around compounding are the accountant's ground, not this system's — which is
 * exactly why the default is off and the term is negotiable per lease.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->foreignId('late_fee_for_invoice_id')
                ->nullable()
                ->after('late_fee_invoice_id')
                ->constrained('invoices')
                ->nullOnDelete();
        });

        Schema::table('leases', function (Blueprint $table) {
            // Nullable like its three siblings: null = no clause of its own, ask the property then
            // the portfolio.
            $table->unsignedSmallInteger('late_fee_recurrence_days')->nullable()->after('late_fee_maximum');
        });

        // Backfill the back-pointer from the links that already exist, so the history a fee invoice
        // carries starts complete rather than from today.
        DB::table('invoices')
            ->whereNotNull('late_fee_invoice_id')
            ->select('id', 'late_fee_invoice_id')
            ->orderBy('id')
            ->chunk(500, function ($sources) {
                foreach ($sources as $source) {
                    DB::table('invoices')
                        ->where('id', $source->late_fee_invoice_id)
                        ->update(['late_fee_for_invoice_id' => $source->id]);
                }
            });
    }

    public function down(): void
    {
        Schema::table('leases', function (Blueprint $table) {
            $table->dropColumn('late_fee_recurrence_days');
        });

        Schema::table('invoices', function (Blueprint $table) {
            $table->dropConstrainedForeignId('late_fee_for_invoice_id');
        });
    }
};
