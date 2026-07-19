<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * CAM admin fee (strengthen item #2, slice 1 of the recovery-clause engine) — the routine
 * administrative fee the landlord adds on top of the recovered pool. Real bookable revenue
 * Atriom couldn't charge. Operator decision: 10% on the net (capped-cost) share, 14% VAT, to a
 * dedicated cam_admin_fee_revenue account.
 *
 * NO-OP for existing pools: admin_fee_pct defaults NULL (no fee) so a pool with no fee configured
 * bills byte-identically to today. The fee is a SIBLING of the true-up (its own cam_admin_fee
 * invoice line + charge), never folded into true_up_amount — so it never contaminates the hardened
 * positive-recovery / negative-credit-note paths.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cam_expense_pools', function (Blueprint $table) {
            $table->decimal('admin_fee_pct', 6, 4)->nullable()->after('total_estimated_collected'); // null/0 = no fee
            $table->boolean('admin_fee_on_net')->default(true)->after('admin_fee_pct');              // fee on the (capped) cost share
        });

        Schema::table('cam_allocations', function (Blueprint $table) {
            $table->decimal('admin_fee_amount', 14, 2)->default(0)->after('true_up_amount');      // fee, net of VAT
            $table->decimal('admin_fee_vat_amount', 14, 2)->default(0)->after('admin_fee_amount');
            $table->foreignId('billed_admin_fee_charge_id')->nullable()->after('billed_credit_note_id')
                ->constrained('charges')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('cam_allocations', function (Blueprint $table) {
            $table->dropConstrainedForeignId('billed_admin_fee_charge_id');
            $table->dropColumn(['admin_fee_amount', 'admin_fee_vat_amount']);
        });
        Schema::table('cam_expense_pools', function (Blueprint $table) {
            $table->dropColumn(['admin_fee_pct', 'admin_fee_on_net']);
        });
    }
};
