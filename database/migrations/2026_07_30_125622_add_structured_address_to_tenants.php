<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Structured buyer address for ETA e-invoicing (module 16).
 *
 * ETA's receiver node wants the address in PARTS — governorate, city, street,
 * building number — because the tax authority validates and indexes them. The
 * tenants table only ever had a freeform `address` textarea, so EtaJsonBuilder
 * filled the parts with constants: every submitted document declared the buyer to
 * be in **Giza / 6th of October City at building 1**, whoever they actually were,
 * with the entire freeform address stuffed into `street`.
 *
 * That is wrong on every invoice for a mall outside 6th of October, and wrong on
 * the building number for all of them. It went unnoticed because ETA is still in
 * mock mode — the fake endpoint accepts anything — so nothing has ever been filed
 * against these values.
 *
 * The freeform `address` is KEPT: it is what the invoice PDF, the portal and the
 * tenant directory print, it is populated for every existing tenant, and it holds
 * detail (floor, landmark) that no fixed set of columns should try to absorb.
 * These columns are additive, and exist for the tax filing.
 *
 * Nullable, with no backfill. Splitting a freeform Arabic/English address into
 * governorate + city + street by guesswork would put invented data on a legal tax
 * document — the one thing worse than the constants it replaces. Instead the
 * builder REFUSES to submit a business invoice until the fields are filled, the
 * same way it already refuses one with no tax_id, so the gap surfaces as a named
 * tenant to fix rather than as a silently wrong filing.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            // Strings, not enums — the project rule (a governorate list that changes
            // must not need a migration). Validated at the form layer.
            $table->string('address_governorate')->nullable()->after('address');
            $table->string('address_city')->nullable()->after('address_governorate');
            $table->string('address_street')->nullable()->after('address_city');
            $table->string('address_building_number', 50)->nullable()->after('address_street');
        });
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->dropColumn([
                'address_governorate',
                'address_city',
                'address_street',
                'address_building_number',
            ]);
        });
    }
};
