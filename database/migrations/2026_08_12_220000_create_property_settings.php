<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A property may answer some questions differently from the portfolio.
 *
 * Eltizam runs several malls, and every configured number was portfolio-wide: one late-fee rate,
 * one grace period, one set of payment terms across every property. That is not how the leases
 * read — a mall's economics are negotiated per building — and it is not how the systems this is
 * benchmarked against work: Yardi configures late fees, billing day and SLA hours per property.
 *
 * ## Three tiers, and the middle one is what this adds
 *
 *   1. **The LEASE** — `leases.late_fee_percent` and friends. A negotiated term always wins.
 *   2. **The PROPERTY** — this table.
 *   3. **The PORTFOLIO** — the settings screen, which is the default and always answerable.
 *
 * The first and third already existed and the fallback chain was already written that way; this
 * slots into the gap. A row here is an OVERRIDE, so its absence is the normal state and means
 * "whatever the portfolio says" — never "zero".
 *
 * ## Only some settings may be overridden, and the list is explicit
 *
 * `App\Support\PropertySettings::OVERRIDABLE` names them with a reason each, and a conformance test
 * refuses one that does not exist on its settings class. Most settings must NOT be per-property and
 * saying so is the point: the seller's tax registration number is company identity, VAT rates are
 * national, payroll rates are statutory, and a module is switched on for the system or not at all.
 * An override on any of those would be a way to make one mall file a different return.
 *
 * Storage mirrors spatie's `settings` table — group + name + JSON payload — so a value read from
 * either tier deserialises the same way and the resolver has nothing to translate.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('property_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('asset_id')->constrained()->cascadeOnDelete();

            $table->string('group');
            $table->string('name');
            $table->json('payload');

            $table->timestamps();

            // One answer per property per setting. Two rows would make "the override" depend on
            // which the query happened to return first.
            $table->unique(['asset_id', 'group', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('property_settings');
    }
};
