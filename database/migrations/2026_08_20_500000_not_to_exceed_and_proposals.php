<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Not-to-exceed and the proposal loop — close-out step 6, the BEFORE-the-money control.
 *
 * ## The standard
 *
 * ServiceChannel §3. Every work order carries an **NTE** — the most a contractor may spend without
 * coming back. At or under it they proceed and invoice; expecting to exceed it, they must submit a
 * **proposal first**, and approval raises the NTE.
 *
 * Scenario S4: a leak reported, the contractor decides the riser must be replaced, does it, and
 * invoices EGP 46,000 against an expected EGP 4,000 repair. With an NTE the work stops at 5,000 and
 * the operator approves 38,000 or refuses — **before the riser comes out**.
 *
 * ## Atriom already had the AFTER control, and it is arguably stronger
 *
 * `PurchaseRequest::billingVariance()` is a real three-way match. What did not exist was anything
 * BEFORE the money: the operator saw the number when the invoice arrived, which is not a
 * negotiation. This is the other half, not a replacement.
 *
 * ## Over-NTE is SHOWN, never blocked — and that is this codebase's own settled reasoning
 *
 * The three-way match deliberately does not block, because a bill legitimately covers freight and
 * labour beyond the goods, so the variance is a number to show. The same holds here: a job can
 * legitimately grow for a reason nobody could have proposed for. **The control is that a contractor
 * should have proposed before exceeding** — the enforcement is that the breach is visible and
 * attributable, not that accounts payable is jammed. Stated as a deviation from ServiceChannel,
 * which does hold the invoice.
 *
 * ## A proposal IS the estimate
 *
 * Its three buckets are the cost object's three buckets, deliberately: approving a proposal writes
 * `est_labour_cost` / `est_material_cost` / `est_service_cost` onto the job, so step 2's
 * planned-vs-actual variance becomes *"did the contractor deliver what they quoted?"* — which is the
 * question the whole loop exists to answer. One vocabulary, not two.
 *
 * ## The vendor does not submit it themselves — yet
 *
 * ServiceChannel's provider logs in and submits. That portal is gap **O2** and remains open, so a
 * proposal is recorded BY THE OPERATOR on the contractor's behalf, exactly as a vendor bill is
 * today. The loop is real; its self-service half is not built, and this does not pretend otherwise.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('trades', 'default_nte')) {
            Schema::table('trades', function (Blueprint $table) {
                // The trade's default ceiling. NULL means "no default" — a job in that trade starts
                // with no NTE, which is honest, where 0 would mean "may spend nothing".
                $table->decimal('default_nte', 14, 2)->nullable()->after('standard_hourly_rate');
            });
        }

        if (! Schema::hasColumn('facility_work_orders', 'nte_amount')) {
            Schema::table('facility_work_orders', function (Blueprint $table) {
                $table->decimal('nte_amount', 14, 2)->nullable()->after('est_total_cost');
            });
        }

        if (! Schema::hasTable('work_order_proposals')) {
            Schema::create('work_order_proposals', function (Blueprint $table) {
                $table->id();
                $table->foreignId('facility_work_order_id')->constrained()->cascadeOnDelete();
                $table->foreignId('vendor_id')->nullable()->constrained()->nullOnDelete();

                $table->string('status', 16)->default('submitted');

                // The cost object's own three buckets — see the class docblock. NET of tax, like
                // every other cost figure in the module: VAT is not what the work costs.
                $table->decimal('labour_amount', 14, 2)->default(0);
                $table->decimal('material_amount', 14, 2)->default(0);
                $table->decimal('service_amount', 14, 2)->default(0);
                $table->decimal('total_amount', 14, 2)->default(0);

                $table->text('scope')->nullable();
                $table->text('decision_reason')->nullable();

                $table->foreignId('submitted_by_user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->dateTime('submitted_at')->nullable();
                $table->foreignId('decided_by_user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->dateTime('decided_at')->nullable();

                $table->timestamps();
                $table->softDeletes();

                $table->index(['facility_work_order_id', 'status'], 'wo_proposals_order_status_index');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('work_order_proposals');

        Schema::table('facility_work_orders', function (Blueprint $table) {
            $table->dropColumn('nte_amount');
        });

        Schema::table('trades', function (Blueprint $table) {
            $table->dropColumn('default_nte');
        });
    }
};
