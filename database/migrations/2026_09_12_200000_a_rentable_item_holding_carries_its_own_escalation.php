<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A parking bay, a storage cage, a signage face steps on the lease anniversary by ITS OWN rule.
 *
 * Meeting 2026-09-02, point 24 made every charge row carry its annual increase — and left the
 * `parking` row out on purpose, because that row is DERIVED: `AssignRentableItemService` re-sums
 * it from the bays a lease holds on every assignment and release, so a rule written on the row was
 * undone by the next bay. The rule belongs where the rate lives, and the rate lives on the HOLDING
 * — `rentable_item_holdings.monthly_rate` is what this tenant pays for this bay (the register's own
 * `monthly_rate` is the asking price). Voyager's shape exactly: a rentable item is billed as a
 * recurring lease charge on its own code (benchmark 09 §2), and the escalation schedule sits on
 * that charge (benchmark 01 §4). One bay at +500 a year beside another at +5 % beside a third that
 * follows the rent is an ordinary Egyptian mall contract.
 *
 * Same three columns, same vocabulary as `charges` (`ChargeEscalation::MODES`), so the reading is
 * one class and not two. Null is what every existing holding gets and means what it meant: a bay
 * nobody ruled on steps nothing. Nothing an install bills moves on deploy.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rentable_item_holdings', function (Blueprint $table) {
            $table->string('escalation_mode', 32)->nullable()->after('monthly_rate');
            $table->decimal('escalation_rate', 5, 2)->nullable()->after('escalation_mode');
            $table->decimal('escalation_amount', 12, 2)->nullable()->after('escalation_rate');
        });
    }

    public function down(): void
    {
        Schema::table('rentable_item_holdings', function (Blueprint $table) {
            $table->dropColumn(['escalation_mode', 'escalation_rate', 'escalation_amount']);
        });
    }
};
