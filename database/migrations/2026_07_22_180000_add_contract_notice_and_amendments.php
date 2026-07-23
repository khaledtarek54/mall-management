<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Vendor contract lifecycle (module 12b) — two gaps that made `vendor_contracts` a record of the
 * past rather than a thing you manage.
 *
 * 1. RENEWAL NOTICE. `vendors:expire-contracts` flipped a contract to `expired` on its end_date —
 *    which is the wrong trigger, because by then every decision has already been made for you.
 *    The date that matters is the NOTICE DEADLINE (end_date − notice_period_days): miss it and
 *    either the contract auto-renews for another term at the old rate, or you arrive at day one
 *    with no cleaning contractor. `renewal_alert_for` is the stamp, keyed on the end_date it fired
 *    for, so re-signing (a new end_date) re-arms the alert by itself.
 *
 * 2. AMENDMENTS. `value` was a single static number, so the over-commitment flag added earlier
 *    could not tell an APPROVED change order from an uncontrolled over-run — both simply showed
 *    red. Real contracts get varied; the commitment has to be able to move, with an audit trail.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vendor_contracts', function (Blueprint $table) {
            // Days of written notice required before the term ends. Null = none agreed.
            $table->unsignedSmallInteger('notice_period_days')->nullable()->after('end_date');
            // Does silence renew it? Changes the alert from "arrange a replacement" to the far more
            // urgent "serve notice by X or you are committed for another term".
            $table->boolean('auto_renews')->default(false)->after('notice_period_days');
            // Derived (end_date − notice_period_days), maintained in VendorContract::saving().
            // Stored rather than computed in SQL because "date minus a COLUMN of days" has no
            // portable expression across MySQL and SQLite — and as a real column it is indexable
            // and sortable, so the operator can order the list by the date that actually matters.
            $table->date('notice_deadline')->nullable()->after('auto_renews');
            $table->date('renewal_alert_for')->nullable()->after('notice_deadline');

            $table->index('notice_deadline');
        });

        Schema::create('vendor_contract_amendments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('vendor_contract_id')->constrained()->cascadeOnDelete();
            $table->string('reference')->nullable();
            // Signed: a variation may cut scope as well as add to it.
            $table->decimal('value_delta', 15, 2);
            $table->date('effective_on');
            $table->text('reason');
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index('vendor_contract_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vendor_contract_amendments');

        Schema::table('vendor_contracts', function (Blueprint $table) {
            $table->dropIndex(['notice_deadline']);
            $table->dropColumn(['notice_period_days', 'auto_renews', 'notice_deadline', 'renewal_alert_for']);
        });
    }
};
