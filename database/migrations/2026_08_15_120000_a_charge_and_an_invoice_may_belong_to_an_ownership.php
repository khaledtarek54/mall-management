<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * An owner can be billed: `lease_id` becomes optional on both `invoices` and `charges`.
 *
 * A unit OWNER has no lease. He owes the service charge from handover — whether he trades from the
 * unit, lets it himself, hands it to the operator to let, or leaves it empty — and until now there
 * was no shape in the schema that could carry that debt. `unit_ownership_id` shipped in phase 1
 * (nullable, unwritten, so the deletion guard could run a real query); this is the half that lets it
 * be written.
 *
 * **Exactly one of the two is set**, and that invariant lives on the models rather than in a CHECK:
 * SQLite drops CHECK constraints on any later `->change()` to the table, so a constraint written here
 * would quietly disappear the next time anybody widens a column — which is precisely the class of
 * silent failure this codebase keeps finding. `Charge::saving` and `Invoice::saving` refuse both-or-
 * neither, and `ChargeAgreementConformanceTest` proves the refusal fires rather than asserting a
 * guard exists.
 *
 * ## The `->change()` on `invoices` is the risk in this migration
 *
 * `invoices.status` and `invoices.eta_status` are CHECK-constrained on SQLite (they were freed from
 * DB enums in `2026_08_12_270000`). A `->change()` there rebuilds the table from its introspected
 * schema and can drop those. `NoDatabaseEnumsConformanceTest` reads BOTH driver shapes and proves
 * the value guard still refuses — so if this migration silently un-guards them, the build goes red
 * rather than shipping. That gate is the reason this change is safe to make at all.
 *
 * @see docs/modules/37-unit-owners.md, §5.3
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->foreignId('lease_id')->nullable()->change();
        });

        Schema::table('charges', function (Blueprint $table) {
            $table->foreignId('lease_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        // Only reversible while no ownership has been billed — a row with a null lease_id cannot be
        // made NOT NULL again, and inventing a lease for it would be worse than refusing.
        $orphaned = DB::table('invoices')->whereNull('lease_id')->count()
            + DB::table('charges')->whereNull('lease_id')->count();

        if ($orphaned > 0) {
            throw new RuntimeException(
                "Cannot reverse: {$orphaned} row(s) belong to a unit ownership rather than a lease. "
                .'Removing the ownership billing means deciding what happens to those documents first.'
            );
        }

        Schema::table('charges', function (Blueprint $table) {
            $table->foreignId('lease_id')->nullable(false)->change();
        });

        Schema::table('invoices', function (Blueprint $table) {
            $table->foreignId('lease_id')->nullable(false)->change();
        });
    }
};
