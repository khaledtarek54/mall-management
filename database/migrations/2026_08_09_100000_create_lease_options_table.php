<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `lease_options` — the optionality inside a lease, and the windows in which it must be exercised.
 *
 * A commercial lease is a bundle of options, and options are money. A renewal option at a
 * contracted uplift, unexercised because nobody was told the notice window had opened, turns a
 * five-year known-rent tenancy into a holdover at whatever the parties can agree — or a vacancy
 * plus fit-out plus a leasing commission. Yardi treats these as first-class records with
 * critical-date alerting and space encumbrance
 * (docs/benchmarks/yardi/01-yardi-lease-administration.md §6); Atriom had nothing at all.
 *
 * **What made this urgent:** `leases:remind-expiring` fires 90 days before EXPIRY, which for a
 * typical clause ("notice no earlier than 12 and no later than 9 months before expiry") is three
 * to six months AFTER the window has already closed. The system's only lease-date alert spoke
 * exclusively after it was too late to act.
 *
 * Dates are what this table is for, so both ends of the window are stored: an option you may not
 * exercise *yet* is as actionable as one about to lapse.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lease_options', function (Blueprint $table) {
            $table->id();
            $table->foreignId('lease_id')->constrained()->cascadeOnDelete();

            // Strings, not DB enums (project convention): a new option type or rent basis must not
            // need a migration. Validation lives in the model + form.
            $table->string('type', 32);           // renewal | termination | expansion | contraction | rofr | rofo | purchase
            $table->string('status', 16)->default('open'); // open | exercised | lapsed | waived

            // The window. `latest_notice_date` is the one that bites — miss it and the option is
            // gone — but the earliest matters too: serving notice early is usually invalid.
            $table->date('earliest_notice_date')->nullable();
            $table->date('latest_notice_date')->nullable();

            // What exercising it produces.
            $table->unsignedSmallInteger('term_months')->nullable();     // renewal/extension length
            $table->string('rent_basis', 24)->nullable();                // fixed | uplift_percent | market | cpi
            $table->decimal('uplift_percent', 5, 2)->nullable();
            $table->decimal('fixed_rent', 12, 2)->nullable();
            $table->decimal('penalty_amount', 12, 2)->nullable();        // termination penalty

            // The space an expansion/ROFR option encumbers, so it can be flagged before it is
            // promised to somebody else.
            $table->foreignId('unit_id')->nullable()->constrained()->nullOnDelete();

            $table->date('notice_given_at')->nullable();
            $table->date('resolved_at')->nullable();
            $table->text('notes')->nullable();

            // Idempotency stamps for the daily scan — the same shape as every other scheduled scan
            // in this system (stamp, then re-check inside the transaction).
            $table->timestamp('opening_notified_at')->nullable();
            $table->timestamp('closing_notified_at')->nullable();
            $table->timestamp('lapsed_notified_at')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['lease_id', 'status']);
            $table->index(['status', 'latest_notice_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lease_options');
    }
};
