<?php

use App\Models\Charge;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A recurring charge's TAXABILITY becomes an override too. Null means "ask the catalogue" (EG-01).
 *
 * `2026_08_12_200000` did this for `charges.vat_rate` and stopped one line short. `vat_applicable`
 * was left `boolean default(true)`, NOT NULL, and still written at row-creation time from
 * `Vat::rateForType($type) > 0` by thirteen services — so the freeze the earlier migration removed
 * from the RATE stayed in place on the QUESTION ABOVE IT.
 *
 * That is not a cosmetic leftover, because {@see Charge::resolvedVatRate()} tests this
 * column FIRST and returns 0.0 before the catalogue is ever consulted. Three consequences, all
 * measured before this migration was written:
 *
 *   1. A `base_rent` row is born `false` — rent is in `Vat::EXEMPT_TYPES` — and can never become
 *      taxable again, whatever the accountant later rules. With the charge code pointed at `VAT_14`,
 *      `Vat::rateForType('base_rent')` answered **14.0** and the charge still resolved **0.0**.
 *   2. The billing run therefore raised a rent line with **0.00 VAT** on an invoice that should have
 *      carried 14,000 — under-collecting output VAT the operator owes ETA.
 *   3. It also defeated the operator's OWN override: a row with a deliberately typed `vat_rate` of
 *      8 still resolved 0.0, because the short-circuit runs before the rate is read.
 *
 * This matters now rather than hypothetically. Law 157/2025 pulled property rental into the tax net
 * (EGYPT-MARKET-FIT §3.1), so *"point base rent at VAT_14"* is the exact change this operator is
 * expecting to make — and it would have appeared to work while every lease already on the books
 * went on billing rent untaxed.
 *
 * ## Why every row backfills to NULL
 *
 * **No screen has ever offered this as a tick.** All three UI/import write sites DERIVE it — from
 * the catalogue, or from a rate the operator typed. So there is no operator statement in this column
 * to preserve: it holds the catalogue's answer copied onto a row, which is the freeze itself.
 *
 * And nothing is lost, because the operator's real channel survives: a deliberately untaxed charge
 * is one with `vat_rate = 0`, which the resolver still honours ahead of the catalogue. What used to
 * need two columns saying the same thing now needs one, and the one that is left is the one a form
 * actually writes.
 *
 * Nothing moves on deploy: rent is exempt in the shipped catalogue, so a nulled row resolves 0.0
 * exactly as the frozen `false` did. What changes is that the day the ruling changes, the bill does.
 *
 * `false` stays MEANINGFUL rather than being dropped — an explicit per-charge exemption is a real
 * thing to want, and the resolver still honours it. It simply stops being written by a service that
 * was only ever quoting the catalogue back to itself.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('charges', function (Blueprint $table) {
            $table->boolean('vat_applicable')->nullable()->default(null)->change();
        });

        // Every row, for the reason in the docblock: all of them were derived, none was declared.
        // Not `Vat::rateForType()` here — a migration that reads the catalogue breaks the day the
        // catalogue changes shape, and the answer would be the same for every row anyway.
        DB::table('charges')->update(['vat_applicable' => null]);
    }

    public function down(): void
    {
        // `true` rather than the derived value: this cannot restore which rows the catalogue called
        // exempt when each was written, and re-deriving now would invent history from today's
        // rulings. `true` is the column's old default and is inert — the resolver falls straight
        // through to `vat_rate` and the catalogue, which is what a restored row should do.
        DB::table('charges')->whereNull('vat_applicable')->update(['vat_applicable' => true]);

        Schema::table('charges', function (Blueprint $table) {
            $table->boolean('vat_applicable')->nullable(false)->default(true)->change();
        });
    }
};
