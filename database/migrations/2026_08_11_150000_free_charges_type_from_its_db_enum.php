<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `charges.type` becomes a string, so the charge-code catalogue reaches RECURRING billing
 * (validation sweep §9 L7).
 *
 * `invoice_items.type` was freed in June 2026, which is what lets `charge_codes` — the catalogue an
 * accountant maintains without a deploy — drive invoice lines. This column was never converted, so
 * a code they added could be billed as a **one-off invoice line** but could not be set up as a
 * **recurring lease charge**: the database rejected it. The catalogue's "no deploy needed" promise
 * stopped at recurring billing, which is most of the money.
 *
 * It also stood against the project's own rule — no DB-level enums; string plus app-layer
 * validation, so the set can change without a migration. That validation is not lost with the
 * constraint: `Charge::assertTypeIsAKnownChargeCode()` refuses an unknown code at the model, where
 * every writer meets it, and gives a message naming the catalogue instead of a driver error.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('charges', function (Blueprint $table) {
            $table->string('type', 32)->change();
        });
    }

    public function down(): void
    {
        // Every value up() allowed as a string must appear here, including any code an accountant
        // added while the column was free — otherwise rollback fails under MySQL strict mode or
        // silently loses those rows. Codes beyond this list are exactly what the change enables, so
        // a rollback on a live database is a data question, not a schema one.
        Schema::table('charges', function (Blueprint $table) {
            $table->enum('type', [
                'base_rent', 'service_charge', 'utility', 'parking',
                'percentage_rent', 'marketing', 'other',
            ])->change();
        });
    }
};
